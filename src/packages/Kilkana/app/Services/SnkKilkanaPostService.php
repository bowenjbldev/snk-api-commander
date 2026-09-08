<?php

namespace Packages\Kilkana\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Support\SnkKilkanaRichText;
use Spatie\QueryBuilder\QueryBuilder;

class SnkKilkanaPostService
{
    /**
     * El listado editorial, con la audiencia que el panel enseña.
     *
     * **Una suma y no tres.** Aqui hubo tambien `views_day` y `views_week`, y no las pintaba
     * nadie: eran dos subconsultas correlacionadas por fila, calculadas y tiradas en cada
     * peticion del listado. Medido en 522 piezas y 12 212 filas de audiencia, ordenar por
     * lecturas pasaba de 5,9 a 18,6 ms de SQL solo por ellas — porque ordenar por el alias de
     * una correlacionada obliga a calcularla para TODAS las filas, no para las veinticinco que
     * se devuelven. La calle tenia las suyas y **tampoco las pintaba nadie**: se quitaron
     * despues, asi que hoy ningun listado suma mas de una ventana.
     *
     * `engaged`, no `opens`: la API de audiencia cuenta lecturas reales.
     */
    public function query(int $userId, bool $canEditOthers): Builder
    {
        return $this->scopeToOwner(SnkKilkanaPostModel::query(), $userId, $canEditOthers);
    }

    /**
     * La suma de audiencia, y **va después de elegir columnas, no antes**.
     *
     * `withSum()` pasa por `withAggregate()`, que si la consulta no tiene columnas le pone
     * `select tabla.*` y encima cuelga la subconsulta. El `select()` con el que
     * `?fields=` recorta **reemplaza** esa lista, así que aplicar la suma primero es
     * escribirla para que la borren: `views_total` desaparecía sin error y la audiencia se
     * leía como cero. Es la misma trampa que ya costó el `$withCount` global del modelo.
     *
     * Con ventana la suma cuenta solo esos días; sin ella, el archivo entero. Es UNA suma y no
     * dos, porque el panel enseña una cifra: mandar las dos y elegir en el cliente sería pagar
     * la subconsulta que no se pinta.
     *
     * @template T of Builder|\Spatie\QueryBuilder\QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function withAudience($query, ?int $dias)
    {
        $ventana = $dias === null
            ? fn (Builder $q) => $q
            : fn (Builder $q) => $q->where('date', '>=', now()->subDays($dias - 1)->toDateString());

        return $query->withSum(['views as views_total' => $ventana], 'engaged');
    }

    public function find($id, int $userId, bool $canEditOthers): ?SnkKilkanaPostModel
    {
        $post = SnkKilkanaPostModel::query()->find($id);

        if ($post === null || $canEditOthers || (int) $post->created_by === $userId) {
            return $post;
        }

        return null;
    }

    /**
     * Por qué columna ordena cada clave de la vista, y **por tanto el vocabulario de `sort`**.
     *
     * Público y una sola lista: el controlador tenía las claves (`['date','title','reads']`)
     * para validar la query y esto tenía el mapa para ordenar. Eran la misma lista escrita dos
     * veces con distinta forma, y un orden nuevo había que darlo de alta en los dos sitios o
     * quedaba aceptado y sin efecto. `reads` es el alias del `withSum`.
     */
    public const SORTS = [
        'date' => 'publish_at',
        'title' => 'title',
        'reads' => 'views_total',
    ];

