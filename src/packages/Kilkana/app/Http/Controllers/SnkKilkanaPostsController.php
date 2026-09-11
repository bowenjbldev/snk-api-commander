<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaBlockTypeEnum;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaPostResource;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Packages\Kilkana\Services\SnkKilkanaPublicService;

/**
 * CRUD de artículos con BD normalizada. La escritura acepta texto libre del editor
 * (category=slug existente, image=url, tags=nombres) y el server resuelve/crea las
 * relaciones; la FIRMA no, que viaja como `author_id` de una fila existente porque se
 * gobierna como agregado propio. La lectura sale anidada (category/author/cover/tags/meta,
 * serialización del modelo). Solo claves de meta conocidas pasan la validación.
 */
#[Group('Posts')]
class SnkKilkanaPostsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaPostModel::class;

    protected int $perPageDefault = SnkKilkanaPostService::PER_PAGE;

    protected int $perPageMax = SnkKilkanaPostService::PER_PAGE_MAX;

    protected array $allowedFields = SnkKilkanaPostService::FIELDS;

    protected array $allowedIncludes = SnkKilkanaPostService::INCLUDES;

    protected ?string $resourceClass = SnkKilkanaPostResource::class;

    /**
     * Vistas reales agregadas también en la API editorial, no solo en la salida pública.
     *
     * Tres ventanas: hoy, últimos siete días y total histórico. Aquí hubo una columna
     * `posts.reads` que nada incrementaba y se presentaba como si fuera audiencia;
     * `views_total` es la misma pregunta contestada con los datos de verdad. Mismos alias que
     * `SnkKilkanaPublicController::posts()`, para que quien lea vea un único contrato.
     */
    protected function newQuery()
    {
        return $this->posts->query((int) auth()->id(), $this->canEditOthers());
    }

    /**
     * Una pieza ajena no existe: 404, no 403 — el id es correlativo y un 403 confirmaría cuántas hay.
     *
     * Con `$applyWith` va por `queryBuilder()`, que arranca de `newQuery()` —o sea, ya acotado
     * por propiedad— y encima aplica lo que la query pida. Es lo que hace que `?include=` y
     * `?fields=` lleguen también a la ficha, y no solo al listado. Sin él, la ficha del editor
     * salía sin relaciones en cuanto el modelo dejó de cargarlas solo.
     */
    protected function findModel($id, bool $applyWith = false): ?Model
    {
        return $applyWith
            ? $this->queryBuilder()->find($id)
            : $this->posts->find($id, (int) auth()->id(), $this->canEditOthers());
    }

    private function canEditOthers(): bool
    {
        return auth()->user()?->can('edit others posts') === true;
    }

    /**
     * Saber que una pieza ajena existe, que **no es lo mismo que poder tocarla**.
     *
     * Un solo permiso contestaba las dos preguntas, y por eso el sumario de la redaccion
     * —cuanto hay sin publicar y en que fase— viajaba tambien a quien escribe desde fuera.
     * La linea no es «puede editar / no puede editar», es plantilla contra fuera: dentro de
     * la sala se sabe en que anda el resto.
     */
    private function canViewOthers(): bool
    {
        return auth()->user()?->can('view others posts') === true;
    }

    /**
     * El muro entre escribir y publicar: quien firma no decide cuándo sale.
     *
     * `draft` y `review` los mueve cualquiera que pueda editar la pieza; pasarla a
     * publicada o programada exige `publish posts`.
     */
    private function deniesPublish(?string $status): bool
    {
        $estado = SnkKilkanaPostStatusEnum::tryFrom((string) $status);

        return $estado?->requiresPublishPermission() === true
            && ! auth()->user()?->can('publish posts');
    }

    private function forbidPublish(): JsonResponse
    {
        return response()->json(['error' => 'Forbidden', 'permission' => 'publish posts'], 403);
    }

    /** Las cifras de las pestañas, que cuentan el archivo entero y no la página. */
    protected array $allowedSummary = ['counts'];

    protected string $indexPermission = 'index posts';

    protected string $showPermission = 'show posts';

    protected string $storePermission = 'create posts';

    protected string $updatePermission = 'update posts';

    protected string $destroyPermission = 'delete posts';

    /**
     * `status` no está en los arrays de abajo y no puede estarlo: la inicialización de una
     * propiedad solo admite expresiones constantes, y su regla sale de un método del enum.
     * Aquí es donde las dos listas se juntan, y donde un estado nuevo entra sin tocar nada.
     */
    /**
     * Los tres por el constructor y no con `app()` dentro del método.
     *
     * `SnkKilkanaActivityService` es singleton, así que el `quiet()` y el `log()` de una
     * restauración hablan del mismo objeto; pedirlo dos veces al contenedor funcionaba por eso
     * y no porque estuviera escrito así a propósito.
     */
    public function __construct(
        private readonly SnkKilkanaPostService $posts,
        private readonly SnkKilkanaPublicService $calle,
        private readonly SnkKilkanaActivityService $registro,
    ) {
        $this->validationRulesStore['status'] = 'required|'.SnkKilkanaPostStatusEnum::rule();
        $this->validationRulesUpdate['status'] = 'sometimes|required|'.SnkKilkanaPostStatusEnum::rule();

        // Lo que hace falta para SALIR, no para existir. Un borrador se crea con el titular y
        // poco mas —el texto llega despues—, pero publicarlo sin entradilla ni fecha es
        // publicar media pieza. La lista de estados sale del propio enum.
        $alPublicar = 'required_if:status,'.implode(',', SnkKilkanaPostStatusEnum::publishing());

        foreach (['validationRulesStore', 'validationRulesUpdate'] as $reglas) {
            $this->{$reglas}['content_tier'] = 'nullable|'.SnkKilkanaContentTierEnum::rule();
            $this->{$reglas}['body_blocks.*.type'] = 'required|'.SnkKilkanaBlockTypeEnum::rule();
            $this->{$reglas}['excerpt'] = $alPublicar.'|nullable|string';
            $this->{$reglas}['publish_at'] = $alPublicar.'|nullable|date';
            // La retirada programada: nula es «no se retira»; con fecha, siempre por delante.
            $this->{$reglas}['unpublish_at'] = 'sometimes|nullable|date|after:now';
        }
    }

    protected array $validationRulesStore = [
        'title' => 'required|string|max:500',
        'category' => 'required|string|exists:kilkana_categories,slug',
        'author_id' => 'required|integer|exists:kilkana_authors,id',
        'cover_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.config' => 'nullable|array',
        'tags' => 'nullable|array',
        'tags.*' => 'string|max:'.SnkKilkanaTagModel::NAME_MAX,
        // Sin `unique`: la unicidad es la pareja (url_date, slug) y la resuelve el modelo al
        // publicar. Una regla sobre el slug suelto rechazaría el titular que vuelve otro año.
        'slug' => 'nullable|string|max:'.SnkKilkanaPostModel::SLUG_MAX,
        'featured' => 'nullable|boolean',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
        'og_title' => 'nullable|string|max:500',
        'og_description' => 'nullable|string',
        'og_image_id' => 'nullable|integer|exists:kilkana_media,id',
        // Absoluta a la fuerza: una canonical relativa no dice a qué dominio apunta, y esta
        // columna existe justo para señalar a OTRO sitio.
        'canonical_url' => 'nullable|url|max:2048',
        'noindex' => 'nullable|boolean',
    ];

    protected array $validationRulesUpdate = [
        'title' => 'sometimes|required|string|max:500',
        'category' => 'sometimes|required|string|exists:kilkana_categories,slug',
        'author_id' => 'sometimes|required|integer|exists:kilkana_authors,id',
        'cover_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.config' => 'nullable|array',
        'tags' => 'nullable|array',
        'tags.*' => 'string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaPostModel::SLUG_MAX,
        'featured' => 'nullable|boolean',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
        'og_title' => 'nullable|string|max:500',
        'og_description' => 'nullable|string',
        'og_image_id' => 'nullable|integer|exists:kilkana_media,id',
        // Absoluta a la fuerza: una canonical relativa no dice a qué dominio apunta, y esta
        // columna existe justo para señalar a OTRO sitio.
        'canonical_url' => 'nullable|url|max:2048',
        'noindex' => 'nullable|boolean',
    ];

    /**
     * El listado editorial: paginado, filtrado y con las cifras de las pestañas.
     *
     * Sustituye al `index()` del controlador base, que devuelve `->get()` pelado. No se
     * cambia allí porque lo comparten Commander, DemoApp y el resto de recursos: lo que
     * aquí se concreta es el listado de artículos, que es el único con quinientas filas,
     * cuatro relaciones cargadas y tres sumas de vistas por pieza.
     */
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        // Sin parámetro: la firma la fija `CoreBaseApiController::index()`, que la comparten
        // todos los recursos. La petición se pide al contenedor, que es lo mismo que inyectarla.
        //
        // El orden de los tres pasos es el que importa: `newQuery()` acota por propiedad,
        // `queryBuilder()` recorta columnas y carga relaciones con lo que pida la query, y la
        // suma de audiencia va **la última**, porque el `select()` de `?fields=` borraría su
        // subconsulta. Ver `SnkKilkanaPostService::withAudience()`.
        $page = $this->posts->page(
            $this->posts->withAudience(
                $this->queryBuilder(),
                SnkKilkanaPostService::readsWindow(request()->query('reads')),
            ),
            $this->listFilters(request()),
            $this->perPage(),
        );

        // `counts` es lo que ve quien mira, que es lo que cuentan las pestanas de Posts.
        // `counts_all` es el periodico entero, y el tablero las ensena juntas: sin la
        // segunda su cifra queda al lado de una seccion del periodico entero y se lee como
        // una contradiccion. Sin `view others posts` las dos vienen iguales — el servicio
        // las iguala, no las calla, porque el panel deduce de esa igualdad que hay una
        // sola escala que dibujar.
        //
        // **Y solo si se piden.** Cuentan el archivo entero, no la página, así que no son
        // parte de esta respuesta: son otra pregunta. Tres pantallas del panel piden este
        // listado solo para enseñar «lo último publicado» —secciones, etiquetas, firmas— y
        // pagaban el recuento sin usarlo.
        $cifras = $this->wants('counts')
            ? $this->posts->countsByStatus((int) auth()->id(), $this->canEditOthers(), $this->canViewOthers())
            : null;

        return response()->json([
            'data' => $this->asResourceCollection($page->getCollection()),
            'meta' => $this->paginationMeta($page),
            ...$cifras === null ? [] : ['counts' => $cifras['scoped'], 'counts_all' => $cifras['all']],
        ]);
    }

    /**
     * La query es una frontera de entrada, y se lee validando.
     *
     * Un valor desconocido **cae al de por defecto**; no devuelve 422 ni vacía la tabla.
     * Es la misma regla que aplica el panel al leer sus propios `searchParams`: quien
     * teclea `?status=banana` a mano se merece la lista entera, no una pantalla en blanco.
     */
    private function listFilters(Request $request): array
    {
        $term = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        // **Un parámetro para una idea.** `sort=-date` es descendente y `sort=date`
        // ascendente, que es como lo escriben JSON:API y el resto del gremio. Antes eran dos,
        // `sort` y `dir`, y ninguno de los dos decía por sí solo cómo iba a salir la lista.
        //
        // La clave es un alias —`date`, `title`, `reads`— y no el nombre de la columna: lo que
        // impide que quien llama nombre columnas reales, que en un API multi-inquilino es justo
        // lo que no se quiere. Lo que no esté en el vocabulario cae al orden por defecto.
        $pedido = (string) $request->query('sort', '');
        $clave = ltrim($pedido, '-');
        $conocida = array_key_exists($clave, SnkKilkanaPostService::SORTS);

        return [
            'status' => SnkKilkanaPostStatusEnum::tryFrom((string) $request->query('status', ''))?->value,
            // Una sección que no existe se **ignora**, no filtra a cero: dejar la tabla vacía
            // sin decir por qué es exactamente lo que la validación en la frontera evita.
            'category' => $category === '' ? null : $this->posts->knownCategory($category),
            // Con una sola letra la búsqueda no acota nada y recorre la tabla entera.
            'q' => mb_strlen($term) >= 2 ? mb_substr($term, 0, 128) : null,
            'sort' => $conocida ? $clave : 'date',
            'dir' => $conocida && ! str_starts_with($pedido, '-') ? 'asc' : 'desc',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $validator = validator($request->all(), $this->validationRulesStore, $this->validationMessages);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $data = $validator->validated();
        if ($this->deniesPublish($data['status'] ?? null)) {
            return $this->forbidPublish();
        }

        return response()->json($this->posts->create($data, (int) auth()->id()), 201);
    }

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        if ($id === null) {
            return response()->json(['error' => 'Missing id'], 422);
        }

        // findModel, no query(): acota a lo tuyo cuando no puedes editar lo de otros.
        $post = $this->findModel($id);
        if (! $post) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        // Bloqueo optimista: quien manda `expected_updated_at` dice desde qué versión escribe, y
        // si la pieza cambió por debajo se le devuelve quién y cuándo en vez de pisarlo.
        if (SnkKilkanaPostService::staleFor($post, $request->input('expected_updated_at'))) {
            $post->loadMissing('updater:id,name');

            return response()->json([
                'error' => 'stale',
                'updated_at' => $post->updated_at?->toIso8601String(),
                'updated_by' => $post->updater?->name,
            ], 409);
        }

        // Una edición parcial que publica se valida contra el estado RESULTANTE, no contra lo que
        // llegó: la regla está en el servicio, que es donde se puede probar sin una petición.
        $entrada = SnkKilkanaPostService::publishDefaults($post, $request->all());

        $rules = $this->interpolateRules($this->validationRulesUpdate, $id);
        $validator = validator($entrada, $rules, $this->validationMessages);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $data = $validator->validated();
        if ($this->deniesPublish($data['status'] ?? null)) {
            return $this->forbidPublish();
        }

        return response()->json($this->posts->update($post, $data, (int) auth()->id()));
    }

    /**
     * Acción masiva sobre varios artículos: cambio de estado, etiquetado o borrado.
     *
     * Un endpoint y no N peticiones: publicar veinte eran veinte PUT, cada
     * uno con su carrera y su fallo parcial. `publish` sella `publish_at` cuando falta o
     * cuando apunta al futuro: publicar a mano una pieza programada la saca AHORA, y dejarle
     * la fecha prevista la ordenaría mañana en la portada, el sitemap y el feed.
     *
     * **Etiquetar entra aquí por lo mismo.** Reetiquetar es lo que más se hace en tanda
     * después de publicar —una cobertura entera comparte etiquetas—, y hacerlo con N `PUT`
     * obliga a que el cliente mande las etiquetas RESULTANTES de cada pieza, o sea a que
     * calcule la unión con lo que ya tenían. Ese cálculo lo hace bien quien tiene las filas
     * delante, y quien las tiene es este lado.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Con techo, y el mismo que el de la página: el lote lo dispara la barra de
            // selección, que solo puede marcar lo que tiene delante. Sin `max`, un `?/bulk`
            // con cincuenta mil ids entra entero en la transacción y hace cincuenta mil
            // `save()` dentro de ella.
            'ids' => 'required|array|min:1|max:'.SnkKilkanaPostService::PER_PAGE_MAX,
            'ids.*' => 'integer',
            // Los cinco que ofrece la barra de selección del panel, más los dos del
            // reetiquetado. Faltaban `review` y `scheduled`, así que dos de sus botones no
            // tenían endpoint y el panel habría tenido que mandar N peticiones — justo lo
            // que este existe para evitar.
            'action' => 'required|in:publish,draft,review,scheduled,delete,tag,untag',
            // Nombres y no ids: es lo que el backend ya escribe (`firstOrCreateByName`) y lo
            // que un humano lee. Un id de etiqueta no significa nada fuera de esta base.
            'tags' => 'required_if:action,tag,untag|array|min:1|max:'.SnkKilkanaPostService::PER_PAGE_MAX,
            'tags.*' => 'string|max:'.SnkKilkanaTagModel::NAME_MAX,
        ]);

        $permission = $data['action'] === 'delete' ? $this->destroyPermission : $this->updatePermission;
        if ($response = $this->requirePermission($permission)) {
            return $response;
        }
        if ($this->deniesPublish(SnkKilkanaPostStatusEnum::fromBulkAction($data['action'])?->value)) {
            return $this->forbidPublish();
        }

        $movidas = $this->posts->bulk(
            $data['ids'],
            $data['action'],
            (int) auth()->id(),
            $this->canEditOthers(),
            $data['tags'] ?? [],
        );

        return response()->json(['ok' => true, 'count' => $movidas]);
    }

    /** Historial de versiones de un post (más reciente primero). */
    public function revisions($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        // El historial cuenta el contenido entero de la pieza: se mira con las mismas
        // llaves que la pieza.
        if ($this->findModel($id) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($this->posts->revisions((int) $id));
    }

    /**
     * Un enlace de previa para quien no tiene sesión: firmado por el periódico y con una
     * semana de vida. Se mira con las mismas llaves que la pieza, y la ruta es la de la
     * calle, que es donde se abre.
     */
    public function previewLink($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }
        if ($this->findModel($id) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        // Un solo instante y no dos: con `now()->addWeek()` llamado dos veces, una petición que
        // cruce el segundo firma una caducidad y anuncia otra.
        $caduca = now()->addWeek();
        $firma = $this->calle->previewSignature((int) $id, $caduca->getTimestamp());

        return response()->json([
            'path' => "/preview/{$id}?until={$caduca->getTimestamp()}&sig={$firma}",
            'expires_at' => $caduca->toIso8601String(),
        ]);
    }

    /** Restaura una revisión: el estado actual se snapshotea antes (restore reversible). */
    public function restore($id, $revisionId): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $post = $this->findModel($id);
        $revision = $this->posts->revision((int) $id, $revisionId);
        if (! $post || ! $revision) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        // Restaurar una versión publicada es publicar: la puerta de atrás del muro.
        if ($this->deniesPublish($revision->payload['status'] ?? null)) {
            return $this->forbidPublish();
        }

        // Callado: restaurar guarda la pieza, y «editó» no es lo que pasó. El servicio es
        // singleton, así que el `quiet()` y el `log()` de después hablan del mismo.
        $restaurada = $this->registro->quiet(fn () => $this->posts->restore($post, $revision, (int) auth()->id()));
        $this->registro->log('restore', 'version_restored', $post->title, '/posts');

        return response()->json($restaurada);
    }
}
