<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSubscriberModel;

/**
 * Lectura pública del periódico (sin auth): solo contenido `published`, resuelto por
 * slug (URL SEO). Un slug antiguo responde `{redirect_to}` para que el frontend emita
 * el 301. El kill-switch por paquete (CoreEnsureActiveMiddleware) sigue aplicando.
 */
#[Group('Public')]
class SnkKilkanaPublicController extends Controller
{
    /**
     * Firma en la salida pública: nombre y slug, que es lo único que pinta la tarjeta.
     *
     * El modelo trae `author` entera por `$with` y esto es un endpoint anónimo: sin
     * recortar, cada post arrastra bio, web y redes repetidas en los 50 posts. La ficha
     * completa la sirve `author()`, que es quien la necesita.
     *
     * `user_id` y `alias` no se pintan: hacen falta para que el modelo derive `name` (el
     * alias si firma con seudónimo, si no el de su cuenta). La cuenta va oculta del JSON.
     */
    private const AUTHOR_PUBLIC = 'author:id,user_id,alias,slug';

    public function posts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cat' => 'nullable|string|max:32',
            'author' => 'nullable|string|max:255',
            'q' => 'nullable|string|min:2|max:128',
            'count' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SnkKilkanaPostModel::query()
            ->with(self::AUTHOR_PUBLIC)
            ->published()
            // Vistas reales agregadas (kilkana_post_views): el «más leído» ordena por ellas.
            // Tres ventanas —hoy, siete días y total— porque son tres preguntas distintas y
            // ninguna se puede derivar de las otras dos.
            ->withSum(['views as views_day' => fn ($q) => $q->where('date', now()->toDateString())], 'views')
            ->withSum(['views as views_week' => fn ($q) => $q->where('date', '>=', now()->subDays(6)->toDateString())], 'views')
            ->withSum('views as views_total', 'views')
            ->orderByDesc('publish_at');
        // Una sección incluye a sus DESCENDIENTES: quien pulsa «Deportes» espera el
        // fútbol. Dos niveles, que es lo que admite la taxonomía (una sección con hijas
        // no puede tener madre), así que basta mirar la categoría del post y su madre.
        if (! empty($data['cat'])) {
            $query->whereHas('category', fn ($q) => $q
                ->where('slug', $data['cat'])
                ->orWhereHas('parent', fn ($p) => $p->where('slug', $data['cat'])));
        }
        // Firma: por SLUG, que es lo que lleva la URL pública /author/{slug}.
        if (! empty($data['author'])) {
            $query->whereHas('author', fn ($q) => $q->where('slug', $data['author']));
        }
        // Buscador del sitio: FULLTEXT sobre título y entradilla.
        if (! empty($data['q'])) {
            $query->whereFullText(['title', 'excerpt'], $data['q']);
        }