    /**
     * El techo de `?fields=`, y **quién elige dentro es quien llama**.
     *
     * Vive aquí y no en el controlador porque lo declaran dos puertas: `GET posts` y las cuatro
     * colas de `panel/dashboard`, que son el mismo listado servido de otra manera. Con una copia
     * en cada una, el día que la calle pudiera pedir un campo más el tablero no lo sabría.
     *
     * Fuera quedan a propósito `created_by`, `updated_by`, `deleted_by` y `deleted_at`: son de la
     * auditoría, no de la pieza. `body_blocks` tampoco —es el cuerpo entero, y pedirlo en una
     * página de veinticinco es traerse el periódico—: lo trae `GET posts/{id}`.
     *
     * **`views_total` no está y no puede estar**: es el alias de un `withSum`, no una columna, y
     * `?fields=` antepone la tabla a todo lo que recibe — `kilkana_posts.views_total` no existe
     * y la consulta muere con un `no such column`. La audiencia la cuelga `withAudience()`
     * después de recortar, y sale sola: pedirla aparte sería un `?include=` con su propia clase,
     * que es lo que hace falta el día que un listado no quiera pagar la subconsulta.
     *
     * Las entradas con punto son de las relaciones incluidas, y el prefijo es **el nombre de la
     * relación en plural snake**, no el de la tabla —`category` → `categories`—, que es como
     * `getRequestedFieldsForRelatedTable()` las busca.
     */
    public const FIELDS = [
        'id', 'title', 'excerpt', 'slug', 'status', 'content_tier',
        'publish_at', 'unpublish_at', 'updated_at', 'created_at',
        'url_date', 'cover_media_id', 'og_image_id', 'noindex', 'featured',
        // Las foráneas de las relaciones incluidas. **Sin ellas la relación llega nula**: un
        // `belongsTo` empareja lo cargado con la clave de la fila, y si el recorte se la lleva,
        // Eloquent no tiene con qué emparejar y no avisa. Es lo que el mapa `COLUMNS_FOR` sabía
        // y esto sustituye: aquí quien incluye una relación pide también su llave, y se ve.
        'category_id', 'author_id',
        'seo_title', 'seo_description', 'og_title', 'og_description',
        'categories.id', 'categories.name', 'categories.slug',
        'authors.id', 'authors.alias', 'authors.slug', 'authors.user_id',
        'tags.id', 'tags.name', 'tags.slug',
    ];

    /** `commentsCount` es el sufijo de agregado de spatie: `?include=commentsCount`. */
    public const INCLUDES = ['category', 'author', 'tags', 'commentsCount'];

    /** Página por defecto, y el techo que impide restaurar el volcado entero. */
    public const PER_PAGE = 25;

    public const PER_PAGE_MAX = 100;

    /**
     * Sobre cuántos días se cuentan las lecturas, y `null` es «desde siempre».
     *
     * Estático porque lo preguntan dos puertas —el listado editorial y el tablero— y porque no
     * necesita nada del servicio. Solo dos valores porque solo hay dos botones: una ventana
     * libre invita a pedir mil días y a pagar la subconsulta entera sin que nadie mire el
     * resultado. Lo que no sea uno de los dos es «desde siempre», no un 422.
     */
    public static function readsWindow(mixed $dias): ?int
    {
        return in_array((int) $dias, [7, 30], true) ? (int) $dias : null;
    }

    /**
     * La sección que existe, por su nombre; `null` si no está.
     *
     * Una sección desconocida se **ignora**, no filtra a cero: dejar la tabla vacía sin decir
     * por qué es lo que la validación en la frontera evita. Vive aquí y no en el controlador
     * porque es una consulta.
     */
    public function knownCategory(string $nombre): ?string
    {
        return SnkKilkanaCategoryModel::query()->where('name', $nombre)->exists() ? $nombre : null;
    }

