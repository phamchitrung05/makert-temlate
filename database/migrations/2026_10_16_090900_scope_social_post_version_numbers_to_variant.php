<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đổi unique version Social Post sang phạm vi từng variant.
 * =====================================================================
 * Tạo index thay thế trước khi gỡ index đang hỗ trợ khóa ngoại trên MySQL.
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo unique variant/version và bỏ index legacy còn tồn tại.
 * - down(): khôi phục index legacy trước khi bỏ unique variant/version.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng social_post_versions đã có cột variant.
 * - OUTPUT: thay đổi index; giữ nguyên dữ liệu và khóa ngoại.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Input: index cũ hoặc trạng thái MySQL đã gỡ một phần index legacy.
     * Output: unique variant/version; DDL giữ index hỗ trợ FK suốt quá trình.
     */
    public function up(): void
    {
        Schema::table('social_post_versions', function (Blueprint $table): void {
            $table->unique(
                ['social_post_variant_id', 'version_no'],
                'social_post_versions_variant_version_unique',
            );
        });

        // MySQL có thể đã commit DROP đầu tiên trước khi migration cũ gặp lỗi FK.
        foreach (['social_post_versions_unique', 'social_post_versions_variant_version_index'] as $index) {
            if (Schema::hasIndex('social_post_versions', $index)) {
                Schema::table('social_post_versions', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }
    }

    /** Input: unique variant/version. Output: index legacy phục hồi, dữ liệu không đổi. */
    public function down(): void
    {
        Schema::table('social_post_versions', function (Blueprint $table): void {
            $table->unique(['social_post_id', 'version_no'], 'social_post_versions_unique');
            $table->index(
                ['social_post_variant_id', 'version_no'],
                'social_post_versions_variant_version_index',
            );
            $table->dropUnique('social_post_versions_variant_version_unique');
        });
    }
};
