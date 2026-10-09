<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
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
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Spatie\QueryBuilder\QueryBuilder;

class SnkKilkanaPublicService
{
    /**
     * Cuántas piezas trae un listado de la calle, y su techo.
     *
     * Más que las veinticinco del panel porque una portada compone con un fondo de cien: el
     * compositor elige de ahí, y con menos no saldría lo elegido. El techo es el mismo que el
     * del panel, que es lo que impide que un enlace a mano se lleve el archivo entero.
     */
    public const PER_PAGE = 50;

    public const PER_PAGE_MAX = 100;

    /**
     * La firma, con lo que la calle pinta de ella.
     *
     * Es proyección de la consulta, no lista de salida —eso lo declara `SnkKilkanaAuthorResource`—:
     * `user_id` está porque el nombre publicado es derivado y sale de la cuenta cuando no hay alias.
     */
    private const AUTHOR_PUBLIC = 'author:id,user_id,alias,slug,noindex,avatar_media_id';

    /**
     * El techo de `?fields=` en la calle, y **es más estrecho que el del panel a propósito**.
     *
     * Es la única lista que decide qué puede leer un anónimo: la lista blanca de esta puerta.
     * Lo que no está no se puede pedir, y lo que no se pide no viaja. Fuera quedan `created_by`,
     * `updated_by`, `deleted_at`, `deleted_by` y `featured`: dicen quién escribió, quién editó y
     * quién tiró una pieza.
     *
     * `body_blocks` tampoco: el cuerpo de la calle lo sirve `publicBodyBlocks()`, que aplica el
     * muro de pago. Un listado que lo pudiera pedir se saltaría el muro y además arrastraría
     * **239 KB** de cuerpos en una portada de cien piezas para tirarlos al final.
     *
     * Las tres foráneas están porque sin ellas la relación incluida llega nula: un `belongsTo`
     * empareja por la clave de la fila, y si el recorte se la lleva, Eloquent no avisa.
     */
    public const FIELDS = [
        'id', 'title', 'excerpt', 'slug', 'status', 'content_tier',
        'publish_at', 'unpublish_at', 'updated_at', 'url_date',
        'noindex', 'seo_title', 'seo_description', 'og_title', 'og_description', 'og_image_id',
        'category_id', 'author_id', 'cover_media_id', 'cover_alt', 'cover_caption', 'presentation',
        'categories.id', 'categories.name', 'categories.slug', 'categories.parent_id',
        'authors.id', 'authors.alias', 'authors.slug', 'authors.noindex', 'authors.user_id',
        'authors.avatar_media_id',
        // El retrato de una firma **sí** deja elegir columnas, al revés que la portada de una
        // pieza: ahí `variants` obliga a nombrar cuatro columnas de fontanería para recibir las
        // derivadas, y aquí es un disco de 44 px que se sirve con su `url` y ya. Anidada dentro
        // de cada pieza de un pozo de cien, la fila entera de la foto son veintinueve campos
        // repetidos que nadie mira.
        'author.avatar_medias.id', 'author.avatar_medias.url',
        'tags.id', 'tags.name', 'tags.slug',
    ];

    /** Las columnas propias del techo, sin las de las relaciones incluidas. */
    private static function columns(): array
    {
        return array_values(array_filter(self::FIELDS, fn (string $field) => ! str_contains($field, '.')));
    }

    /**
     * Las fotos no llevan `fields`: su forma la declara `SnkKilkanaMediaResource`. Ver allí.
     *
     * `author.avatarMedia` es anidada y opcional: la tira de opinión de una portada dibuja el
     * retrato de quien firma, y sin él una banda de opinión es indistinguible de una fila de
     * titulares. El resto de listados no la piden y no la pagan.
     */
    public const INCLUDES = ['category', 'author', 'tags', 'coverMedia', 'author.avatarMedia'];

