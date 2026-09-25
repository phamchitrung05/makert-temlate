<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Seed role và permission cho admin guard
 * =====================================================================
 *
 * Seeder này idempotent và chỉ tạo permission cho guard `admin`. Customer
 * không nhận permission back-office trong phiên bản đầu tiên.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - run(): tạo permission catalog, roles và quan hệ role-permission
 * =====================================================================
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo permission và role admin một cách idempotent
     * =====================================================================
     *
     * OUTPUT:
     * - Không trả giá trị; database có đủ permission và role cho admin guard
     *
     * SIDE EFFECT:
     * - INSERT/UPDATE permissions, roles và role_has_permissions
     * =====================================================================
     */
    public function run(): void
    {
        $permissionNames = [
            'resources.view',
            'resources.create',
            'resources.update',
            'resources.delete',
            'resources.publish',
            'resources.archive',
            'resource_versions.manage',
            'media.view',
            'media.manage',
            'users.view',
            'users.manage',
            'analytics.view',
            'settings.manage',
        ];

        $permissions = collect($permissionNames)
            ->mapWithKeys(fn (string $name): array => [
                $name => Permission::findOrCreate($name, 'admin'),
            ]);

        $roles = [
            'super-admin' => $permissions,
            'admin' => $permissions->only([
                'resources.view',
                'resources.create',
                'resources.update',
                'resources.publish',
                'resources.archive',
                'resource_versions.manage',
                'media.view',
                'media.manage',
            ]),
            'editor' => $permissions->only([
                'resources.view',
                'resources.create',
                'resources.update',
                'resources.publish',
                'resource_versions.manage',
                'media.view',
                'media.manage',
            ]),
            'support' => $permissions->only([
                'resources.view',
                'media.view',
                'users.view',
            ]),
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'admin');
            $role->syncPermissions($rolePermissions->values());
        }
    }
}
