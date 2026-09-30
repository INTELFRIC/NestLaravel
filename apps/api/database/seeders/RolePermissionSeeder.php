<?php

namespace Database\Seeders;

use App\Security\Authorization\Models\Permission;
use App\Security\Authorization\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'customer' => 'Customer',
            'fleet_manager' => 'Fleet Manager',
            'workshop' => 'Workshop',
            'supplier' => 'Supplier',
            'platform_admin' => 'Platform Admin',
        ];

        foreach ($roles as $name => $label) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $label],
            );
        }

        $permissions = [
            'users.profile.view' => 'View own profile',
            'users.manage' => 'Manage users',
            'fleet.manage' => 'Manage fleet',
            'workshop.manage' => 'Manage workshop work',
            'supplier.manage' => 'Manage supplier catalog',
            'platform.admin' => 'Full platform administration',
        ];

        foreach ($permissions as $name => $label) {
            Permission::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $label],
            );
        }

        $rolePermissions = [
            'customer' => ['users.profile.view'],
            'fleet_manager' => ['users.profile.view', 'fleet.manage'],
            'workshop' => ['users.profile.view', 'workshop.manage'],
            'supplier' => ['users.profile.view', 'supplier.manage'],
            'platform_admin' => array_keys($permissions),
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $permissionIds = Permission::query()
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
