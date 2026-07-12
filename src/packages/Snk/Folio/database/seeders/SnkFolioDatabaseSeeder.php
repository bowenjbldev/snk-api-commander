<?php

namespace Packages\Snk\Folio\Database\Seeders;

use Illuminate\Database\Seeder;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Folio\Models\SnkFolioAuthorModel;
use Packages\Snk\Folio\Models\SnkFolioCategoryModel;
use Packages\Snk\Folio\Models\SnkFolioLayoutModel;
use Packages\Snk\Folio\Models\SnkFolioMediaModel;
use Packages\Snk\Folio\Models\SnkFolioSettingModel;
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
        'politics' => ['name' => 'Politics', 'hue' => 25, 'description' => 'Government, parliament, elections and public policy.'],
        'economy' => ['name' => 'Economy', 'hue' => 250, 'description' => 'Markets, business, employment and personal finance.'],
        'culture' => ['name' => 'Culture', 'hue' => 310, 'description' => 'Books, film, music, arts and heritage.'],
        'society' => ['name' => 'Society', 'hue' => 150, 'description' => 'Education, health, housing and everyday life.'],
        'sports' => ['name' => 'Sports', 'hue' => 50, 'description' => 'Results, matches and the stories behind the game.'],
        'technology' => ['name' => 'Technology', 'hue' => 195, 'description' => 'Science, innovation and the digital world.'],
        'international' => ['name' => 'International', 'hue' => 275, 'description' => 'World news and global affairs.'],
    ];

    public function run(): void
    {
        $this->seedPermissions();
        $this->seedCategories();
        $this->seedPosts();
        $this->seedLayouts();
        $this->seedSettings();
    }

    /** Composición de arranque (snapshot del builder): un fresh ya no deja la portada en blanco. */
    private function seedLayouts(): void
    {
        $layouts = json_decode((string) file_get_contents(__DIR__.'/data/layouts.json'), true);
        foreach ($layouts as $row) {
            SnkFolioLayoutModel::firstOrCreate(['layout' => $row['layout']], ['schema' => $row['schema']]);
        }
    }

    /** Identidad/tema de arranque; firstOrCreate: nunca pisa lo que el tenant ya editó. */
    private function seedSettings(): void
    {
        $settings = json_decode((string) file_get_contents(__DIR__.'/data/settings.json'), true);
        SnkFolioSettingModel::firstOrCreate(['id' => 1], ['settings' => $settings]);
    }

    private function seedPermissions(): void
    {
        $names = [];
        foreach (['posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers'] as $resource) {
            foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
                $names[] = "$action $resource";
            }
        }
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);

        // Lector registrado: solo puede comentar (la lectura pública no exige auth).
        $reader = Role::findOrCreate('reader', 'sanctum');
        $reader->givePermissionTo('create comments');

        $demo = CoreUserModel::firstOrCreate(
            ['email' => 'reader@snk.test'],
            // Sin Hash::make: el cast 'hashed' del modelo ya lo hace (doble hash = login roto).
            ['name' => 'Demo Reader', 'password' => 'password']
        );
        $demo->assignRole($reader);
    }

    private function seedCategories(): void
    {
        $position = 0;
        foreach (self::CATEGORIES as $slug => $attrs) {
            // position = orden de declaración (el orden del nav público)
            SnkFolioCategoryModel::firstOrCreate(['slug' => $slug], [...$attrs, 'position' => $position++]);
        }
    }

    private function seedPosts(): void
    {
        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        foreach ($posts as $post) {
            $category = SnkFolioCategoryModel::query()->where('slug', $post['cat'])->first();
            $author = SnkFolioAuthorModel::firstOrCreate(['name' => trim($post['author'])]);
            $media = ! empty($post['image'])
                ? SnkFolioMediaModel::firstOrCreateByUrl($post['image'])
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
