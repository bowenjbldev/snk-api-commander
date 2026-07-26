<?php

namespace Packages\Snk\Kilkana\Services;

use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
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
            ->withSum(['views as views_day' => fn ($q) => $q->where('date', now()->toDateString())], 'views')
            ->withSum(['views as views_week' => fn ($q) => $q->where('date', '>=', now()->subDays(6)->toDateString())], 'views')
            ->withSum('views as views_total', 'views')
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

    public function categories()
    {
        return SnkKilkanaCategoryModel::query()->orderBy('position')->orderBy('id')->get();
    }

    public function author(string $slug): ?array
    {
        $author = SnkKilkanaAuthorModel::query()
            ->with('avatarMedia:id,url')
            ->where('slug', $slug)
            ->first();

        return $author ? [
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
        ] : null;
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
            ->where('status', 'approved')
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get(['id', 'post_id', 'user_id', 'parent_id', 'author_name', 'body', 'created_at']);
    }

    public function storeComment(int $postId, array $data): bool
    {
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parentId = SnkKilkanaCommentModel::query()
                ->where('id', $data['parent_id'])
                ->where('post_id', $postId)
                ->where('status', 'approved')
                ->value('id');
            if ($parentId === null) {
                return false;
            }
        }

        SnkKilkanaCommentModel::create([
            'post_id' => $postId,
            'user_id' => null,
            'parent_id' => $parentId,
            'author_name' => $data['author_name'],
            'author_email' => strtolower($data['author_email']),
            'body' => $data['body'],
            'status' => 'pending',
        ]);

        return true;
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
        $settings = SnkKilkanaSettingModel::query()->find(1)?->settings ?? [];

        return [
            'tenant' => $settings['tenant'] ?? null,
            'theme' => $settings['theme'] ?? null,
            'theme_overrides' => $settings['theme_overrides'] ?? [],
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
