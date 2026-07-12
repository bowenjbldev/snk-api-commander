<?php

namespace Packages\Snk\Folio\Database\Seeders;

use Illuminate\Database\Seeder;
use Packages\Snk\Folio\Models\SnkFolioPostModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permisos del recurso posts (rol admin, que crea CoreDatabaseSeeder) + los 60 articulos
 * demo de snk-news (data/posts.json, exportados una vez de su catalog.ts). firstOrCreate:
 * re-sembrar no pisa ediciones hechas por la API.
 */
class SnkFolioDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();
        $this->seedPosts();
    }

    private function seedPermissions(): void
    {
        $names = [];
        foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
            $names[] = "$action posts";
        }
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);
    }

    private function seedPosts(): void
    {
        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        foreach ($posts as $post) {
            SnkFolioPostModel::firstOrCreate(['id' => $post['id']], $post);
        }
    }
}
