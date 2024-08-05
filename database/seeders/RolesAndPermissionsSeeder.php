<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        $rolesWithPermissions = [

            'Admin' => [
                'view users',
                'create user',
                'update user',
                'delete user',

                'view',
                'create',
                'update',
                'delete',

                'view roles',
                'add permissions on roles',
                'delete permissions on roles',
                'view permissions',
            ],
            'Editor' => [
                'view',
                'create',
                'update',
                'delete',
            ],
            // 'Patient' => [
            //     'view',
            //     'create',
            //     'update',
            //     'delete',
            // ],

        ];

        $this->createRolesAndPermissions($rolesWithPermissions);
    }

    private function createRolesAndPermissions(array $rolesWithPermissions)
    {
        foreach ($rolesWithPermissions as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            foreach ($permissions as $permissionName) {
                $permission = Permission::firstOrCreate(['name' => $permissionName]);
                if (!$role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
    }
}