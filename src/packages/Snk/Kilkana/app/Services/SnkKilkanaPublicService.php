<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategorySlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSubscriberModel;

class SnkKilkanaPublicService
{
    private const AUTHOR_PUBLIC = 'author:id,user_id,alias,slug';

    public function posts(array $filters)
    {
        $query = SnkKilkanaPostModel::query()
            ->with(self::AUTHOR_PUBLIC)
            ->published()
            // Se suma `engaged`, no `opens`: «lo más leído» tiene que ordenarse por lo que la
            // gente lee, no por lo que consigue que pulsen.
            ->withSum(['views as views_day' => fn ($q) => $q->where('date', now()->toDateString())], 'engaged')
            ->withSum(['views as views_week' => fn ($q) => $q->where('date', '>=', now()->subDays(6)->toDateString())], 'engaged')
            ->withSum('views as views_total', 'engaged')
            ->orderByDesc('publish_at');

        if (! empty($filters['cat'])) {
            $query->whereHas('category', fn ($q) => $q
                ->where('slug', $filters['cat'])
                ->orWhereHas('parent', fn ($parent) => $parent->where('slug', $filters['cat'])));
        }
        if (! empty($filters['author'])) {
            $query->whereHas('author', fn ($q) => $q->where('slug', $filters['author']));
        }
        if (! empty($filters['q'])) {
            $query->whereFullText(['title', 'excerpt'], $filters['q']);
        }

        return $query->limit($filters['count'] ?? 50)->get();
    }

    /**
     * Registra que alguien abrió o leyó una pieza. Falso si esa URL no es de nadie publicado.
     *
     * Dos hechos, dos llamadas: `open` al abrir y `read` al pasar el umbral de lectura. El
     * cociente entre ambos es lo que distingue un titular que funciona de uno que engaña, y
     * con un solo contador no se puede calcular.
     *
     * La procedencia se clasifica **del lado del servidor**, leyendo la cabecera `Referer`:
     * si la mandara el cliente sería un número que cualquiera puede escribir.
     *
     * `readers` solo sube la primera lectura de ese visitante ese día. El visitante es un
     * hash con sal y con la FECHA dentro, así que el de hoy y el de mañana no se pueden
     * emparejar: sirve para no contar dos veces y para nada más. No se almacena —vive en
     * caché hasta medianoche—, así que esto no rastrea a nadie ni necesita consentimiento.
     */
    public function recordView(string $year, string $month, string $day, string $slug, string $event, Request $request): bool
    {
        $postId = $this->publishedAt($year, $month, $day, $slug)->value('id');
        if (! $postId) {
            return false;
        }

        if ($event === 'open') {
            SnkKilkanaPostViewModel::record($postId, 'opens', $this->referrerCounter($request));

            return true;
        }

        $visitante = sha1($request->ip().$request->userAgent().config('app.key').now()->toDateString());
        $primera = Cache::add("kilkana:reader:{$postId}:{$visitante}", true, now()->endOfDay());

        SnkKilkanaPostViewModel::record($postId, 'engaged', ...($primera ? ['readers'] : []));

        return true;
    }

    /** De dónde llegó, en los cinco cubos que usa cualquier analítica. */
    private function referrerCounter(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        if ($referer === '') {
            return 'ref_direct';
        }

        $host = (string) parse_url($referer, PHP_URL_HOST);
        if ($host === '' || $host === $request->getHost()) {
            return 'ref_internal';
        }
        if (preg_match('/(^|\.)(google|bing|duckduckgo|yahoo|ecosia|qwant|baidu|yandex)\./i', $host)) {
            return 'ref_search';
        }
        if (preg_match('/(^|\.)(facebook|instagram|twitter|x|t)\.co|(^|\.)(linkedin|reddit|telegram|whatsapp|bsky|mastodon|threads)\./i', $host)) {
            return 'ref_social';
        }

        // Otro medio que enlaza. No es directo —directo es SIN procedencia— y confundirlos
        // borraría justo el dato que dice quién te cita.
        return 'ref_other';
    }

