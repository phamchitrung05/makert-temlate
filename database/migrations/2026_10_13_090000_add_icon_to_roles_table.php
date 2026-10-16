<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm biểu tượng tùy chọn cho role admin.
 * =====================================================================
 *
 * Role cũ có thể để icon null để frontend tiếp tục dùng icon mặc định theo
 * tên role; role mới do dialog tạo sẽ lưu icon Tabler đã được chọn.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : bảng roles của Spatie Permission.
 * - OUTPUT: cột icon nullable dùng cho giao diện quản trị role.
 * - SIDE EFFECT: thay đổi schema database.
 * - EXCEPTION/TRANSACTION: migration do Laravel điều phối.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema hiện tại. Output: roles có thêm icon nullable. */
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasColumn($table, 'icon')) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->string('icon', 80)->nullable()->after('name');
            });
        }
    }

    /** Input: schema đã thêm icon. Output: schema trước migration. */
    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($table, 'icon')) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->dropColumn('icon');
            });
        }
    }
};
