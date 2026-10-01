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
        $permissionConfig = config('permissions');
        $guard = 'admin';
        $permissionNames = collect($permissionConfig['catalog'])
            ->flatMap(fn (array $actions, string $resource): array => collect($actions)
                ->map(fn (string $action): string => $resource.'.'.$action)
                ->all())
            ->unique()
            ->values();

        $permissions = collect($permissionNames)
            ->mapWithKeys(fn (string $name): array => [
                $name => Permission::findOrCreate($name, $guard),
            ]);

        // Role mặc định chỉ phục vụ dữ liệu khởi tạo; role thực tế sẽ được
        // quản lý/gán permission từ trang quản trị trong các bước tiếp theo.
        $roles = [
            'super-admin' => $permissions,
            'admin' => $permissions->only([
                'resources.view', 'resources.create', 'resources.update',
                'resources.publish', 'resources.archive', 'resource_versions.manage',
                'posts.manage', 'media.view', 'media.upload', 'media.attach',
                'media.delete', 'media.retry',
                'ai_settings.manage',
            ]),
            'editor' => $permissions->only([
                'resources.view', 'resources.create', 'resources.update',
                'resources.publish', 'resource_versions.manage', 'posts.manage',
                'taxonomy.manage', 'media.view', 'media.upload', 'media.attach',
            ]),
            'support' => $permissions->only(['resources.view', 'media.view', 'users.view']),
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, $guard);
            $role->syncPermissions($rolePermissions->values());
        }
    }
}
