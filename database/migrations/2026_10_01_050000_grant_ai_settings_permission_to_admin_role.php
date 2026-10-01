<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấp quyền quản trị AI Settings cho admin role hiện hữu.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT: permission catalog và role admin guard.
 * OUTPUT: admin/super-admin có thể truy cập AI Settings.
 * SIDE EFFECT: ghi permission, role-permission pivot và xóa permission cache.
 * EXCEPTION/TRANSACTION: migration transaction thuộc database driver.
 * =====================================================================
 */
return new class extends Migration
{
    private const PERMISSION = 'ai_settings.manage';

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo permission AI Settings và cấp cho role quản trị.
     * =====================================================================
     * INPUT: role `admin`/`super-admin` thuộc guard `admin`.
     * OUTPUT: các role quản trị có permission ai_settings.manage.
     * SIDE EFFECT: insert permission/pivot và refresh Spatie cache.
     * EXCEPTION/TRANSACTION: lỗi database truyền lên; không gọi provider AI.
     * =====================================================================
     */
    public function up(): void
    {
        $permission = Permission::findOrCreate(self::PERMISSION, 'admin');

        Role::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', ['admin', 'super-admin'])
            ->get()
            ->each(fn (Role $role): mixed => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ permission AI Settings khỏi role đã được migration cấp.
     * =====================================================================
     * INPUT: permission và role admin guard đã tồn tại.
     * OUTPUT: role không còn pivot ai_settings.manage; permission không dùng
     * được xóa để tránh ảnh hưởng assignment trực tiếp ngoài migration.
     * SIDE EFFECT: xóa role-permission pivot và refresh Spatie cache.
     * EXCEPTION/TRANSACTION: lỗi database truyền lên; không gọi provider AI.
     * =====================================================================
     */
    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'admin')
            ->first();

        if (! $permission) {
            return;
        }

        Role::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', ['admin', 'super-admin'])
            ->get()
            ->each(fn (Role $role): mixed => $role->revokePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
