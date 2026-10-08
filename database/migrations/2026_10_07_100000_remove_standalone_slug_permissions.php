<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Loại bỏ permission slug độc lập khỏi admin catalog
 * =====================================================================
 *
 * Slug preview đã dùng permission của model tương ứng trong config/slug-models.php.
 * Migration dọn các dòng slugs.* cũ và pivot còn sót để role không giữ một
 * nhóm quyền SEO tách khỏi model.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): xóa permission slugs.* và các pivot admin liên quan.
 * - down(): không khôi phục dữ liệu legacy đã bị loại bỏ có chủ đích.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn permission slug độc lập đã deprecated
     * INPUT: Permission admin có tên slugs.view/create/update.
     * OUTPUT: Không còn permission/pivot slug độc lập trong database.
     * SIDE EFFECT: Xóa role/model permission pivot và làm mới cache Spatie.
     * EXCEPTION/TRANSACTION: Laravel chạy migration trong lifecycle chuẩn.
     * =====================================================================
     */
    public function up(): void
    {
        $permissionPivot = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $permissionNames = ['slugs.view', 'slugs.create', 'slugs.update'];
        $permissionIds = Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', $permissionNames)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        foreach (['role_has_permissions', 'model_has_permissions'] as $tableKey) {
            $table = config("permission.table_names.{$tableKey}", $tableKey);
            DB::table($table)->whereIn($permissionPivot, $permissionIds)->delete();
        }

        Permission::query()->whereIn('id', $permissionIds)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ catalog model làm nguồn quyền slug duy nhất khi rollback
     * INPUT: Không có.
     * OUTPUT: Không khôi phục permission slug độc lập đã deprecated.
     * SIDE EFFECT: Không ghi database.
     * EXCEPTION/TRANSACTION: Không mở transaction riêng.
     * =====================================================================
     */
    public function down(): void
    {
        // Không khôi phục permission legacy vì catalog hiện tại không khai báo chúng.
    }
};
