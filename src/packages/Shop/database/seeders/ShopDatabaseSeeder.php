<?php

namespace Packages\Shop\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Grants the Shop resource permissions to the `admin` role that CoreDatabaseSeeder creates.
 * Runs after Core's seeder (see CorePackagesDatabaseSeeder ordering). No business/demo data —
 * the registry is fed by real API traffic.
 */
class ShopDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $resources = ['products', 'orders', 'customers'];
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