    /**
     * El filtro del listado editorial: estado, sección, búsqueda y orden.
     *
     * Los valores llegan ya saneados por el controlador, que es quien conoce la URL.
     * Aquí solo se traducen a cláusulas.
     */
    /**
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function filter($query, array $filters)
    {
        if (($status = $filters['status'] ?? null) !== null) {
            $query->where('status', $status);
        }

        if (($category = $filters['category'] ?? null) !== null) {
            $query->whereHas('category', fn (Builder $seccion) => $seccion->where('name', $category));
        }

        if (($term = $filters['q'] ?? null) !== null) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $todo) => $todo
                ->where('title', 'like', $like)
                ->orWhereHas('author', fn (Builder $firma) => $this->matchesByline($firma, $like)));
        }

        $column = self::SORTS[$filters['sort'] ?? 'date'] ?? 'publish_at';
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // El desempate por `id` no es adorno: sin él, dos piezas con la misma fecha pueden
        // salir en distinto orden en dos consultas, y entonces paginar repite una fila en la
        // página 2 y se salta otra. Un orden estable es requisito de la paginación.
        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }

    /**
     * Una página del archivo: filtrada, ordenada, acotada y con las filas ya recortadas.
     *
     * Existe porque **esto se escribía dos veces**: el listado editorial y las cuatro colas del
     * tablero repetían `filter()->paginate()->each(asListRow)`, y la segunda copia la escribí yo
     * al componer `panel/bootstrap`. Un orden nuevo o un recorte distinto en una de las dos las
     * separaba en silencio, que es justo lo que `KilkanaPanelCompositeTest` vigila comparándolas.
     *
     * **Una página fuera de rango enseña la última**, no una tabla vacía: es lo que hace que un
     * enlace guardado con `?page=999` siga significando algo cuando el archivo ha encogido. La
     * segunda consulta parte de un `clone` y no de la primera, porque paginar deja el `limit`
     * puesto en el constructor y repaginar sobre ella heredaría el de la página que no existe.
     *
     * Acepta las dos formas de consulta porque `Spatie\QueryBuilder\QueryBuilder` **no extiende**
     * el `Builder` de Eloquent: reenvía por `__call`. Su `__clone()` sí clona el subject, así que
     * la repaginación de abajo vale igual para las dos.
     *
     * @param  array<string, mixed>  $filters  ya saneados por quien leyó la query
     * @return LengthAwarePaginator<int, SnkKilkanaPostModel>
     */
    public function page(Builder|QueryBuilder $query, array $filters, int $perPage): LengthAwarePaginator
    {
        $filtrada = $this->filter($query, $filters);
        $pagina = (clone $filtrada)->paginate($perPage);

        if ($pagina->currentPage() > $pagina->lastPage() && $pagina->total() > 0) {
            $pagina = $filtrada->paginate($perPage, ['*'], 'page', $pagina->lastPage());
        }

        return $pagina;
    }

    /**
     * Buscar por firma es buscar por el nombre **publicado**, que es derivado.
     *
     * Es el alias si lo hay y el de la cuenta si no. Mirar los dos a secas encontraría
     * las piezas firmadas «Redacción» al teclear el nombre de quien las escribió, que es
     * justo lo que la firma colectiva existe para no decir.
     */
    private function matchesByline(Builder $firma, string $like): Builder
    {
        return $firma
            ->where(fn (Builder $conAlias) => $conAlias
                ->whereNotNull('alias')
                ->where('alias', '<>', '')
                ->where('alias', 'like', $like))
            ->orWhere(fn (Builder $sinAlias) => $sinAlias
                ->where(fn (Builder $vacio) => $vacio->whereNull('alias')->orWhere('alias', ''))
                ->whereHas('user', fn ($cuenta) => $cuenta->where('name', 'like', $like)));
    }

