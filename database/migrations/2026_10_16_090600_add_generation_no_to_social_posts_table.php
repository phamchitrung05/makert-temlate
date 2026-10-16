<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm số lượt tạo hiện hành cho Social Post.
 * =====================================================================
 * Generation number giúp worker caption/ảnh nhận biết job cũ và không ghi đè
 * kết quả của lần regenerate mới hơn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): thêm generation_no và index lifecycle.
 * - down(): gỡ cột/index khi rollback migration.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng social_posts đã tồn tại.
 * - OUTPUT: mỗi Social Post có generation number nguyên dương.
 * - SIDE EFFECT: thay đổi DDL; không gọi AI hoặc publisher.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Thêm generation number được khóa cùng Social Post khi xếp hàng.
     *
     * Input: schema có social_posts.
     * Output: cột generation_no mặc định 0 và index hỗ trợ worker.
     * Side effect: ghi DDL; không mở transaction nghiệp vụ.
     */
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            // Không thêm index riêng ở SQLite: ALTER TABLE rebuild sẽ tự copy
            // index generation_status hiện hữu và có thể tạo duplicate index.
            $table->unsignedInteger('generation_no')->default(0)->after('publication_status');
        });
    }

    /**
     * Gỡ cột generation number khi rollback.
     *
     * Input: schema có cột/index do migration tạo.
     * Output: social_posts trở về schema trước migration.
     * Side effect: xóa index/cột; không xóa Social Post.
     */
    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropColumn('generation_no');
        });
    }
};