    public function posts(array $filters)
    {
        // El orden importa y está pagado: primero el alcance —solo publicadas—, luego lo que
        // la query recorta e incluye, y **los agregados al final**. `withSum()` y `withCount()`
        // pasan por `withAggregate()`, que pone `select tabla.*` y cuelga la subconsulta; el
        // `select()` de `?fields=` reemplaza esa lista, así que aplicarlos antes es escribirlos
        // para que los borren.
        $query = QueryBuilder::for(SnkKilkanaPostModel::query()->published())
            ->allowedFields(...self::FIELDS)
            ->allowedIncludes(...self::INCLUDES);

        // **Sin `?fields=`, el techo es también el valor por defecto**, y esto no es aseo.
        // El paquete, cuando nadie recorta, no recorta: selecciona la fila entera. En una
        // puerta anónima eso significa devolver `body_blocks` —el cuerpo completo, muro de
        // pago incluido— a quien pida `/public/posts` a secas. Y 239 KB de cuerpos en una
        // portada de cien piezas para tirarlos al final.
        //
        // Va antes que los agregados a propósito: `select()` reemplaza la lista de columnas,
        // y `withSum()`/`withCount()` cuelgan la suya encima.
        if (! request()->filled('fields')) {
            $query->select(self::columns());
        }

        $query
            // Se suma `engaged`, no `opens`: «lo más leído» tiene que ordenarse por lo que la
            // gente lee, no por lo que consigue que pulsen.
            //
            // **Y una suma, no tres.** Cada ventana más es una subconsulta correlacionada por
            // fila: en una portada de cien piezas, dos ventanas que no lee nadie son doscientas
            // subconsultas por carga.
            ->withSum('views as views_total', 'engaged')
            // En la calle, `comments_count` es lo que la calle puede ver: los aprobados. Para
            // un lector, un número que incluye spam y rechazados no es moderación: es un
            // artículo que dice tres y enseña dos. Con el alias definitivo y no con uno que
            // luego se reescribe: dos subconsultas con la misma etiqueta dejan al orden de las
            // columnas decidir cuál sobrevive.
            ->withCount(['comments as comments_count' => fn ($c) => $c->approved()])
            // El desempate por `id` no es adorno: dos piezas publicadas en el mismo minuto
            // salen en un orden u otro según el plan de la consulta, y esta lista se pagina
            // con `forPage`. Un orden inestable hace que la página 2 repita una y se salte otra.
            ->orderByDesc('publish_at')
            ->orderByDesc('id');

        if (! empty($filters['category'])) {
            $query->whereHas('category', fn ($q) => $q
                ->where('slug', $filters['category'])
                ->orWhereHas('parent', fn ($parent) => $parent->where('slug', $filters['category'])));
        }
        if (! empty($filters['author'])) {
            $query->whereHas('author', fn ($q) => $q->where('slug', $filters['author']));
        }
        if (! empty($filters['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('slug', $filters['tag']));
        }
        if (! empty($filters['q'])) {
            $query->whereFullText(['title', 'excerpt'], $filters['q']);
        }

        // Los listados son tarjetas: `page` es la hemeroteca, la misma lista más atrás.
        return $query->forPage((int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? self::PER_PAGE))->get();
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
            // mes. La ruta no exige sesión —la calle es pública— pero un token de lector que
            // viaje en la petición se resuelve igual, y sin él no hay a quién apuntársela.
            $reader = $request->user('sanctum');
            if ($reader !== null) {
                SnkKilkanaReaderReadModel::record((int) $reader->getAuthIdentifier(), $post);
            }

            return true;
        }

        $visitor = sha1($request->ip().$request->userAgent().config('app.key').now()->toDateString());
        // La caché es de la instalación y cada periódico tiene su base: el nombre de la base
        // es lo que separa a un lector de `demo` del mismo lector en otro inquilino.
        $tenant = sha1(DB::connection()->getDatabaseName());
        $firstRead = Cache::add("kilkana:reader:{$tenant}:{$postId}:{$visitor}", true, now()->endOfDay());

        SnkKilkanaPostViewModel::record($postId, 'engaged', ...($firstRead ? ['readers'] : []));

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
     * Lo que un rastreador puede pedir: la ruta sellada y cuándo se tocó por última vez, de
     * cada pieza publicada que no pida quedarse fuera. Sin relaciones ni contadores, porque
     * un sitemap de un periódico con años de archivo es una lista larga y nada más.
     */
    public function sitemap(): array
    {
        return SnkKilkanaPostModel::query()
            // Solo las columnas de las que salen los dos campos: traer la fila entera sería traer
            // `body_blocks` de todo el archivo publicado para devolver una ruta y una fecha.
            // `url_path` no es una columna, es un accesor sobre `url_date` y `slug`, así que se
            // piden esas dos. `without()` asegura que ninguna carga ansiosa entre en una lista
            // que no pinta relaciones.
            ->select(['id', 'url_date', 'slug', 'updated_at'])
            ->without(['category', 'author', 'coverMedia', 'tags'])
            ->published()
            ->where('noindex', false)
            // Mismo desempate que el listado: sin él, dos piezas del mismo minuto salen en un
            // orden distinto en cada consulta y el sitemap cambia sin que cambie el periódico.
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SnkKilkanaPostModel $post) => [
                'path' => '/'.ltrim((string) $post->url_path, '/'),
                'updated_at' => $post->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Una etiqueta, con lo que la calle rotula; `null` si no existe. */
    public function tag(string $slug): ?array
    {
        $tag = SnkKilkanaTagModel::query()->where('slug', $slug)->first(['name', 'slug']);

        return $tag === null ? null : ['name' => $tag->name, 'slug' => $tag->slug];
    }

    /**
     * Solo las del menú: una sección en preparación no existe para el lector, y una retirada
     * dejó de ofrecerse.
     *
     * No se dice el estado: con tres estados, decirlo en la calle es contarle a cualquiera
     * **qué secciones retiró el periódico del menú a propósito**, que es justo lo que retirarlas
     * calla.
     */
    public function categories()
    {
        // La madre se incluye porque la calle rotula «Local · Deportes»; `status` y `position`
        // no se seleccionan porque son de la redacción, y que la sección responda ya dice que
        // es alcanzable.
        return SnkKilkanaCategoryModel::query()
            ->inMenu()
            ->with('parent:id,slug,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'noindex', 'parent_id']);
    }

    /**
     * Una sección por su slug, o el slug de ahora si la URL pedida es una que dejó atrás.
     *
     * Devuelve cadena para redirigir, igual que `post()` y `author()`. Existe para eso: sin
     * una entrada por slug, el historial de URLs de una sección se guarda y no hay dónde
     * consultarlo, y renombrar una sección rompe sus enlaces.
     *
     * La redirección exige que la sección siga lanzada, por el mismo motivo que la de un
     * artículo exige que siga publicado: una mesa retirada no recibe tráfico por detrás.
     */
    public function category(string $slug): SnkKilkanaCategoryModel|string|null
    {
        $category = SnkKilkanaCategoryModel::query()
            ->reachable()
            ->with('parent:id,slug,name')
            ->where('slug', $slug)
            ->first(['id', 'slug', 'name', 'noindex', 'parent_id']);
        if ($category) {
            // Igual que el catálogo: que responda ya dice que es alcanzable, y el estado con el
            // que lo es —del menú o retirada de él— es cosa de la redacción.
            return $category;
        }

        $retired = SnkKilkanaCategorySlugModel::query()->where('path', $slug)->first();
        if (! $retired) {
            return null;
        }

        return SnkKilkanaCategoryModel::query()
            ->whereKey($retired->category_id)
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
            $retired = SnkKilkanaAuthorSlugModel::query()->where('path', $slug)->first();

            return $retired
                ? SnkKilkanaAuthorModel::query()->whereKey($retired->author_id)->value('slug')
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
        $post = $this->article($this->publishedAt($year, $month, $day, $slug));
        if ($post) {
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
            ->with('user:id,name,lastname')
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
        $consent = [
            'consent_source' => SnkKilkanaConsentSourceEnum::PublicForm,
            'consent_at' => now(),
        ];

        // `status` explícito aunque la columna tenga default: `firstOrCreate` no relee la fila,
        // así que el default de la base no llega al objeto y el recién creado se quedaría con
        // `status` nulo — justo el que hay que mirar tres líneas más abajo.
        $subscriber = SnkKilkanaSubscriberModel::firstOrCreate(
            ['email' => strtolower($email)],
            $consent + ['status' => SnkKilkanaSubscriberStatusEnum::Pending],
        );

        // Quien se dio de baja y vuelve a rellenar el formulario está pidiendo volver, y eso
        // es consentimiento nuevo: se le devuelve a `pending` para que reconfirme. Sin esto
        // `firstOrCreate` encuentra la fila, no toca nada y responde 202: el alta parece
        // funcionar y la persona sigue dada de baja para siempre.
        if ($subscriber->status === SnkKilkanaSubscriberStatusEnum::Unsubscribed) {
            $subscriber->update($consent + ['status' => SnkKilkanaSubscriberStatusEnum::Pending]);
        }

        // A quien ya está dentro no se le manda nada: no hay nada que confirmar, y devolverlo a
        // `pending` sería una baja de facto por rellenar el formulario dos veces.
        if ($subscriber->status === SnkKilkanaSubscriberStatusEnum::Confirmed) {
            return;
        }

        $this->sendConfirmation($subscriber, $request);
    }

    /**
     * Confirma un alta. Solo desde `pending`.
     *
     * Un enlace viejo de confirmación NO puede resucitar a quien luego se dio de baja: sería
     * devolver a la lista a alguien que pidió salir, y el enlace vive en su buzón para siempre.
     */
    public function confirm(SnkKilkanaSubscriberModel $subscriber, Request $request): void
    {
        if ($subscriber->status !== SnkKilkanaSubscriberStatusEnum::Pending) {
            return;
        }

        $subscriber->update([
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
    public function unsubscribe(SnkKilkanaSubscriberModel $subscriber): void
    {
        if ($subscriber->status === SnkKilkanaSubscriberStatusEnum::Unsubscribed) {
            return;
        }

        $subscriber->update(['status' => SnkKilkanaSubscriberStatusEnum::Unsubscribed]);
    }

    /** A dónde vuelve el lector después de pulsar. Null si el periódico no tiene URL puesta. */
    public function subscriptionLanding(string $outcome): ?string
    {
        $siteUrl = SnkKilkanaSettingModel::query()->find(1)?->tenant_url;

        return $siteUrl ? rtrim($siteUrl, '/')."/subscribe/{$outcome}" : null;
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
    private function sendConfirmation(SnkKilkanaSubscriberModel $subscriber, Request $request): void
    {
        $newspaper = SnkKilkanaSettingModel::query()->find(1)?->tenant_name ?: config('app.name');

        try {
            Mail::to($subscriber->email)->send(new SnkKilkanaSubscriptionConfirmMail(
                $newspaper,
                $this->signedSubscriberUrl('kilkana.subscribe.confirm', $subscriber, $request),
                $this->signedSubscriberUrl('kilkana.subscribe.unsubscribe', $subscriber, $request),
            ));
        } catch (\Throwable $e) {
            Log::error('kilkana: could not send the subscription confirmation', [
                'subscriber' => $subscriber->id,
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
    private function signedSubscriberUrl(string $path, SnkKilkanaSubscriberModel $subscriber, Request $request): string
    {
        return URL::signedRoute($path, [
            'client' => CorePackageSelectorSupport::subdomainFromHost($request->getHost()),
            'subscriber' => $subscriber->id,
        ]);
    }

    /**
     * La previa por enlace: la pieza tal y como saldría, esté en el estado que esté, para
     * quien tenga el enlace. El enlace lleva la firma del periódico y una caducidad, y con
     * eso no hace falta sesión: se manda a quien tiene que dar el visto bueno y ya.
     */
    public function preview(int $id, int $until, string $signature): ?SnkKilkanaPostModel
    {
        if ($until < now()->getTimestamp() || ! hash_equals($this->previewSignature($id, $until), $signature)) {
            return null;
        }

        return $this->article(SnkKilkanaPostModel::query()->whereKey($id));
    }

    /** Lo que firma un enlace de previa: la pieza, la caducidad y el periódico, con la llave de la instalación. */
    public function previewSignature(int $id, int $until): string
    {
        $newspaper = (string) config('database.connections.tenant.database');

        return hash_hmac('sha256', "preview:{$newspaper}:{$id}:{$until}", (string) config('app.key'));
    }

    /**
     * Una pieza servida entera: firma, portada, el cuerpo ya recortado por el muro y las
     * fotos que ese cuerpo necesita. La consulta dice cuál; esto dice cómo sale.
     */
    private function article(Builder $query): ?SnkKilkanaPostModel
    {
        $post = $query
            // La madre de la sección, por la plantilla de pieza que una hija hereda de ella.
            ->with([self::AUTHOR_PUBLIC, 'ogImage', 'category', 'category.parent:id,name,slug,template', 'tags', 'coverMedia'])
            ->withSum('views as views_total', 'engaged')
            // Los aprobados, con el alias definitivo: es lo que la calle puede ver.
            ->withCount(['comments as comments_count' => fn ($c) => $c->approved()])
            ->first();
        if (! $post) {
            return null;
        }

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
            ->get(['id', 'url', 'alt', 'caption', 'credit', 'width', 'height', 'mime', 'master_path', 'master_width', 'master_height', 'variants_key', 'focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h'])
            ->map(fn (SnkKilkanaMediaModel $m) => [
                'id' => (string) $m->getKey(),
                'url' => $m->url,
                'alt' => (string) $m->alt,
                'caption' => (string) $m->caption,
                'credit' => (string) $m->credit,
                'width' => (int) $m->width,
                'height' => (int) $m->height,
                'variants' => $m->variants,
            ])->values()->all());

        return $post;
    }

    /**
     * La identidad y la piel del periódico para un lector.
     *
     * La forma es SIEMPRE la misma, haya fila o no: `tenant` sale con sus claves aunque la fila
     * no exista. El flujo administrativo la crea con `firstOrCreate` y nunca ve ese caso, así
     * que una segunda forma para el mismo endpoint solo aparecería en la salida pública.
     *
     * `url` no sale: el lector ya está en ella, y es un dato de configuración.
     */
    public function site(): array
    {
        $settings = SnkKilkanaSettingModel::query()->with('theme')->find(1);

        return [
            'tenant' => [
                'name' => $settings?->tenant_name,
                'tagline' => $settings?->tenant_tagline,
                'city' => $settings?->tenant_city,
                'url' => $settings?->tenant_url,
            ],
            // Lo que la hoja necesita para hablar como el periódico: su idioma y su reloj, si se
            // deja encontrar, y quién es y dónde está en las redes para el JSON-LD.
            'lang' => $settings?->lang ?? SnkKilkanaSettingModel::DEFAULT_LANG,
            'tz' => $settings?->tz ?? SnkKilkanaSettingModel::DEFAULT_TZ,
            'search_visible' => $settings === null || (bool) $settings->search_visible,
            // Nulo mientras no haya abierto: la calle sirve entonces la página de «pronto».
            'launched_at' => $settings?->launched_at?->toIso8601String(),
            'legal' => SnkKilkanaSettingService::group($settings?->legal, SnkKilkanaSettingModel::LEGAL),
            'social' => SnkKilkanaSettingService::group($settings?->social, SnkKilkanaSettingModel::SOCIAL),
            // La variante resuelta: `base_slug` para buscar en código lo que no baja a la base
            // —layouts, cabecera, lenguaje de bloques— y encima lo que tocó la redacción.
            'theme' => $settings?->themePayload(),
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
