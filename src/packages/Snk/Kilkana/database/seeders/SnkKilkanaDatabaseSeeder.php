<?php

namespace Packages\Snk\Kilkana\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaTagModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permisos de los recursos del CMS + taxonomía base (7 categorías con su hue) + los 60
 * artículos demo de kilkana (data/posts.json) resolviendo strings → relaciones
 * (autor, media de portada, tags). firstOrCreate: re-sembrar no duplica ni pisa.
 */
class SnkKilkanaDatabaseSeeder extends Seeder
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
            SnkKilkanaLayoutModel::firstOrCreate(['layout' => $row['layout']], ['schema' => $row['schema']]);
        }
    }

    /** Identidad/tema de arranque; firstOrCreate: nunca pisa lo que el tenant ya editó. */
    private function seedSettings(): void
    {
        $settings = json_decode((string) file_get_contents(__DIR__.'/data/settings.json'), true);
        SnkKilkanaSettingModel::firstOrCreate(['id' => 1], ['settings' => $settings]);
    }

    private function seedPermissions(): void
    {
        $names = [];
        foreach (['posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers', 'layouts', 'settings'] as $resource) {
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

        $readerPassword = config('snk.kilkana.kilkana.demo_reader_password');
        if ((! is_string($readerPassword) || $readerPassword === '') && app()->environment(['local', 'testing'])) {
            $readerPassword = 'password';
        }
        if (! is_string($readerPassword) || $readerPassword === '') {
            return;
        }

        $demo = CoreUserModel::firstOrCreate(
            ['email' => 'reader@snk.test'],
            // Sin Hash::make: el cast 'hashed' del modelo ya lo hace (doble hash = login roto).
            ['name' => 'Demo Reader', 'password' => $readerPassword]
        );
        $demo->assignRole($reader);
    }

    private function seedCategories(): void
    {
        $position = 0;
        foreach (self::CATEGORIES as $slug => $attrs) {
            // position = orden de declaración (el orden del nav público)
            SnkKilkanaCategoryModel::firstOrCreate(['slug' => $slug], [...$attrs, 'position' => $position++]);
        }
    }

    private function seedPosts(): void
    {
        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        foreach ($posts as $i => $post) {
            $category = SnkKilkanaCategoryModel::query()->where('slug', $post['cat'])->first();
            $author = SnkKilkanaAuthorModel::firstOrCreate(['name' => trim($post['author'])]);
            // El fixture de imagen es posicional: posts.json[N] ↔ data/media/unsplash-N.jpg.
            $media = ! empty($post['image'])
                ? $this->seedCoverMedia($post, $category, $i + 1)
                : null;

            $row = SnkKilkanaPostModel::firstOrCreate(['slug' => $post['slug']], [
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
                ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreate(['name' => $name])->id);
            $row->tags()->syncWithoutDetaching($tagIds);
        }
    }

    /**
     * Portada como fichero propio del tenant, no como URL remota: copia el JPG del
     * fixture (data/media/unsplash-N.jpg) al disco del tenant con la misma convención
     * que la subida real (SnkKilkanaMediaController::upload) y crea la fila con los
     * metadatos completos que pide la biblioteca. La URL de Unsplash de posts.json
     * queda como `source_url` (la procedencia), no como origen de servicio.
     *
     * Dedup por `content_hash`, igual que la subida: re-sembrar no duplica.
     * Sin fixture, cae a la fila con URL remota para no romper la siembra.
     */
    private function seedCoverMedia(array $post, ?SnkKilkanaCategoryModel $category, int $n): SnkKilkanaMediaModel
    {
        $fixture = __DIR__.'/data/media/unsplash-'.$n.'.jpg';
        if (! is_file($fixture)) {
            return SnkKilkanaMediaModel::firstOrCreateByUrl($post['image']);
        }

        $name = 'unsplash-'.$n.'.jpg';
        $path = 'media/'.DB::connection()->getDatabaseName().'/'.$name;
        // putFileAs es idempotente por nombre: re-sembrar reescribe el mismo fichero.
        Storage::disk('public')->putFileAs(dirname($path), new File($fixture), $name);

        [$width, $height] = @getimagesize($fixture) ?: [null, null];
        $media = SnkKilkanaMediaModel::firstOrCreate(
            ['content_hash' => hash_file('sha256', $fixture)],
            [
                'url' => '/storage/'.$path,
                'original_name' => $name,
                'path' => $path,
                'disk' => 'public',
                'mime' => 'image/jpeg',
                'width' => $width,
                'height' => $height,
                'size_bytes' => filesize($fixture),
                'title' => $post['title'],
                'alt' => $post['title'],
                'caption' => $post['excerpt'],
                'description' => 'Editorial cover for: '.$post['title'],
                'credit' => 'Unsplash',
                'source_url' => $post['image'],
                'rights_status' => 'licensed',
                'license' => 'Unsplash License',
            ]
        );

        // ponytail: repite la regla de kilkana:enrich-media, pero aquí el post ya está
        // en la mano (sin re-consultar). Enrich sigue siendo el reparador de media
        // subida más tarde sin metadatos; no se orquesta un comando desde un seeder.
        $media->tags()->syncWithoutDetaching(
            collect($post['tags'] ?? [])->map(fn (string $tag) => SnkKilkanaMediaTagModel::firstOrCreate(['name' => $tag])->id)
        );
        if ($category) {
            $media->collections()->syncWithoutDetaching(
                [SnkKilkanaMediaCollectionModel::firstOrCreate(['name' => $category->name])->id]
            );
        }

        return $media;
    }
}
