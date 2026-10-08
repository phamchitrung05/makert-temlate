<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu metadata nguồn và profile nháp của analysis.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : analysis đã có bài tham khảo và snapshot model.
 * - OUTPUT: dữ liệu đủ để mở lại trang edit từ popup.
 * - SIDE EFFECT: thay đổi schema; không lưu secret hoặc gọi AI.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Thêm loại nguồn, URL và liên kết profile nháp.
     * =====================================================================
     * Input: bảng analysis/profile. Output: metadata có thể khôi phục khi edit.
     * Side effect: cập nhật schema và foreign key tùy chọn.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::table('ai_writing_profile_analyses', function (Blueprint $table): void {
            $table->string('source_type', 20)->default('paste')->after('reference_text');
            $table->text('source_url')->nullable()->after('source_type');
            $table->unsignedBigInteger('draft_profile_id')->nullable()->after('result_json');
            $table->foreign('draft_profile_id')->references('id')->on('ai_writing_profiles')->nullOnDelete();
            $table->index('draft_profile_id');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ metadata nguồn và liên kết profile nháp.
     * =====================================================================
     * Input: schema đã thêm các cột analysis. Output: schema cũ.
     * Side effect: xóa foreign key/cột/index.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::table('ai_writing_profile_analyses', function (Blueprint $table): void {
            $table->dropIndex('ai_writing_profile_analyses_draft_profile_id_index');
            $table->dropForeign(['draft_profile_id']);
            $table->dropColumn(['source_type', 'source_url', 'draft_profile_id']);
        });
    }
};
