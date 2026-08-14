<?php

namespace Packages\Commander\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds only the Commander API authorization scaffolding: Spatie permissions for each resource and the
 * `admin` role (granted to the admin user that CoreDatabaseSeeder creates). No business/demo data —
 * the registry starts empty and is filled from deployed application code plus real API data.
 */
class CommanderDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $resources = ['accounts', 'clients', 'applications', 'instances', 'subscriptions', 'invoices', 'quotas', 'activity', 'plans', 'settings'];
        $actions = ['index', 'show', 'create', 'update', 'delete'];
        $names = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $names[] = "$action $resource";
            }
        }
        $names[] = 'control instances';
        $names[] = 'provision instances';
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);
    }
}
