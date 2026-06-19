<?php

namespace Packages\Core\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Support\CoreActivePackageSupport;
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

        // Create admin user, scoped to the active package (null = operator/apex).
        $activePackageId = app(CoreActivePackageSupport::class)->id();

        $user = CoreUserModel::firstOrCreate(
            ['email' => 'admin@snake.com', 'package_id' => $activePackageId],
            [
                'name' => 'Admin User',
                'full_name' => 'Admin User',
                'password' => 'secret1234',
            ]
        );

        $user->assignRole('admin');
    }
}
