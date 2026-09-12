<?php

namespace Packages\Commander\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Siembra solo la autorización de la API Commander: permisos Spatie por recurso y el rol
 * `admin` (asignado al usuario administrador que crea CoreDatabaseSeeder). No incluye datos de
 * negocio ni demo: el registro empieza vacío y se rellena desde el código desplegado y la API real.
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