    /**
     * Cuantas piezas hay de cada estado, **sin ningun filtro puesto y en las dos escalas**.
     *
     * Es lo que sostiene que «Draft 35» siga diciendo 35 mientras se miran los publicados: la
     * pestana cuenta el archivo, no la pagina. Las claves salen del enum, asi que un estado
     * nuevo aparece aqui sin tocar nada.
     *
     * Devuelve `scoped` —lo que ve quien mira, que es **siempre** lo mismo que le devuelve el
     * listado— y `all` —el periodico entero—. El tablero las ensena juntas: «34 of 467» dice a
     * la vez lo que ha publicado quien mira y lo que hay en la casa, y sin la segunda cifra ese
     * 34 aparece al lado de una seccion con 40 y se lee como una contradiccion.
     *
     * **Son dos permisos y no uno.** `edit others posts` contesta «puedes cambiar la pieza de
     * otro» y acota el listado; `view others posts` contesta «puedes saber que existe» y es lo
     * unico que abre la segunda escala. Fundirlos obliga a elegir entre dejar la contradiccion
     * puesta para media redaccion o publicarle el sumario a quien escribe desde fuera.
     *
     * @return array{scoped: array<string, int>, all: array<string, int>}
     */
    public function countsByStatus(int $userId, bool $canEditOthers, bool $canViewOthers): array
    {
        // El `SUM` **solo cuando hace falta**: con `edit others posts` las dos escalas son la
        // misma y calcularlo seria calcular algo para tirarlo. Y no es gratis — `created_by` no
        // esta en `(status, publish_at)`, asi que sin el indice `(status, created_by)` la suma
        // saca la consulta del indice cubridor y la manda a barrer la tabla con temporal y
        // filesort.
        //
        // Filtrar por autor en vez de sumar **no ahorra nada** y esta medido: `GROUP BY status`
        // recorre el indice entero en los dos casos —mismas 457 filas, `Using index` en los
        // dos—, porque `status` es la columna que manda y `created_by` solo se comprueba al
        // pasar. La segunda escala se descarta abajo, que es donde importa.
        $rows = SnkKilkanaPostModel::query()
            ->select('status')
            ->selectRaw('count(*) as total')
            ->when(! $canEditOthers, fn ($q) => $q->selectRaw('sum(created_by = ?) as own', [$userId]))
            ->groupBy('status')
            // `toBase()` y no `get()`: hidratar modelos para un agregado arrastra la carga
            // ansiosa que el modelo declara, y eso es una segunda consulta —de etiquetas, con
            // `post_id in (0)`— para unas filas que no son piezas sino recuentos.
            ->toBase()
            ->get()
            ->keyBy('status');

        $scoped = [];
        $all = [];
        foreach (SnkKilkanaPostStatusEnum::cases() as $estado) {
            $fila = $rows[$estado->value] ?? null;
            $total = (int) ($fila->total ?? 0);
            $propias = $canEditOthers ? $total : (int) ($fila->own ?? 0);

            $scoped[$estado->value] = $propias;
            // Sin `view others posts` la segunda escala **no se calla, se iguala**: el panel
            // deduce que hay una sola escala de que las dos cifras coincidan, asi que omitirla
            // le dejaria la barra sin saber que dibujar.
            $all[$estado->value] = $canViewOthers ? $total : $propias;
        }

        return ['scoped' => $scoped, 'all' => $all];
    }

