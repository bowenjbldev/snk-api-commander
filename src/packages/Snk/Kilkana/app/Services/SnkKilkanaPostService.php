<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Packages\Snk\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Snk\Kilkana\Support\SnkKilkanaRichText;

class SnkKilkanaPostService
{
    public function query(int $userId, bool $canEditOthers): Builder
    {
        return $this->scopeToOwner(
            SnkKilkanaPostModel::query()
                ->withSum(['views as views_day' => fn ($query) => $query->where('date', now()->toDateString())], 'views')
                ->withSum(['views as views_week' => fn ($query) => $query->where('date', '>=', now()->subDays(6)->toDateString())], 'views')
                ->withSum('views as views_total', 'views'),
            $userId,
            $canEditOthers,
        );
    }

    public function find($id, int $userId, bool $canEditOthers): ?SnkKilkanaPostModel
    {
        $post = SnkKilkanaPostModel::query()->find($id);

        if ($post === null || $canEditOthers || (int) $post->created_by === $userId) {
            return $post;
        }

        return null;
    }

    public function create(array $data, int $userId): SnkKilkanaPostModel
    {
        $post = SnkKilkanaPostModel::create([
            ...$this->columnsFrom($data, array_key_exists('image', $data)),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $this->syncRelations($post, $data);

        return $post->fresh();
    }

    public function update(SnkKilkanaPostModel $post, array $data, int $userId, bool $imageSent): SnkKilkanaPostModel
    {
        SnkKilkanaPostRevisionModel::create([
            'post_id' => $post->id,
            'user_id' => $userId,
            'payload' => $post->getAttributes(),
        ]);

        $previousPath = $post->url_path;
        $post->fill($this->columnsFrom($data, $imageSent));
        $post->updated_by = $userId;
        $post->save();
        $this->syncRelations($post, $data);

        if ($previousPath && $post->url_path !== $previousPath) {
            SnkKilkanaPostSlugModel::updateOrCreate(['path' => $previousPath], ['post_id' => $post->id]);
        }
        if ($post->url_path) {
            SnkKilkanaPostSlugModel::query()->where('path', $post->url_path)->delete();
        }

        return $post->fresh();
    }

    public function bulk(array $ids, string $action, int $userId, bool $canEditOthers): int
    {
        $query = $this->scopeToOwner(SnkKilkanaPostModel::query(), $userId, $canEditOthers)
            ->whereIn('id', $ids);

        if ($action === 'delete') {
            $count = $query->count();
            $query->delete();

            return $count;
        }

        $count = 0;
        foreach ($query->get() as $post) {
            $post->status = $action === 'publish'
                ? SnkKilkanaPostStatusEnum::Published
                : SnkKilkanaPostStatusEnum::Draft;
            if ($action === 'publish' && ($post->publish_at === null || $post->publish_at->isFuture())) {
                $post->publish_at = now();
            }
            $post->updated_by = $userId;
            $post->save();
            $count++;
        }

        return $count;
    }

    /**
     * Publica lo programado cuya hora ya paso. Lo llama el scheduler, por tenant.
     *
     * Publicar es SOLO cambiar de estado: la fecha ya esta puesta y es la prevista. Cuando la
     * fecha prevista y la de publicacion eran dos columnas, aqui habia que copiar una en otra
     * y vaciar la primera, y ese traspaso llego a publicar piezas con la fecha en que se
     * redactaron —descolocando portada, sitemap y feed—. Sin dos columnas no hay traspaso que
     * equivocar.
     */
    public function publishDue(): int
    {
        $due = SnkKilkanaPostModel::query()
            ->where('status', SnkKilkanaPostStatusEnum::Scheduled)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->get();

        foreach ($due as $post) {
            $post->update(['status' => SnkKilkanaPostStatusEnum::Published]);
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

    public function restore(
        SnkKilkanaPostModel $post,
        SnkKilkanaPostRevisionModel $revision,
        int $userId,
    ): SnkKilkanaPostModel {
        SnkKilkanaPostRevisionModel::create([
            'post_id' => $post->id,
            'user_id' => $userId,
            'payload' => $post->getAttributes(),
        ]);

        $columns = collect($revision->payload)
            ->except(['id', 'created_at', 'updated_at', 'created_by', 'updated_by'])
            ->all();
        $post->fill($columns);
        $post->updated_by = $userId;
        $post->save();

        return $post->fresh();
    }

    private function scopeToOwner(Builder $query, int $userId, bool $canEditOthers): Builder
    {
        return $canEditOthers ? $query : $query->where('created_by', $userId);
    }

    private function columnsFrom(array $data, bool $imageSent): array
    {
        $columns = collect($data)->except(['category', 'image', 'tags'])->all();

        if (array_key_exists('category', $data)) {
            $columns['category_id'] = SnkKilkanaCategoryModel::query()
                ->where('slug', $data['category'])
                ->value('id');
        }
        if ($imageSent) {
            $url = $data['image'] ?? null;
            $columns['cover_media_id'] = $url
                ? SnkKilkanaMediaModel::firstOrCreateByUrl($url)->id
                : null;
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
            ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreate(['name' => $name])->id);
        $post->tags()->sync($ids);
    }
}
