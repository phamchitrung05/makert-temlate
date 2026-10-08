<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bổ sung permission chi tiết cho workflow Post.
 * =====================================================================
 *
 * Migration tạo các action view/create/update/delete/review/publish/archive
 * cho guard admin và cấp chúng cho role mặc định đang có. Permission
 * `posts.manage` không bị xóa để các role cũ vẫn hoạt động.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo permission còn thiếu và bổ sung cho admin/editor.
 * - down(): gỡ các permission được migration này tạo khỏi role mặc định.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : permission catalog và role hiện có trong database.
 * - OUTPUT: database có permission Post lifecycle độc lập.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo permission Post lifecycle và cấp role mặc định
     * =====================================================================
     *
     * SIDE EFFECT:
     * - INSERT permission còn thiếu; INSERT role_has_permissions cho admin/editor.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction riêng; migration runner quản lý boundary.
     */
    public function up(): void
    {
        $names = [
            'posts.view', 'posts.create', 'posts.update', 'posts.delete',
            'posts.review', 'posts.publish', 'posts.archive',
        ];

        $permissions = collect($names)
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'admin'))
            ->values();

        foreach (['admin', 'editor'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'admin')->first();
            $role?->givePermissionTo($permissions);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hoàn tác permission do migration này thêm
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Gỡ quan hệ với role mặc định rồi xóa permission catalog.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction riêng; migration runner quản lý boundary.
     */
    public function down(): void
    {
        $permissions = Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', [
                'posts.view', 'posts.create', 'posts.update', 'posts.delete',
                'posts.review', 'posts.publish', 'posts.archive',
            ])
            ->get();

        foreach (['admin', 'editor'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'admin')->first();
            $role?->revokePermissionTo($permissions);
        }

        Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', $permissions->pluck('name'))
            ->delete();
    }
};