    /** Solo las lanzadas: una sección en preparación no existe para el lector. */
    public function categories()
    {
        return SnkKilkanaCategoryModel::query()->live()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Una sección por su slug, o el slug de ahora si la URL pedida es una que dejó atrás.
     *
     * Devuelve cadena para redirigir, igual que `post()` y `author()`. Existe para eso: sin
     * una entrada por slug, el historial de URLs de una sección se guardaba y no había dónde
     * consultarlo, así que renombrar «Technology» rompía sus enlaces igual que antes.
     *
     * La redirección exige que la sección siga lanzada, por el mismo motivo que la de un
     * artículo exige que siga publicado: una mesa retirada no recibe tráfico por detrás.
     */
    public function category(string $slug): SnkKilkanaCategoryModel|string|null
    {
        $category = SnkKilkanaCategoryModel::query()->live()->where('slug', $slug)->first();
        if ($category) {
            return $category;
        }

        $retirada = SnkKilkanaCategorySlugModel::query()->where('path', $slug)->first();
        if (! $retirada) {
            return null;
        }

        return SnkKilkanaCategoryModel::query()
            ->whereKey($retirada->category_id)
            ->live()
            ->value('slug');
    }

    /**
     * La ficha de una firma, o su nuevo slug si la URL pedida es una que dejó atrás.
     *
     * Devuelve cadena para redirigir, igual que `post()`: quien tenga enlazada la ficha con
     * su seudónimo antiguo llega igual.
     */
    public function author(string $slug): array|string|null
    {
        $author = SnkKilkanaAuthorModel::query()
            ->with('avatarMedia:id,url')
            ->where('slug', $slug)
            ->first();

        if (! $author) {
            $retirada = SnkKilkanaAuthorSlugModel::query()->where('path', $slug)->first();

            return $retirada
                ? SnkKilkanaAuthorModel::query()->whereKey($retirada->author_id)->value('slug')
                : null;
        }

        return [
            'id' => $author->id,
            'name' => $author->name,
            'job_title' => $author->job_title,
            'slug' => $author->slug,
            'bio' => $author->bio,
            'seo_title' => $author->seo_title,
            'seo_description' => $author->seo_description,
            'noindex' => (bool) $author->noindex,
            'website' => $author->website,
            'socials' => $author->socials,
            'avatar' => $author->avatarMedia?->url,
        ];
    }

    public function post(string $year, string $month, string $day, string $slug): SnkKilkanaPostModel|string|null
    {
        $post = $this->publishedAt($year, $month, $day, $slug)
            ->with([self::AUTHOR_PUBLIC, 'ogImage:id,url'])
            ->first();
        if ($post) {
            SnkKilkanaPostViewModel::hit($post->id);

            return $post;
        }

        $history = SnkKilkanaPostSlugModel::query()
            ->where('path', "{$year}/{$month}/{$day}/{$slug}")
            ->first();
        if ($history) {
            $current = SnkKilkanaPostModel::query()->whereKey($history->post_id)->published()->first();
            if ($current?->url_path) {
                return $current->url_path;
            }
        }

        $bySlug = SnkKilkanaPostModel::query()->where('slug', $slug)->published()->get();

        return $bySlug->count() === 1 && $bySlug->first()->url_path
            ? $bySlug->first()->url_path
            : null;
    }

    public function comments(string $year, string $month, string $day, string $slug)
    {
        $postId = $this->publishedId($year, $month, $day, $slug);
        if ($postId === null) {
            return null;
        }

        return SnkKilkanaCommentModel::query()
            ->where('post_id', $postId)
            ->approved()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get(['id', 'post_id', 'user_id', 'parent_id', 'body', 'created_at']);
    }


    public function layout(string $layout): array
    {
        $row = SnkKilkanaLayoutModel::query()->where('layout', $layout)->first();

        return [
            'layout' => $layout,
            'schema' => $row?->schema ?? ['version' => 1, 'sections' => []],
        ];
    }

    public function subscribe(string $email): void
    {
        SnkKilkanaSubscriberModel::firstOrCreate(['email' => strtolower($email)]);
    }

    public function site(): array
    {
        $ajustes = SnkKilkanaSettingModel::query()->find(1);

        return [
            'tenant' => $ajustes ? [
                'name' => $ajustes->tenant_name,
                'tagline' => $ajustes->tenant_tagline,
                'city' => $ajustes->tenant_city,
            ] : null,
            'theme' => $ajustes?->theme,
            'theme_overrides' => $ajustes?->theme_overrides ?? [],
        ];
    }

    private function publishedAt(string $year, string $month, string $day, string $slug)
    {
        return SnkKilkanaPostModel::query()
            ->where('url_date', sprintf('%04d-%02d-%02d', $year, $month, $day))
            ->where('slug', $slug)
            ->published();
    }

    public function publishedId(string $year, string $month, string $day, string $slug): ?int
    {
        $id = $this->publishedAt($year, $month, $day, $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
