<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostMetaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Snk\Kilkana\Support\SnkKilkanaRichText;

/**
 * CRUD de artículos con BD normalizada. La escritura acepta texto libre del editor
 * (category=slug existente, author=nombre, image=url, tags=nombres) y el server
 * resuelve/crea las relaciones; la lectura sale anidada (category/author/cover/tags/meta,
 * serialización del modelo). Solo claves de meta conocidas pasan la validación.
 */
#[Group('Posts')]
class SnkKilkanaPostsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaPostModel::class;

    protected string $indexPermission = 'index posts';

    protected string $showPermission = 'show posts';

    protected string $storePermission = 'create posts';

    protected string $updatePermission = 'update posts';

    protected string $destroyPermission = 'delete posts';

    protected array $validationRulesStore = [
        'title' => 'required|string|max:500',
        'excerpt' => 'required|string',
        'category' => 'required|string|exists:kilkana_categories,slug',
        'author' => 'required|string|max:255',
        'published_at' => 'required|date',
        'reads' => 'nullable|integer|min:0',
        'image' => 'nullable|string|max:2048',
        'status' => 'required|in:published,draft,scheduled',
        'body' => 'nullable|string',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.type' => 'required|in:text,quote,image,gallery,video,paywall,divider',
        'body_blocks.*.config' => 'nullable|array',
        'tags' => 'nullable|array',
        'tags.*' => 'string|max:64',
        'slug' => 'nullable|string|max:255|unique:kilkana_posts,slug',
        'featured' => 'nullable|boolean',
        'scheduled_for' => 'nullable|date',
        'meta' => 'nullable|array:seo_title,seo_description',
        'meta.seo_title' => 'nullable|string|max:500',
        'meta.seo_description' => 'nullable|string',
    ];

    protected array $validationRulesUpdate = [
        'title' => 'sometimes|required|string|max:500',
        'excerpt' => 'sometimes|required|string',
        'category' => 'sometimes|required|string|exists:kilkana_categories,slug',
        'author' => 'sometimes|required|string|max:255',
        'published_at' => 'sometimes|required|date',
        'reads' => 'nullable|integer|min:0',
        'image' => 'nullable|string|max:2048',
        'status' => 'sometimes|required|in:published,draft,scheduled',
        'body' => 'nullable|string',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.type' => 'required|in:text,quote,image,gallery,video,paywall,divider',
        'body_blocks.*.config' => 'nullable|array',
        'tags' => 'nullable|array',
        'tags.*' => 'string|max:64',
        'slug' => 'nullable|string|max:255|unique:kilkana_posts,slug,{id}',
        'featured' => 'nullable|boolean',
        'scheduled_for' => 'nullable|date',
        'meta' => 'nullable|array:seo_title,seo_description',
        'meta.seo_title' => 'nullable|string|max:500',
        'meta.seo_description' => 'nullable|string',
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

        $post = SnkKilkanaPostModel::query()->find($id);
        if (! $post) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $rules = $this->interpolateRules($this->validationRulesUpdate, $id);
        $validator = validator($request->all(), $rules, $this->validationMessages);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $data = $validator->validated();

        // Historial de versiones: snapshot de las columnas ANTES de aplicar el cambio.
        SnkKilkanaPostRevisionModel::create([
            'post_id' => $post->id,
            'user_id' => auth()->id(),
            'payload' => $post->getAttributes(),
        ]);

        $previousSlug = $post->slug;
        $post->fill($this->columnsFrom($data, $request));
        $post->updated_by = auth()->id();
        $post->save();
        $this->syncRelations($post, $data);

        // Historial de slugs: la URL vieja hará 301; recuperar un slug lo saca del historial.
        if ($previousSlug && $post->slug !== $previousSlug) {
            SnkKilkanaPostSlugModel::updateOrCreate(['slug' => $previousSlug], ['post_id' => $post->id]);
        }
        if ($post->slug) {
            SnkKilkanaPostSlugModel::query()->where('slug', $post->slug)->delete();
        }

        return response()->json($post->fresh());
    }

    /** Historial de versiones de un post (más reciente primero). */
    public function revisions($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
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

        $post = SnkKilkanaPostModel::query()->find($id);
        $revision = SnkKilkanaPostRevisionModel::query()->whereKey($revisionId)->where('post_id', $id)->first();
        if (! $post || ! $revision) {
            return response()->json(['error' => 'Record not found'], 404);
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
            ->except(['category', 'author', 'image', 'tags', 'meta'])
            ->all();

        if (array_key_exists('category', $data)) {
            $columns['category_id'] = SnkKilkanaCategoryModel::query()
                ->where('slug', $data['category'])
                ->value('id');
        }
        if (array_key_exists('author', $data)) {
            $name = trim($data['author']);
            $columns['author_id'] = SnkKilkanaAuthorModel::firstOrCreate(['name' => $name])->id;
        }
        // image: presente y null limpia la portada; ausente no la toca (PUT parcial).
        $imageSent = $request ? $request->exists('image') : array_key_exists('image', $data);
        if ($imageSent) {
            $url = $data['image'] ?? null;
            $columns['cover_media_id'] = $url
                ? SnkKilkanaMediaModel::firstOrCreateByUrl($url)->id
                : null;
        }

        // Saneado del HTML rico en la frontera de escritura (defensa en profundidad + SSR fiable).
        if (array_key_exists('body', $columns)) {
            $columns['body'] = SnkKilkanaRichText::clean($columns['body']);
        }
        if (array_key_exists('body_blocks', $columns)) {
            $columns['body_blocks'] = SnkKilkanaRichText::cleanBlocks($columns['body_blocks']);
        }

        return $columns;
    }

    /** Tags (sync por nombre) y meta (upsert de claves validadas) tras guardar columnas. */
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

        if (array_key_exists('meta', $data)) {
            foreach ($data['meta'] ?? [] as $key => $value) {
                if ($value === null || $value === '') {
                    SnkKilkanaPostMetaModel::query()->where('post_id', $post->id)->where('meta_key', $key)->delete();
                } else {
                    SnkKilkanaPostMetaModel::updateOrCreate(
                        ['post_id' => $post->id, 'meta_key' => $key],
                        ['meta_value' => $value],
                    );
                }
            }
        }
    }
}
