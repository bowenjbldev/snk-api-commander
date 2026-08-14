<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaPostService;

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

    /** Una pieza ajena no existe: 404, no 403 — el id es correlativo y un 403 confirmaría cuántas hay. */
    protected function findModel($id, bool $applyWith = false): ?Model
    {
        return $this->posts->find($id, (int) auth()->id(), $this->canEditOthers());
    }

    private function canEditOthers(): bool
    {
        return auth()->user()?->can('edit others posts') === true;
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
    public function __construct(private readonly SnkKilkanaPostService $posts)
    {
        $this->validationRulesStore['status'] = 'required|'.SnkKilkanaPostStatusEnum::rule();
        $this->validationRulesUpdate['status'] = 'sometimes|required|'.SnkKilkanaPostStatusEnum::rule();
        foreach (['validationRulesStore', 'validationRulesUpdate'] as $reglas) {
            $this->{$reglas}['content_tier'] = 'nullable|'.SnkKilkanaContentTierEnum::rule();
        }
    }

    protected array $validationRulesStore = [
        'title' => 'required|string|max:500',
        'excerpt' => 'required|string',
        'category' => 'required|string|exists:kilkana_categories,slug',
        'author_id' => 'required|integer|exists:kilkana_authors,id',
        'publish_at' => 'required|date',
        'image' => 'nullable|string|max:2048',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.type' => 'required|in:text,quote,image,gallery,video,paywall,divider',
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
        'excerpt' => 'sometimes|required|string',
        'category' => 'sometimes|required|string|exists:kilkana_categories,slug',
        'author_id' => 'sometimes|required|integer|exists:kilkana_authors,id',
        'publish_at' => 'sometimes|required|date',
        'image' => 'nullable|string|max:2048',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.type' => 'required|in:text,quote,image,gallery,video,paywall,divider',
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

        $rules = $this->interpolateRules($this->validationRulesUpdate, $id);
        $validator = validator($request->all(), $rules, $this->validationMessages);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $data = $validator->validated();
        if ($this->deniesPublish($data['status'] ?? null)) {
            return $this->forbidPublish();
        }

        return response()->json($this->posts->update(
            $post,
            $data,
            (int) auth()->id(),
            $request->exists('image'),
        ));
    }

    /**
     * Acción masiva sobre varios artículos: cambio de estado o borrado.
     *
     * Un endpoint y no N peticiones: publicar veinte eran veinte PUT, cada
     * uno con su carrera y su fallo parcial. `publish` sella `publish_at` cuando falta o
     * cuando apunta al futuro: publicar a mano una pieza programada la saca AHORA, y dejarle
     * la fecha prevista la ordenaría mañana en la portada, el sitemap y el feed.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'action' => 'required|in:publish,draft,delete',
        ]);

        $permission = $data['action'] === 'delete' ? $this->destroyPermission : $this->updatePermission;
        if ($response = $this->requirePermission($permission)) {
            return $response;
        }
        if ($data['action'] === 'publish' && ! auth()->user()?->can('publish posts')) {
            return $this->forbidPublish();
        }

        return response()->json([
            'ok' => true,
            'count' => $this->posts->bulk(
                $data['ids'],
                $data['action'],
                (int) auth()->id(),
                $this->canEditOthers(),
            ),
        ]);
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

        return response()->json($this->posts->restore($post, $revision, (int) auth()->id()));
    }
}
