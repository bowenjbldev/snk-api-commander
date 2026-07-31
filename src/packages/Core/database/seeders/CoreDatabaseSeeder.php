<?php

namespace Packages\Core\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CoreDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Create roles
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);

        // Create permissions
        $resources = [
            'users',
        ];

        $allPermissions = [];
        foreach ($resources as $resource) {
            foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
                $allPermissions[] = "{$action} {$resource}";
            }
        }

        foreach ($allPermissions as $permName) {
            Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'sanctum']);
        }

        // Assign permissions to roles
        $admin->givePermissionTo($allPermissions);

        // Nunca copies el admin global del control plane a un tenant. El provisioning crea el
        // administrador elegido para esa instancia antes de ejecutar los seeders de negocio.
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        $password = config('core.auth.admin_password');
        if (! is_string($password) || $password === '') {
            if (app()->environment('production')) {
                throw new \RuntimeException('CORE_ADMIN_PASSWORD is required when seeding in production.');
            }
            $password = 'secret1234';
        }

        $user = CoreUserModel::firstOrCreate(
            ['email' => config('core.auth.admin_email', 'admin@snake.com')],
            [
                'name' => 'Admin User',
                'full_name' => 'Admin User',
                'password' => $password,
            ]
        );

        $user->assignRole('admin');
    }
}
