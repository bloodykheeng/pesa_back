<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /** Roles and permissions as production has them. Call from setUp() in tests that use RefreshDatabase. */
    protected function seedRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        \Spatie\Permission\Models\Role::findOrCreate('Customer', 'web');
        \Spatie\Permission\Models\Role::findOrCreate('Editor', 'web');
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['status' => 'active'])->assignRole('Admin');
    }

    protected function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['status' => 'active'])->assignRole('Customer');
    }
}
