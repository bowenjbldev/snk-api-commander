<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Snk\Kilkana\Support\SnkKilkanaRichText;

/**
 * CRUD de artículos con BD normalizada. La escritura acepta texto libre del editor
 * (category=slug existente, image=url, tags=nombres) y el server resuelve/crea las
 * relaciones; la FIRMA no, que viaja como `author_id` de una fila existente porque se
 * gobierna en su propia pantalla. La lectura sale anidada (category/author/cover/tags/meta,
 * serialización del modelo). Solo claves de meta conocidas pasan la validación.
 */
#[Group('Posts')]
class SnkKilkanaPostsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaPostModel::class;

    /**
     * Vistas reales agregadas también en el PANEL, no solo en la salida pública.
     *
     * Tres ventanas: hoy, últimos siete días y total histórico. Aquí hubo una columna
     * `posts.reads` que nada incrementaba y que el panel pintaba como si fuera audiencia;
     * `views_total` es la misma pregunta contestada con los datos de verdad. Mismos alias que
     * `SnkKilkanaPublicController::posts()`, para que quien lea vea un único contrato.
     */
    protected function newQuery()
    {
        $query = SnkKilkanaPostModel::query()
            ->withSum(['views as views_day' => fn ($q) => $q->where('date', now()->toDateString())], 'views')
            ->withSum(['views as views_week' => fn ($q) => $q->where('date', '>=', now()->subDays(6)->toDateString())], 'views')
            ->withSum('views as views_total', 'views');

        return $this->scopeToOwner($query);
    }

    /**
     * Sin `edit others posts`, para ti solo existe lo que has escrito.
     *
     * Mira `created_by`, NO la firma: en una redacción quien teclea y quien firma pueden
     * ser distintos —una pieza de agencia firmada por «Redacción», un becario que escribe
     * bajo la firma de su sección—, y el permiso va con quien la escribió.
     */
    private function scopeToOwner($query)
    {
        if (! auth()->user()?->can('edit others posts')) {
            $query->where('created_by', auth()->id());
        }

        return $query;
    }

    /** Una pieza ajena no existe: 404, no 403 — el id es correlativo y un 403 confirmaría cuántas hay. */
    protected function findModel($id, bool $applyWith = false): ?Model
    {
        $post = parent::findModel($id, $applyWith);
        if ($post === null || auth()->user()?->can('edit others posts')) {
            return $post;
        }

        return (int) $post->created_by === (int) auth()->id() ? $post : null;
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
    public function __construct()
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
        'tags.*' => 'string|max:64',
        // Sin `unique`: la unicidad es la pareja (url_date, slug) y la resuelve el modelo al
        // publicar. Una regla sobre el slug suelto rechazaría el titular que vuelve otro año.
        'slug' => 'nullable|string|max:255',
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
        'tags.*' => 'string|max:64',
        'slug' => 'nullable|string|max:255',
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

        $post = SnkKilkanaPostModel::create([
            ...$this->columnsFrom($data),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        $this->syncRelations($post, $data);

        return response()->json($post->fresh(), 201);
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

        // Historial de versiones: snapshot de las columnas ANTES de aplicar el cambio.
        SnkKilkanaPostRevisionModel::create([
            'post_id' => $post->id,
            'user_id' => auth()->id(),
            'payload' => $post->getAttributes(),
        ]);

        // La RUTA anterior, no el slug: la URL lleva fecha, y comparar solo el slug dejaría
        // sin enterrar cualquier movimiento de la fecha.
        $previousPath = $post->url_path;
        $post->fill($this->columnsFrom($data, $request));
        $post->updated_by = auth()->id();
        $post->save();
        $this->syncRelations($post, $data);

        // Historial: la URL vieja hará 301; recuperar una ruta la saca del historial, o
        // quedaría a la vez como URL actual y como redirección hacia sí misma.
        if ($previousPath && $post->url_path !== $previousPath) {
            SnkKilkanaPostSlugModel::updateOrCreate(['path' => $previousPath], ['post_id' => $post->id]);
        }
        if ($post->url_path) {
            SnkKilkanaPostSlugModel::query()->where('path', $post->url_path)->delete();
        }

        return response()->json($post->fresh());
    }

    /**
     * Acción masiva sobre varios artículos: cambio de estado o borrado.
     *
     * Un endpoint y no N peticiones desde el panel: publicar veinte eran veinte PUT, cada
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

        // El acotado también aquí: en masa es donde más barato sale saltárselo.
        $query = $this->scopeToOwner(SnkKilkanaPostModel::query())->whereIn('id', $data['ids']);
        if ($data['action'] === 'delete') {
            $count = $query->count();
            $query->delete();

            return response()->json(['ok' => true, 'count' => $count]);
        }

        $count = 0;
        foreach ($query->get() as $post) {
            $post->status = $data['action'] === 'publish'
                ? SnkKilkanaPostStatusEnum::Published
                : SnkKilkanaPostStatusEnum::Draft;
            if ($data['action'] === 'publish') {
                if ($post->publish_at === null || $post->publish_at->isFuture()) {
                    $post->publish_at = now();
                }
            }
            $post->updated_by = auth()->id();
            $post->save();
            $count++;
        }

        return response()->json(['ok' => true, 'count' => $count]);
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

        return response()->json(
            SnkKilkanaPostRevisionModel::query()
                ->where('post_id', $id)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->get()
        );
    }

    /** Restaura una revisión: el estado actual se snapshotea antes (restore reversible). */
    public function restore($id, $revisionId): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $post = $this->findModel($id);
        $revision = SnkKilkanaPostRevisionModel::query()->whereKey($revisionId)->where('post_id', $id)->first();
        if (! $post || ! $revision) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        // Restaurar una versión publicada es publicar: la puerta de atrás del muro.
        if ($this->deniesPublish($revision->payload['status'] ?? null)) {
            return $this->forbidPublish();
        }

        SnkKilkanaPostRevisionModel::create([
            'post_id' => $post->id,
            'user_id' => auth()->id(),
            'payload' => $post->getAttributes(),
        ]);

        $columns = collect($revision->payload)
            ->except(['id', 'created_at', 'updated_at', 'created_by', 'updated_by'])
            ->all();
        $post->fill($columns);
        $post->updated_by = auth()->id();
        $post->save();

        return response()->json($post->fresh());
    }

    /** Payload del editor → columnas: resuelve category/author/image a FKs; el resto pasa tal cual. */
    private function columnsFrom(array $data, ?Request $request = null): array
    {
        $columns = collect($data)
            ->except(['category', 'image', 'tags'])
            ->all();

        if (array_key_exists('category', $data)) {
            $columns['category_id'] = SnkKilkanaCategoryModel::query()
                ->where('slug', $data['category'])
                ->value('id');
        }
        // La firma llega ya resuelta —`author_id` pasa tal cual, como una columna más—: la
        // elige el editor de una lista gobernada y quien la identifica es su cuenta. Antes
        // esto era `firstOrCreate(['name' => …])`, así que cada variante escrita del nombre
        // —«J. Bowen» / «Jorge Bowen»— abría una firma nueva, con su página pública, y
        // partía la hemeroteca en dos. Aquí ya no se crea ninguna: `exists` deja el 422 a
        // la vista en vez de inventar una firma en silencio.
        // image: presente y null limpia la portada; ausente no la toca (PUT parcial).
        $imageSent = $request ? $request->exists('image') : array_key_exists('image', $data);
        if ($imageSent) {
            $url = $data['image'] ?? null;
            $columns['cover_media_id'] = $url
                ? SnkKilkanaMediaModel::firstOrCreateByUrl($url)->id
                : null;
        }

        // Saneado del HTML rico en la frontera de escritura (defensa en profundidad + SSR
        // fiable). Solo bloques: `body` ya no existe como columna ni como entrada aceptada.
        if (array_key_exists('body_blocks', $columns)) {
            $columns['body_blocks'] = SnkKilkanaRichText::cleanBlocks($columns['body_blocks']);
        }

        return $columns;
    }

    /** Tags: sync por nombre tras guardar las columnas. */
    private function syncRelations(SnkKilkanaPostModel $post, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            $ids = collect($data['tags'] ?? [])
                ->map(fn (string $name) => trim($name))
                ->filter()
                ->unique()
                ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreate(['name' => $name])->id);
            $post->tags()->sync($ids);
        }
    }
}
