<?php

namespace Packages\Core\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Packages\Core\Models\CoreUserModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CoreDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = config('core.auth.admin_password');
        if (! is_string($password) || $password === '') {
            if (app()->environment('production')) {
                throw new \RuntimeException('CORE_ADMIN_PASSWORD is required when seeding in production.');
            }
            $password = 'secret1234';
        }

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

        // Admin de ESTA BD (la del package o la del operador). Aislamiento por BD, sin package_id.
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
