<?php

namespace Packages\Blog\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Grants the Blog resource permissions to the `admin` role that CoreDatabaseSeeder creates.
 * Runs after Core's seeder (see CorePackagesDatabaseSeeder ordering). No business/demo data —
 * the registry is fed by real API traffic.
 */
class BlogDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $resources = ['authors', 'posts', 'comments'];
        $actions = ['index', 'show', 'create', 'update', 'delete'];

        $names = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $names[] = "{$action} {$resource}";
            }
        }

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }

        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);
    }
}
