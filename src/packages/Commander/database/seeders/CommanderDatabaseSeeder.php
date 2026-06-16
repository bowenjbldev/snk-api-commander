<?php

namespace Packages\Commander\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds ONLY the panel's authorization scaffolding: the Spatie permissions for each resource and the
 * `admin` role (granted to the admin user that CoreDatabaseSeeder creates). No business/demo data —
 * the registry starts empty and is filled with real data: deployments via `commander:register-deploy`
 * (scripts/subscription.sh), and clients/subscriptions/invoices via the panel's CRUD.
 */
class CommanderDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $resources = ['clients', 'deployments', 'subscriptions', 'invoices', 'quotas', 'activity'];
        $actions = ['index', 'show', 'create', 'update', 'delete'];
        $names = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $names[] = "$action $resource";
            }
        }
        // Custom: signed /control proxy actions (suspend/grace/resume/sync/rotate-secret).
        $names[] = 'control deployments';
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);
    }
}
