<?php

namespace Packages\Snk\Folio\Database\Seeders;

use Illuminate\Database\Seeder;
use Packages\Snk\Folio\Models\SnkFolioAuthorModel;
use Packages\Snk\Folio\Models\SnkFolioCategoryModel;
use Packages\Snk\Folio\Models\SnkFolioMediaModel;
use Packages\Snk\Folio\Models\SnkFolioPostModel;
use Packages\Snk\Folio\Models\SnkFolioTagModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permisos de los recursos del CMS + taxonomía base (7 categorías con su hue) + los 60
 * artículos demo de snk-news (data/posts.json) resolviendo strings → relaciones
 * (autor, media de portada, tags). firstOrCreate: re-sembrar no duplica ni pisa.
 */
class SnkFolioDatabaseSeeder extends Seeder
{
    /** Fuente de verdad de la taxonomía (el hue pinta el gradiente sin foto en el frontend). */
    private const CATEGORIES = [
        'politica' => ['name' => 'Politics', 'hue' => 25],
        'economia' => ['name' => 'Economy', 'hue' => 250],
        'cultura' => ['name' => 'Culture', 'hue' => 310],
        'sociedad' => ['name' => 'Society', 'hue' => 150],
        'deportes' => ['name' => 'Sports', 'hue' => 50],
        'tecnologia' => ['name' => 'Technology', 'hue' => 195],
        'internacional' => ['name' => 'International', 'hue' => 275],
    ];

    public function run(): void
    {
        $this->seedPermissions();
        $this->seedCategories();
        $this->seedPosts();
    }

    private function seedPermissions(): void
    {
        $names = [];
        foreach (['posts', 'categories', 'authors', 'media', 'tags'] as $resource) {
            foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
                $names[] = "$action $resource";
            }
        }
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);
    }

    private function seedCategories(): void
    {
        foreach (self::CATEGORIES as $slug => $attrs) {
            SnkFolioCategoryModel::firstOrCreate(['slug' => $slug], $attrs);
        }
    }

    private function seedPosts(): void
    {
        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        foreach ($posts as $post) {
            $category = SnkFolioCategoryModel::query()->where('slug', $post['cat'])->first();
            $author = SnkFolioAuthorModel::firstOrCreate(['name' => trim($post['author'])]);
            $media = ! empty($post['image'])
                ? SnkFolioMediaModel::firstOrCreate(['url' => $post['image']])
                : null;

            $row = SnkFolioPostModel::firstOrCreate(['slug' => $post['slug']], [
                'category_id' => $category->id,
                'author_id' => $author->id,
                'cover_media_id' => $media?->id,
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                'published_at' => $post['published_at'],
                'reads' => $post['reads'],
                'status' => $post['status'],
                'body_blocks' => $post['body_blocks'],
                'featured' => $post['featured'] ?? false,
                'scheduled_for' => $post['scheduled_for'],
            ]);

            $tagIds = collect($post['tags'] ?? [])
                ->map(fn (string $name) => SnkFolioTagModel::firstOrCreate(['name' => $name])->id);
            $row->tags()->syncWithoutDetaching($tagIds);
        }
    }
}