    /**
     * Guardar un articulo son VARIAS escrituras, y o entran todas o no entra ninguna.
     *
     * Un alta toca el post, puede dar de alta el medio de portada y da de alta las etiquetas
     * que no existan mas su pivote. Sin transaccion, un fallo a mitad deja el desecho puesto y
     * el cliente recibe un error: cree que no ha pasado nada y si ha pasado.
     */
    public function create(array $data, int $userId): SnkKilkanaPostModel
    {
        return DB::transaction(function () use ($data, $userId) {
            $post = SnkKilkanaPostModel::create([
                ...$this->columnsFrom($data),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->syncRelations($post, $data);

            return $post->fresh();
        });
    }

    /**
     * Cinco escrituras: la revision, el post, las etiquetas y las dos del historial de URLs.
     *
     * Este es el que lo hizo evidente. Un fallo en las etiquetas dejaba el post guardado y la
     * revision registrada mientras la respuesta era un 500 — o sea, el historial anotaba una
     * edicion que se anuncio como fallida, y la URL vieja podia quedar sin su redireccion.
     */
    public function update(SnkKilkanaPostModel $post, array $data, int $userId): SnkKilkanaPostModel
    {
        return DB::transaction(function () use ($post, $data, $userId) {
            SnkKilkanaPostRevisionModel::create([
                'post_id' => $post->id,
                'user_id' => $userId,
                'payload' => $post->getAttributes(),
            ]);

            $previousPath = $post->url_path;
            $post->fill($this->columnsFrom($data));
            $post->updated_by = $userId;
            $post->save();
            $this->syncRelations($post, $data);
            $this->shiftUrlHistory($post, $previousPath);

            return $post->fresh();
        });
    }

    /** Una accion masiva es UNA decision de quien la pulsa: no puede aplicarse a medias. */
    public function bulk(array $ids, string $action, int $userId, bool $canEditOthers, array $tags = []): int
    {
        $movidas = DB::transaction(function () use ($ids, $action, $userId, $canEditOthers, $tags) {
            $query = $this->scopeToOwner(SnkKilkanaPostModel::query(), $userId, $canEditOthers)
                ->whereIn('id', $ids);

            if ($action === 'delete') {
                // Pieza a pieza y no en una sentencia: el borrado es lógico, y apuntar quién
                // lo tiró y dejar rastro en el registro pasa por los eventos del modelo.
                $count = 0;
                foreach ($query->get() as $post) {
                    $count += (int) $post->delete();
                }

                return $count;
            }

            if ($action === 'tag' || $action === 'untag') {
                return $this->retag($query->get(), $action === 'tag', $tags, $userId);
            }

            // El mapa vive en el enum: el controlador lo usa para decidir el permiso y aqui se
            // usa para escribir, y dos copias es como una acepta lo que la otra no sabe mover.
            $destino = SnkKilkanaPostStatusEnum::fromBulkAction($action);
            if ($destino === null) {
                return 0;
            }

            // Sacar a la calle exige lo mismo por el lote que por el editor. `required_if`
            // solo mira la peticion, y una peticion de lote son ids: marcar veinte borradores
            // y pulsar «Publicar» publicaba veinte piezas sin entradilla. Las que no estan
            // listas se quedan como estan y el recuento lo dice, en vez de fallar el lote
            // entero por una — el resto SI estaban listas.
            $exigeEntradilla = in_array($destino->value, SnkKilkanaPostStatusEnum::publishing(), true);

            $count = 0;
            foreach ($query->get() as $post) {
                if ($exigeEntradilla && ! $post->hasExcerpt()) {
                    continue;
                }

                $post->status = $destino;
                // Publicar a mano una pieza programada la saca AHORA: dejarle la fecha prevista
                // la ordenaria manana en la portada, el sitemap y el feed.
                if ($destino === SnkKilkanaPostStatusEnum::Published
                    && ($post->publish_at === null || $post->publish_at->isFuture())) {
                    $post->publish_at = now();
                }
                $post->updated_by = $userId;
                $post->save();
                $count++;
            }

            return $count;
        });

        // Una escritura en tanda que no deja rastro no se puede reconstruir después. Si mañana
        // alguien jura que publicó veinte, esto es lo único que hay dónde mirar, y **la
        // diferencia entre lo pedido y lo movido es justo el dato que interesa**: lo que no se
        // movió es lo que no era suyo o ya estaba así.
        //
        // Va aquí y no en el controlador porque aquí está todo lo que se apunta —quién, qué, en
        // cuántas— sin tener que preguntárselo a la petición.
        Log::info('kilkana: acción en tanda sobre artículos', [
            'action' => $action,
            'user_id' => $userId,
            'asked' => count($ids),
            'moved' => $movidas,
            'ids' => $ids,
            'tags' => $tags,
        ]);

        return $movidas;
    }

    /**
     * Que fecha lleva una pieza que sale a la calle, dado lo que ya tiene y lo que se pide.
     *
     * **Publicar es AHORA**, y por eso la fecha no se le pide a nadie: un borrador no tiene
     * `publish_at` —se sella al salir— asi que exigirsela al panel es exigirle un dato que no
     * tiene donde pedir, y era por lo que desde el editor **no se podia publicar una pieza**.
     *
     * Y pisa una fecha futura: publicar a mano una programada la saca ahora, y dejarle la
     * prevista la ordenaria manana en la portada, el sitemap y el feed.
     *
     * `scheduled` no entra: una programada sin fecha es un error de verdad, no una que haya
     * que inventar. Devuelve `null` cuando no hay nada que sellar, y quien llama deja lo que
     * hubiera.
     */
    public static function stampFor(string $status, ?string $wanted): ?string
    {
        if ($status !== SnkKilkanaPostStatusEnum::Published->value) {
            return null;
        }

        $vacia = $wanted === null || trim($wanted) === '';

        return $vacia || Carbon::parse($wanted)->isFuture() ? now()->toDateTimeString() : null;
    }

    /**
     * ¿Escribe esta petición desde una versión que ya no es la actual?
     *
     * Quien manda `expected_updated_at` dice desde qué versión escribe. Se comparan **instantes,
     * no cadenas**: la versión llega en la variante ISO que el cliente recibió, y un cambio de
     * serializador no puede convertir todos los guardados en un 409.
     *
     * Lo ilegible cuenta como desfasado, no como ausente: quien manda basura en ese campo no
     * puede acabar pisando lo que otro escribió.
     *
     * Estático y aquí, y no en el controlador, porque es la regla «desde qué versión escribes» y
     * allí no había forma de probarla sin montar una petición.
     */
    public static function staleFor(SnkKilkanaPostModel $post, mixed $expected): bool
    {
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        try {
            return $post->updated_at === null || ! Carbon::parse($expected)->equalTo($post->updated_at);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Qué significa una edición parcial que publica: rellena desde la fila lo que no viene.
     *
     * Lo que `required_if` tiene que mirar es el estado RESULTANTE, y una edición es parcial por
     * definición: publicar una pieza es `PUT {status:"published"}` y nada más. Validando solo el
     * cuerpo, la regla exige una entradilla que la fila **ya tiene** y devuelve un 422 que no
     * significa nada — y obliga al panel a reenviar campos que no está editando, que es
     * exactamente como se pisa lo que otro acaba de escribir.
     *
     * Solo rellena lo que falta: mandar la entradilla vacía a propósito sigue siendo un 422.
     *
     * Y sella la fecha, porque **publicar es AHORA**. Un borrador no tiene `publish_at` —se sella
     * al salir a la calle—, así que el relleno no tenía nada con que rellenar y `required_if`
     * rechazaba: desde el editor **no se podía publicar una pieza**. Pisa además una fecha futura
     * por lo mismo que el lote: publicar a mano una programada la saca ahora, y dejarle la
     * prevista la ordenaría mañana en la portada, el sitemap y el feed. `scheduled` no entra —una
     * programada sin fecha es un error de verdad, no una que haya que inventar—, y de eso se
     * ocupa `stampFor()`.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public static function publishDefaults(SnkKilkanaPostModel $post, array $entrada): array
    {
        if (! in_array($entrada['status'] ?? null, SnkKilkanaPostStatusEnum::publishing(), true)) {
            return $entrada;
        }

        $entrada += array_filter([
            'excerpt' => $post->excerpt,
            'publish_at' => $post->publish_at?->toDateTimeString(),
        ], fn ($v) => $v !== null);

        $sello = self::stampFor(
            (string) $entrada['status'],
            isset($entrada['publish_at']) ? (string) $entrada['publish_at'] : null,
        );

        return $sello === null ? $entrada : [...$entrada, 'publish_at' => $sello];
    }

    /**
     * Anade o quita etiquetas en tanda.
     *
     * Por NOMBRE y no por id: es lo que escribe el resto del API (`firstOrCreateByName`) y lo
     * unico que significa algo fuera de esta base. Anadir CREA la etiqueta que no exista, que
     * es como se etiqueta de verdad — la cobertura empieza antes de que nadie abra el
     * vocabulario a darla de alta.
     *
     * `syncWithoutDetaching` y no `sync`: anadir «sequia» a veinte piezas no puede quitarles
     * las que ya tenian. Quitar solo desengancha, nunca borra la fila del vocabulario: una
     * etiqueta sin usos sigue existiendo, y es la pantalla de Etiquetas la que decide si se va.
     *
     * `updated_by` se sella igual que en un cambio de estado: etiquetar es editar.
     *
     * @param  Collection<int, SnkKilkanaPostModel>  $posts
     * @param  string[]  $nombres
     */
    private function retag($posts, bool $anadir, array $nombres, int $userId): int
    {
        $ids = collect($nombres)
            ->map(fn (string $nombre) => trim($nombre))
            ->filter()
            ->unique()
            ->map(fn (string $nombre) => SnkKilkanaTagModel::firstOrCreateByName($nombre)->id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        foreach ($posts as $post) {
            $anadir ? $post->tags()->syncWithoutDetaching($ids) : $post->tags()->detach($ids);
            $post->forceFill(['updated_by' => $userId])->save();
        }

        return $posts->count();
    }

    /**
     * Publica lo programado cuya hora ya paso. Lo llama el scheduler, por tenant.
     *
     * Publicar es SOLO cambiar de estado: la fecha ya esta puesta y es la prevista. Cuando la
     * fecha prevista y la de publicacion eran dos columnas, aqui habia que copiar una en otra
     * y vaciar la primera, y ese traspaso llego a publicar piezas con la fecha en que se
     * redactaron —descolocando portada, sitemap y feed—. Sin dos columnas no hay traspaso que
     * equivocar.
     *
     * SIN transaccion, y a proposito: si una pieza falla, envolver el lote impediria salir a
     * las demas ese minuto. Publicar una a una hace que las buenas salgan y la rota lo vuelva
     * a intentar dentro de sesenta segundos, que es lo que un periodico quiere de un cron.
     */
    public function publishDue(): int
    {
        $due = SnkKilkanaPostModel::query()
            ->where('status', SnkKilkanaPostStatusEnum::Scheduled)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->get();

        // Y con entradilla. `required_if` vive en el controlador, asi que solo mira la
        // peticion: a `scheduled` se puede llegar por el lote, que no valida contenido, y
        // entonces este cron sacaria a la calle una pieza sin entradilla a las seis de la
        // manana sin que nadie lo viera. Se queda programada, que es un estado del que se
        // puede salir escribiendola.
        [$listas, $aparcadas] = $due->partition->hasExcerpt();

        foreach ($listas as $post) {
            $post->update(['status' => SnkKilkanaPostStatusEnum::Published]);
        }

        // Y se dice. Una pieza que no sale a su hora no rompe nada: simplemente no ocurre, y
        // quien la programo la ve «Programada» a mediodia sin entender por que. Esta linea es
        // la unica pista que va a existir de por que.
        if ($aparcadas->isNotEmpty()) {
            Log::warning('kilkana: piezas programadas sin entradilla, no publicadas', [
                'count' => $aparcadas->count(),
                'ids' => $aparcadas->pluck('id')->all(),
            ]);
        }

        return $listas->count();
    }

    /**
     * La otra mitad de programar: lo publicado con hora de retirada cumplida vuelve a borrador,
     * con su URL reservada como cualquier pieza que sale de la calle, y la hora se borra para
     * que volver a publicarla no la retire otra vez. Se anota en el registro, sin actor: lo hizo
     * el reloj.
     */
    public function unpublishDue(): int
    {
        $due = SnkKilkanaPostModel::query()
            ->where('status', SnkKilkanaPostStatusEnum::Published)
            ->whereNotNull('unpublish_at')
            ->where('unpublish_at', '<=', now())
            ->get();

        foreach ($due as $post) {
            $post->update(['status' => SnkKilkanaPostStatusEnum::Draft, 'unpublish_at' => null]);
        }

        return $due->count();
    }

    public function revisions(int $postId)
    {
        return SnkKilkanaPostRevisionModel::query()
            ->where('post_id', $postId)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function revision(int $postId, $revisionId): ?SnkKilkanaPostRevisionModel
    {
        return SnkKilkanaPostRevisionModel::query()
            ->whereKey($revisionId)
            ->where('post_id', $postId)
            ->first();
    }

    /** Restaurar tambien deja rastro antes de pisar: la instantanea y el post van juntas. */
    public function restore(
        SnkKilkanaPostModel $post,
        SnkKilkanaPostRevisionModel $revision,
        int $userId,
    ): SnkKilkanaPostModel {
        return DB::transaction(function () use ($post, $revision, $userId) {
            SnkKilkanaPostRevisionModel::create([
                'post_id' => $post->id,
                'user_id' => $userId,
                'payload' => $post->getAttributes(),
            ]);

            // Restaurar también mueve la URL: la versión recuperada trae su titular y por
            // tanto su slug. Sin esta contabilidad se PERDÍA la dirección que la pieza tuvo
            // entre el renombrado y la restauración —404 seco para quien la compartiera— y la
            // recuperada quedaba marcada como retirada, o sea redirigiendo hacia sí misma.
            $previousPath = $post->url_path;

            $columns = collect($revision->payload)
                ->except(['id', 'created_at', 'updated_at', 'created_by', 'updated_by'])
                ->all();
            $post->fill($columns);
            $post->updated_by = $userId;
            $post->save();
            $this->shiftUrlHistory($post, $previousPath);

            return $post->fresh();
        });
    }

    /**
     * La ruta que el artículo deja entra en el historial; la que toma sale de él.
     *
     * Lo hacen por igual el guardado y la restauración: las dos pueden mover la URL, y una
     * que se mueve sin dejar redirección es un enlace roto en cada sitio donde estuviera
     * compartida.
     *
     * El borrado va acotado **por artículo**: sin eso, adoptar una URL que otro había dejado
     * borraba la redirección de aquel y sus lectores acababan en esta pieza. Que el modelo ya
     * no deje adoptarla no quita el cinturón — si el dato viejo trae una fila así, esto no la
     * toca.
     */
    private function shiftUrlHistory(SnkKilkanaPostModel $post, ?string $previousPath): void
    {
        if ($previousPath && $post->url_path !== $previousPath) {
            SnkKilkanaPostSlugModel::updateOrCreate(['path' => $previousPath], ['post_id' => $post->id]);
        }

        if ($post->url_path) {
            SnkKilkanaPostSlugModel::query()
                ->where('path', $post->url_path)
                ->where('post_id', $post->id)
                ->delete();
        }
    }

    private function scopeToOwner(Builder $query, int $userId, bool $canEditOthers): Builder
    {
        return $canEditOthers ? $query : $query->where('created_by', $userId);
    }

    /**
     * La portada llega como `cover_media_id` ya validado contra la mediateca y pasa tal cual:
     * el editor elige entre lo que hay, y un id que no existe se rechaza antes de llegar aqui.
     */
    private function columnsFrom(array $data): array
    {
        $columns = collect($data)->except(['category', 'tags'])->all();

        if (array_key_exists('category', $data)) {
            $columns['category_id'] = SnkKilkanaCategoryModel::query()
                ->where('slug', $data['category'])
                ->value('id');
        }
        if (array_key_exists('body_blocks', $columns)) {
            $columns['body_blocks'] = SnkKilkanaRichText::cleanBlocks($columns['body_blocks']);
        }

        return $columns;
    }

    private function syncRelations(SnkKilkanaPostModel $post, array $data): void
    {
        if (! array_key_exists('tags', $data)) {
            return;
        }

        $ids = collect($data['tags'] ?? [])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreateByName($name)->id);
        $post->tags()->sync($ids);
    }
}
