<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu tùy chọn hiển thị Category trên menu.
 * CÁC HÀM/METHOD TRONG FILE: up(): thêm cột; down(): gỡ cột.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): schema categories -> cột boolean show_on_menu.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema hiện tại. Output: thêm show_on_menu, mặc định true cho dữ liệu cũ. */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('show_on_menu')->default(true);
        });
    }

    /** Input: schema sau migration. Output: xóa cột show_on_menu khi rollback. */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('show_on_menu');
        });
    }
};
