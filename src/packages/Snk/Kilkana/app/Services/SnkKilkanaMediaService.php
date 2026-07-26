<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Snk\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;

class SnkKilkanaMediaService
{
    public function index(Request $request): array
    {
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));
        $page = $this->filtered($request)->paginate($perPage);

        return [
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'facets' => $this->facets(),
        ];
    }

    public function find($id): ?SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::query()->find($id);
    }

    public function findTrashed($id): ?SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::onlyTrashed()->find($id);
    }

    public function findWithTrashed($id): ?SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::withTrashed()->find($id);
    }

    public function update(SnkKilkanaMediaModel $media, array $data): SnkKilkanaMediaModel
    {
        $columns = collect($data)->except(['tag_ids', 'collection_ids'])->all();
        if ($columns) {
            $media->update($columns);
        }
        if (array_key_exists('tag_ids', $data)) {
            $media->tags()->sync($data['tag_ids']);
        }
        if (array_key_exists('collection_ids', $data)) {
            $media->collections()->sync($data['collection_ids']);
        }

        return $this->serialize($media);
    }

    public function trash(SnkKilkanaMediaModel $media): void
    {
        $media->delete();
    }

    public function restore(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        $media->restore();

        return $this->serialize($media);
    }

    public function forceDestroy(SnkKilkanaMediaModel $media): void
    {
        if ($media->disk && $media->path) {
            Storage::disk($media->disk)->delete($media->path);
        }
        $media->forceDelete();
    }

    public function bulk(array $ids, string $action, array $tagIds = [], array $collectionIds = []): void
    {
        switch ($action) {
            case 'trash':
                SnkKilkanaMediaModel::query()->whereIn('id', $ids)->delete();
                break;
            case 'restore':
                SnkKilkanaMediaModel::onlyTrashed()->whereIn('id', $ids)->restore();
                break;
            case 'tag':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->tags()->syncWithoutDetaching($tagIds);
                }
                break;
            case 'untag':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->tags()->detach($tagIds);
                }
                break;
            case 'collect':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->collections()->syncWithoutDetaching($collectionIds);
                }
                break;
            case 'uncollect':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->collections()->detach($collectionIds);
                }
                break;
        }
    }

    /** @return array{media: SnkKilkanaMediaModel, duplicate: bool} */
    public function upload(array $data, int $userId): array
    {
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();

        if ($existing) {
            return ['media' => $this->serialize($existing), 'duplicate' => true];
        }

        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $this->dimensions($file->getRealPath(), $data);

        $media = SnkKilkanaMediaModel::create([
            'url' => '/storage/'.$path,
            'original_name' => $file->getClientOriginalName(),
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'mime' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'duration' => $data['duration'] ?? null,
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'disk' => 'public',
            'path' => $path,
            'uploaded_by' => $userId,
        ]);

        return ['media' => $this->serialize($media), 'duplicate' => false];
    }

    /** @return array{media: SnkKilkanaMediaModel, duplicate: bool} */
    public function replace(SnkKilkanaMediaModel $media, array $data): array
    {
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $clash = SnkKilkanaMediaModel::withTrashed()
            ->where('content_hash', $hash)
            ->where('id', '!=', $media->id)
            ->first();

        if ($clash) {
            return ['media' => $this->serialize($clash), 'duplicate' => true];
        }

        if ($media->disk && $media->path) {
            Storage::disk($media->disk)->delete($media->path);
        }
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $this->dimensions($file->getRealPath(), $data);

        $media->update([
            'url' => '/storage/'.$path,
            'mime' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'duration' => $data['duration'] ?? null,
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'disk' => 'public',
            'path' => $path,
        ]);

        return ['media' => $this->serialize($media), 'duplicate' => false];
    }

    private function dimensions(string $realPath, array $data): array
    {
        $size = @getimagesize($realPath);

        return $size ? [$size[0], $size[1]] : [$data['width'] ?? null, $data['height'] ?? null];
    }

    private function filtered(Request $request): Builder
    {
        $query = SnkKilkanaMediaModel::query()
            ->with(['tags', 'collections'])
            ->withCount(SnkKilkanaMediaModel::USOS);

        $status = $request->string('status')->toString();
        if ($status === 'trash') {
            $query->onlyTrashed();
        } elseif ($status === 'review') {
            $query->where('rights_status', SnkKilkanaMediaRightsEnum::Unknown);
        } elseif ($status === 'unused') {
            $query->unused();
        }

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function (Builder $sub) use ($search) {
                foreach (['title', 'original_name', 'alt', 'caption', 'credit', 'description'] as $column) {
                    $sub->orWhere($column, 'like', "%{$search}%");
                }
            });
        }
        if ($kind = $request->string('kind')->toString()) {
            $this->whereKind($query, $kind);
        }
        if ($tagId = $request->integer('tag_id')) {
            $query->whereHas('tags', fn (Builder $sub) => $sub->where('kilkana_media_tags.id', $tagId));
        }
        if ($collectionId = $request->integer('collection_id')) {
            $query->whereHas('collections', fn (Builder $sub) => $sub->where('kilkana_media_collections.id', $collectionId));
        }

        if ($orientation = $request->string('orientation')->toString()) {
            $query->whereNotNull('width')->whereNotNull('height');
            if ($orientation === 'landscape') {
                $query->whereColumn('width', '>', 'height');
            } elseif ($orientation === 'portrait') {
                $query->whereColumn('height', '>', 'width');
            } elseif ($orientation === 'square') {
                $query->whereColumn('width', '=', 'height');
            }
        }

        if ($from = $request->string('from')->toString()) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($uploadedBy = $request->integer('uploaded_by')) {
            $query->where('uploaded_by', $uploadedBy);
        }

        return match ($request->string('sort')->toString()) {
            'oldest' => $query->orderBy('created_at'),
            'name' => $query->orderBy('title'),
            'largest' => $query->orderByDesc('size_bytes'),
            default => $query->orderByDesc('created_at'),
        };
    }

    private function whereKind(Builder $query, string $kind): void
    {
        match ($kind) {
            'image' => $query->where('mime', 'like', 'image/%'),
            'video' => $query->where('mime', 'like', 'video/%'),
            'audio' => $query->where('mime', 'like', 'audio/%'),
            'document' => $query->where(fn (Builder $sub) => $sub
                ->whereNull('mime')
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('mime', 'not like', 'image/%')
                    ->where('mime', 'not like', 'video/%')
                    ->where('mime', 'not like', 'audio/%'))),
            default => null,
        };
    }

    private function facets(): array
    {
        $soon = Carbon::today()->addDays(30)->toDateString();

        return [
            'all' => SnkKilkanaMediaModel::query()->count(),
            'review' => SnkKilkanaMediaModel::query()->where('rights_status', SnkKilkanaMediaRightsEnum::Unknown)->count(),
            'unused' => SnkKilkanaMediaModel::query()->unused()->count(),
            'missing_alt' => SnkKilkanaMediaModel::query()
                ->where(fn (Builder $query) => $query->whereNull('alt')->orWhere('alt', ''))
                ->count(),
            'expiring' => SnkKilkanaMediaModel::query()
                ->whereNotNull('rights_expires_at')
                // Sin `whereDate`: envolver la columna en una función impide usar el índice,
                // y la columna ya es de tipo fecha.
                ->where('rights_expires_at', '<=', $soon)
                ->count(),
            'trash' => SnkKilkanaMediaModel::onlyTrashed()->count(),
        ];
    }

    /**
     * Rellena lo que el articulo SI sabe de su portada: titulo, alt, pie, derechos y licencia.
     *
     * Solo rellena huecos —nunca pisa lo que alguien escribio a mano— y no toca etiquetas ni
     * colecciones. Llego a copiar los tags del post y el nombre de su seccion, y como corre
     * sobre todos los tenants bastaba una ejecucion para dejar cada fototeca con el
     * vocabulario editorial dentro: un tag de medio describe el FICHERO —«retrato»,
     * «apaisado», «archivo»—, no el tema de la noticia.
     */
    public function enrichFromPosts(): int
    {
        $enriched = 0;

        foreach (SnkKilkanaPostModel::query()->with('coverMedia')->get() as $post) {
            $media = $post->coverMedia;
            if (! $media) {
                continue;
            }

            $deUnsplash = str_contains((string) $media->source_url, 'unsplash.com');
            $changes = [];

            // El nombre que trae el fichero descargado no es un titulo: `photo-1a2b3c`.
            if (! $media->title || preg_match('/^(photo|unsplash)-[a-z0-9-]+$/i', $media->title)) {
                $changes['title'] = $post->title;
            }
            if (! $media->alt) {
                $changes['alt'] = $post->title;
            }
            if (! $media->caption) {
                $changes['caption'] = $post->excerpt;
            }
            if (! $media->description) {
                $changes['description'] = "Editorial cover for: {$post->title}";
            }
            if ($deUnsplash && $media->rights_status->needsReview()) {
                $changes['rights_status'] = SnkKilkanaMediaRightsEnum::Licensed;
            }
            if ($deUnsplash && ! $media->license) {
                $changes['license'] = 'Unsplash License';
            }

            if ($changes) {
                $media->update($changes);
                $enriched++;
            }
        }

        return $enriched;
    }

    public function serialize(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        return $media->fresh(['tags', 'collections'])->loadCount(SnkKilkanaMediaModel::USOS);
    }
}
