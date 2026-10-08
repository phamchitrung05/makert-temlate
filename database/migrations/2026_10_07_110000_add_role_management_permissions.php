<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bổ sung permission riêng cho vòng đời role admin
 * =====================================================================
 *
 * Catalog trước đây chỉ dùng users.manage để quản trị access nên giao diện
 * không thể hiển thị rõ quyền tạo, sửa và xóa role. Migration này tạo các
 * permission roles.* và cấp chúng cho role mặc định đang quản trị role.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo permission roles.* và cấp cho super-admin/admin.
 * - down(): gỡ permission roles.* cùng các pivot liên quan.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : permission catalog và role admin hiện có.
 * - OUTPUT: database có đủ permission vòng đời role cho admin guard.
 * - SIDE EFFECT: Ghi permission/pivot và xóa cache Spatie.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo permission vòng đời role và cấp cho role mặc định
     * INPUT: Role/permission bảng admin guard nếu đã tồn tại.
     * OUTPUT: roles.view/create/update/delete tồn tại và admin có quyền.
     * SIDE EFFECT: Ghi permission/pivot, xóa permission cache.
     * =====================================================================
     */
    public function up(): void
    {
        $permissionNames = ['roles.view', 'roles.create', 'roles.update', 'roles.delete'];
        $permissions = collect($permissionNames)
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'admin'));

        foreach (['super-admin', 'admin'] as $roleName) {
            $role = Role::query()->where('guard_name', 'admin')->where('name', $roleName)->first();
            $role?->givePermissionTo($permissions->values());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ permission vòng đời role khi rollback migration
     * INPUT: Permission roles.* thuộc admin guard.
     * OUTPUT: Catalog trở về trước migration này.
     * SIDE EFFECT: Spatie xóa pivot/quyền và xóa permission cache.
     * =====================================================================
     */
    public function down(): void
    {
        $permissions = Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', ['roles.view', 'roles.create', 'roles.update', 'roles.delete'])
            ->get();

        foreach ($permissions as $permission) {
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
