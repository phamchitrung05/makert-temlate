<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấp quyền quản lý role cho role admin hiện hữu
 * =====================================================================
 *
 * Migration bổ sung quyền users.view/users.manage cho role admin để tài
 * khoản admin có thể xem và tạo role theo đúng boundary của access API.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): cấp hai quyền quản lý access nếu role admin tồn tại.
 * - down(): thu hồi hai quyền được migration này cấp.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cấp quyền quản lý role cho admin
     * INPUT: Role/permission admin trong database hiện tại.
     * OUTPUT: Role admin có users.view và users.manage.
     * SIDE EFFECT: Ghi role_has_permissions và xóa permission cache.
     * EXCEPTION/TRANSACTION: Laravel chạy migration trong lifecycle chuẩn.
     * =====================================================================
     */
    public function up(): void
    {
        $role = Role::query()->where('name', 'admin')->where('guard_name', 'admin')->first();

        if ($role === null) {
            return;
        }

        foreach (['users.view', 'users.manage'] as $name) {
            $permission = Permission::findOrCreate($name, 'admin');
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hoàn tác quyền được cấp bởi migration
     * INPUT: Role admin trong database.
     * OUTPUT: Role admin không còn hai permission quản lý access.
     * SIDE EFFECT: Xóa pivot role_has_permissions và xóa permission cache.
     * EXCEPTION/TRANSACTION: Laravel chạy rollback trong lifecycle chuẩn.
     * =====================================================================
     */
    public function down(): void
    {
        $role = Role::query()->where('name', 'admin')->where('guard_name', 'admin')->first();

        if ($role === null) {
            return;
        }

        Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', ['users.view', 'users.manage'])
            ->get()
            ->each(fn (Permission $permission) => $role->revokePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
