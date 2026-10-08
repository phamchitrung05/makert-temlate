<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm vòng đời nháp/hoạt động cho mẫu văn phong.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng ai_writing_profiles hiện có.
 * - OUTPUT: profile có status để phân biệt bản worker tạo và bản đã duyệt.
 * - SIDE EFFECT: thay đổi schema; không gọi provider AI.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn trạng thái hoạt động cho profile cũ và tạo index.
     * =====================================================================
     * Input: bảng profile. Output: cột status với giá trị active/draft.
     * Side effect: cập nhật schema và giá trị mặc định.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::table('ai_writing_profiles', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('origin')->index();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ cột trạng thái khi rollback.
     * =====================================================================
     * Input: schema đã thêm status. Output: schema profile như trước migration.
     * Side effect: xóa cột/index status.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::table('ai_writing_profiles', function (Blueprint $table): void {
            $table->dropIndex('ai_writing_profiles_status_index');
            $table->dropColumn('status');
        });
    }
};