        return response()->json($query->limit($data['count'] ?? 50)->get());
    }

    public function categories(): JsonResponse
    {
        return response()->json(SnkKilkanaCategoryModel::query()->orderBy('position')->orderBy('id')->get());
    }

    /**
     * Perfil público de una firma, por slug. La ficha ya viaja anidada en cada post, pero
     * la PÁGINA de autor la necesita suelta: existe aunque la firma no tenga publicados.
     *
     * Se sirve recortada a lo que se pinta —nada de `user_id` ni timestamps— y con el
     * avatar ya resuelto a URL: `avatar_media_id` es un id interno que el lector no puede
     * resolver.
     */
    public function author(string $slug): JsonResponse
    {
        $author = SnkKilkanaAuthorModel::query()
            ->with('avatarMedia:id,url')
            ->where('slug', $slug)
            ->first();
        if ($author === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $author->id,
            'name' => $author->name,
            // El cargo es señal E-E-A-T: `jobTitle` del Person y el eyebrow de la ficha.
            'job_title' => $author->job_title,
            'slug' => $author->slug,
            'bio' => $author->bio,
            'website' => $author->website,
            'socials' => $author->socials,
            'avatar' => $author->avatarMedia?->url,
        ]);
    }

    /**
     * Consulta de un artículo por su ruta pública: fecha + slug, que juntos son la clave.
     * Un slug suelto ya no identifica una pieza —el mismo titular puede volver otro año—.
     */
    private function publishedAt(string $year, string $month, string $day, string $slug)
    {
        return SnkKilkanaPostModel::query()
            ->where('url_date', sprintf('%04d-%02d-%02d', $year, $month, $day))
            ->where('slug', $slug)
            ->published();
    }

    public function post(string $year, string $month, string $day, string $slug): JsonResponse
    {
        // `ogImage` solo aquí: es el único sitio que monta una cabecera de artículo. En el
        // listado sobraría —cincuenta consultas por un dato que la tarjeta no pinta—.
        $post = $this->publishedAt($year, $month, $day, $slug)
            ->with([self::AUTHOR_PUBLIC, 'ogImage:id,url'])
            ->first();
        if ($post) {
            SnkKilkanaPostViewModel::hit($post->id); // lectura de hoy (más leído real)

            return response()->json($post);
        }

        // Ruta antigua → apunta a la actual (el frontend emite el 301).
        $history = SnkKilkanaPostSlugModel::query()
            ->where('path', "{$year}/{$month}/{$day}/{$slug}")
            ->first();
        if ($history) {
            $current = SnkKilkanaPostModel::query()
                ->whereKey($history->post_id)
                ->published()
                ->first();
            if ($current?->url_path) {
                return response()->json(['redirect_to' => $current->url_path]);
            }
        }

        // Fecha que no casa. Los segmentos MANDAN: sin esto el mismo artículo respondería
        // bajo cualquier fecha inventada y viviría en infinitas URLs — contenido duplicado
        // servido por nosotros. Solo se redirige si el slug identifica una pieza sin
        // ambigüedad; con dos años compartiendo titular no hay a cuál mandar.
        $porSlug = SnkKilkanaPostModel::query()
            ->where('slug', $slug)
            ->published()
            ->get();
        if ($porSlug->count() === 1 && $porSlug->first()->url_path) {
            return response()->json(['redirect_to' => $porSlug->first()->url_path]);
        }

        return response()->json(['error' => 'Not found'], 404);
    }

    /** Comentarios aprobados de un artículo publicado (planos; parent_id arma el hilo). */
    public function comments(string $year, string $month, string $day, string $slug): JsonResponse
    {
        $postId = $this->publishedAt($year, $month, $day, $slug)->value('id');
        if ($postId === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $comments = SnkKilkanaCommentModel::query()
            ->where('post_id', $postId)
            ->where('status', 'approved')
            ->with('user:id,name') // solo el nombre: el email del lector no sale al público
            ->orderBy('created_at')
            ->get(['id', 'post_id', 'user_id', 'parent_id', 'author_name', 'body', 'created_at']);

        return response()->json($comments);
    }

    /**
     * Alta pública de un comentario. Entra SIEMPRE como `pending`: lo que se publica lo
     * decide la moderación en /admin/comments, no quien escribe.
     *
     * Existe porque el formulario de la web llamaba al recurso protegido del panel: para
     * comentar en el periódico hacía falta una cuenta del CMS, y si el token caducaba el
     * lector acababa en /admin/login con su texto perdido.
     */
    public function storeComment(Request $request, string $year, string $month, string $day, string $slug): JsonResponse
    {
        $postId = $this->publishedAt($year, $month, $day, $slug)->value('id');
        if ($postId === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $data = $request->validate([
            'author_name' => 'required|string|min:2|max:80',
            'author_email' => 'required|email|max:190',
            'body' => 'required|string|min:2|max:4000',
            'parent_id' => 'nullable|integer',
        ]);

        // Responder solo a un comentario APROBADO del MISMO artículo: si no, un parent_id
        // a voleo cuelga la respuesta de otra pieza o de algo que nadie ha aprobado.
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parentId = SnkKilkanaCommentModel::query()
                ->where('id', $data['parent_id'])
                ->where('post_id', $postId)
                ->where('status', 'approved')
                ->value('id');
            if ($parentId === null) {
                return response()->json(['error' => 'Invalid parent'], 422);
            }
        }

        SnkKilkanaCommentModel::create([
            'post_id' => $postId,
            'user_id' => null,
            'parent_id' => $parentId,
            'author_name' => $data['author_name'],
            'author_email' => strtolower($data['author_email']),
            'body' => $data['body'],
            'status' => 'pending',
        ]);

        // 202 y nada del comentario: no está publicado todavía y devolverlo invitaría a
        // pintarlo en el hilo como si lo estuviera.
        return response()->json(['ok' => true, 'status' => 'pending'], 202);
    }

    public function layout(string $layout): JsonResponse
    {
        $row = SnkKilkanaLayoutModel::query()->where('layout', $layout)->first();

        return response()->json([
            'layout' => $layout,
            'schema' => $row?->schema ?? ['version' => 1, 'sections' => []],
        ]);
    }

    /** Alta pública al boletín: idempotente por email; el estado nace pending. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        SnkKilkanaSubscriberModel::firstOrCreate(['email' => strtolower($data['email'])]);

        // Respuesta uniforme exista o no: no revela si un email está suscrito.
        return response()->json(['ok' => true], 202);
    }

    /** Identidad + tema, lo mínimo para renderizar la salida pública. */
    public function site(): JsonResponse
    {
        $settings = SnkKilkanaSettingModel::query()->find(1)?->settings ?? [];

        return response()->json([
            'tenant' => $settings['tenant'] ?? null,
            'theme' => $settings['theme'] ?? null,
            'theme_overrides' => $settings['theme_overrides'] ?? [],
        ]);
    }
}
