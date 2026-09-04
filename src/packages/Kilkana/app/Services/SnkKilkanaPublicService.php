<?php

namespace Packages\Kilkana\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Mail\SnkKilkanaSubscriptionConfirmMail;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorSlugModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCategorySlugModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Models\SnkKilkanaReaderReadModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;

class SnkKilkanaPublicService
{
    private const AUTHOR_PUBLIC = 'author:id,user_id,alias,slug,noindex';

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
            ->withCount(['comments as approved_comments_count' => fn ($c) => $c->approved()])
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

        // Los listados son tarjetas: además de ahorrar peso, no pueden filtrar el cuerpo
        // completo de una pieza cerrada antes de que el detalle aplique el muro. `page` es
        // la hemeroteca: la misma lista, más atrás.
        return $query->forPage((int) ($filters['page'] ?? 1), (int) ($filters['count'] ?? 50))->get()
            ->each(fn (SnkKilkanaPostModel $post) => $this->publicCount($post))
            ->each->makeHidden('body_blocks');
    }

    /**
     * En la calle, `comments_count` es lo que la calle puede ver: los aprobados.
     *
     * El modelo cuenta todos —para moderar hay que ver que hay algo que moderar— y ese
     * contador viaja en cada pieza; aquí se pisa con el de aprobados, porque para un lector
     * un número que incluye spam y rechazados no es moderación, es un artículo que dice tres
     * y enseña dos. Con un alias propio y no con el mismo nombre: dos subconsultas con la
     * misma etiqueta dejan al orden de las columnas decidir cuál sobrevive.
     */
    private function publicCount(SnkKilkanaPostModel $post): SnkKilkanaPostModel
    {
        $post->setAttribute('comments_count', (int) $post->getAttribute('approved_comments_count'));
        $post->offsetUnset('approved_comments_count');

        return $post;
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
        $post = $this->publishedAt($year, $month, $day, $slug)->first(['id', 'content_tier']);
        if ($post === null) {
            return false;
        }
        $postId = (int) $post->getKey();

        if ($event === 'open') {
            SnkKilkanaPostViewModel::record($postId, 'opens', $this->referrerCounter($request));
            // Si quien abre viene con su cuenta, la lectura medida es suya y se le apunta al
            // mes. La ruta no exige sesion —la calle es publica— pero un token de lector que
            // viaje en la peticion se resuelve igual, y sin el no hay a quien apuntarsela.
            $lector = $request->user('sanctum');
            if ($lector !== null) {
                SnkKilkanaReaderReadModel::record((int) $lector->getAuthIdentifier(), $post);
            }

            return true;
        }

        $visitante = sha1($request->ip().$request->userAgent().config('app.key').now()->toDateString());
        // La cache es de la instalacion y cada periodico tiene su base: el nombre de la base
        // es lo que separa a un lector de `demo` del mismo lector en otro inquilino.
        $tenant = sha1(DB::connection()->getDatabaseName());
        $primera = Cache::add("kilkana:reader:{$tenant}:{$postId}:{$visitante}", true, now()->endOfDay());

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

    /**
     * Solo las del menú: una sección en preparación no existe para el lector, y una retirada
     * dejó de ofrecerse.
     *
     * Sin `status` ni `position`: son la cocina del panel. Desde que hay tres estados, decir el
     * estado en la calle es contarle a cualquiera **qué secciones retiró el periódico del menú
     * a propósito**, que es justo lo que se estaba tapando.
     */
    /**
     * Lo que un rastreador puede pedir: la ruta sellada y cuándo se tocó por última vez, de
     * cada pieza publicada que no pida quedarse fuera. Sin relaciones ni contadores, porque
     * un sitemap de un periódico con años de archivo es una lista larga y nada más.
     */
    public function sitemap(): array
    {
        return SnkKilkanaPostModel::query()
            ->published()
            ->where('noindex', false)
            ->orderByDesc('publish_at')
            ->get(['url_path', 'updated_at'])
            ->map(fn (SnkKilkanaPostModel $post) => [
                'path' => '/'.ltrim((string) $post->url_path, '/'),
                'updated_at' => $post->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    public function categories()
    {
        return SnkKilkanaCategoryModel::query()
            ->inMenu()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->makeHidden(['status', 'position']);
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
        $category = SnkKilkanaCategoryModel::query()->reachable()->where('slug', $slug)->first();
        if ($category) {
            // Igual que el catálogo: que responda ya dice que es alcanzable, y el estado con el
            // que lo es —del menú o retirada de él— es cosa de la redacción.
            return $category->makeHidden(['status', 'position']);
        }

        $retirada = SnkKilkanaCategorySlugModel::query()->where('path', $slug)->first();
        if (! $retirada) {
            return null;
        }

        return SnkKilkanaCategoryModel::query()
            ->whereKey($retirada->category_id)
            ->reachable()
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
            ->withSum('views as views_total', 'engaged')
            ->withCount(['comments as approved_comments_count' => fn ($c) => $c->approved()])
            ->first();
        if ($post) {
            $blocks = $post->publicBodyBlocks();
            $post->setAttribute('body_blocks', $blocks);
            // Las fotos del cuerpo, resueltas: un bloque de imagen lleva solo `media_id`, y la
            // calle no puede pedirlas una a una —ni tiene con qué, que la mediateca es del
            // panel—. Van aparte y no dentro del bloque para que el bloque siga siendo el que
            // el editor escribe.
            $ids = collect($blocks)
                ->filter(fn (array $b) => ($b['type'] ?? null) === 'image')
                ->map(fn (array $b) => (int) (($b['config']['media_id'] ?? 0)))
                ->filter()->unique()->values();
            $post->setAttribute('media', $ids->isEmpty() ? [] : SnkKilkanaMediaModel::query()
                ->whereKey($ids)
                ->get(['id', 'url', 'alt', 'caption', 'credit', 'width', 'height', 'mime'])
                ->map(fn (SnkKilkanaMediaModel $m) => [
                    'id' => (string) $m->getKey(),
                    'url' => $m->url,
                    'alt' => (string) $m->alt,
                    'caption' => (string) $m->caption,
                    'credit' => (string) $m->credit,
                    'width' => (int) $m->width,
                    'height' => (int) $m->height,
                ])->values()->all());

            return $this->publicCount($post);
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

    public function subscribe(string $email, Request $request): void
    {
        $consentimiento = [
            'consent_source' => SnkKilkanaConsentSourceEnum::PublicForm,
            'consent_at' => now(),
        ];

        // `status` explícito aunque la columna tenga default: `firstOrCreate` no relee la fila,
        // así que el default de la base no llega al objeto y el recién creado se quedaría con
        // `status` nulo — justo el que hay que mirar tres líneas más abajo.
        $suscriptor = SnkKilkanaSubscriberModel::firstOrCreate(
            ['email' => strtolower($email)],
            $consentimiento + ['status' => SnkKilkanaSubscriberStatusEnum::Pending],
        );

        // Quien se dio de baja y vuelve a rellenar el formulario está pidiendo volver, y eso
        // es consentimiento nuevo: se le devuelve a `pending` para que reconfirme. Sin esto
        // `firstOrCreate` encontraba la fila, no tocaba nada y respondía 202: el alta parecía
        // funcionar y la persona seguía dada de baja para siempre.
        if ($suscriptor->status === SnkKilkanaSubscriberStatusEnum::Unsubscribed) {
            $suscriptor->update($consentimiento + ['status' => SnkKilkanaSubscriberStatusEnum::Pending]);
        }

        // A quien ya está dentro no se le manda nada: no hay nada que confirmar, y devolverlo a
        // `pending` sería una baja de facto por rellenar el formulario dos veces.
        if ($suscriptor->status === SnkKilkanaSubscriberStatusEnum::Confirmed) {
            return;
        }

        $this->sendConfirmation($suscriptor, $request);
    }

    /**
     * Confirma un alta. Solo desde `pending`.
     *
     * Un enlace viejo de confirmación NO puede resucitar a quien luego se dio de baja: sería
     * devolver a la lista a alguien que pidió salir, y el enlace vive en su buzón para siempre.
     */
    public function confirm(SnkKilkanaSubscriberModel $suscriptor, Request $request): void
    {
        if ($suscriptor->status !== SnkKilkanaSubscriberStatusEnum::Pending) {
            return;
        }

        $suscriptor->update([
            'status' => SnkKilkanaSubscriberStatusEnum::Confirmed,
            // El clic desde el buzón es el acto que PRUEBA el consentimiento y sustituye al de
            // rellenar el formulario, así que pisa la fecha en vez de guardar una segunda. Un
            // solo instante y `status` diciendo de qué tipo de prueba se trata.
            'consent_at' => now(),
            'consent_ip' => $request->ip(),
        ]);
    }

    /**
     * Baja. La fila SE QUEDA: es la prueba de que pidió no recibir más, y sin ella la siguiente
     * importación volvería a escribirle.
     */
    public function unsubscribe(SnkKilkanaSubscriberModel $suscriptor): void
    {
        if ($suscriptor->status === SnkKilkanaSubscriberStatusEnum::Unsubscribed) {
            return;
        }

        $suscriptor->update(['status' => SnkKilkanaSubscriberStatusEnum::Unsubscribed]);
    }

    /** A dónde vuelve el lector después de pulsar. Null si el periódico no tiene URL puesta. */
    public function subscriptionLanding(string $desenlace): ?string
    {
        $sitio = SnkKilkanaSettingModel::query()->find(1)?->tenant_url;

        return $sitio ? rtrim($sitio, '/')."/subscribe/{$desenlace}" : null;
    }

    /**
     * Manda la confirmación, y si el correo falla NO tumba el alta.
     *
     * La fila ya está guardada y la respuesta sigue siendo 202 para todos, que es lo que impide
     * que el formulario sea un oráculo de quién está suscrito. Un SMTP caído no puede convertir
     * eso en un 500 que además delate el fallo.
     *
     * Envío síncrono: con la bandeja local son milisegundos. El día que haya un proveedor de
     * verdad esto quiere cola —y hoy el único worker consume `--queue=provisioning`.
     */
    private function sendConfirmation(SnkKilkanaSubscriberModel $suscriptor, Request $request): void
    {
        $periodico = SnkKilkanaSettingModel::query()->find(1)?->tenant_name ?: config('app.name');

        try {
            Mail::to($suscriptor->email)->send(new SnkKilkanaSubscriptionConfirmMail(
                $periodico,
                $this->signedSubscriberUrl('kilkana.subscribe.confirm', $suscriptor, $request),
                $this->signedSubscriberUrl('kilkana.subscribe.unsubscribe', $suscriptor, $request),
            ));
        } catch (\Throwable $e) {
            Log::error('kilkana: no se pudo enviar la confirmación de suscripción', [
                'subscriber' => $suscriptor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * URL firmada hacia el host del PERIÓDICO, no hacia el de la API.
     *
     * La firma cubre el host. El parámetro `client` completa el dominio wildcard de la ruta sin
     * dejar una raíz URL global que pueda filtrarse a la siguiente petición de otro tenant.
     *
     * Sin caducidad: el de baja tiene que seguir funcionando dos años después, y un mecanismo
     * es más fácil de sostener que dos.
     */
    private function signedSubscriberUrl(string $ruta, SnkKilkanaSubscriberModel $suscriptor, Request $request): string
    {
        return URL::signedRoute($ruta, [
            'client' => CorePackageSelectorSupport::subdomainFromHost($request->getHost()),
            'subscriber' => $suscriptor->id,
        ]);
    }

    /**
     * La identidad y la piel del periódico para un lector.
     *
     * La forma es SIEMPRE la misma, haya fila o no. Antes `tenant` salía nulo cuando la fila no
     * existía mientras el flujo administrativo, que la crea con `firstOrCreate`, no veía ese caso:
     * dos
     * formas posibles para el mismo endpoint, y la rara solo aparecía en la salida pública.
     *
     * `url` no sale: el lector ya está en ella, y es un dato de configuración.
     */
    public function site(): array
    {
        $ajustes = SnkKilkanaSettingModel::query()->with('theme')->find(1);

        return [
            'tenant' => [
                'name' => $ajustes?->tenant_name,
                'tagline' => $ajustes?->tenant_tagline,
                'city' => $ajustes?->tenant_city,
            ],
            // La variante resuelta: `base_slug` para buscar en código lo que no baja a la base
            // —layouts, cabecera, lenguaje de bloques— y encima lo que tocó la redacción.
            'theme' => $ajustes?->themePayload(),
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
