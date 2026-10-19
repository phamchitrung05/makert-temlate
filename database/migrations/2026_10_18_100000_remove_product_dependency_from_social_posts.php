<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Loại bỏ liên kết Product khỏi Social Post.
 * =====================================================================
 * Social Post là nội dung Facebook độc lập; Product vẫn tồn tại cho các
 * module Marketing khác nhưng không còn là dependency của Social Post.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): gỡ foreign key, index và cột product_id.
 * - down(): khôi phục cột nullable để rollback an toàn.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng social_posts đã tồn tại.
 * - OUTPUT: schema Social Post không chứa product_id.
 * - SIDE EFFECT: thay đổi DDL; không gọi provider hoặc ghi nội dung AI.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema social_posts. Output: product_id bị loại bỏ. */
    public function up(): void
    {
        if (! Schema::hasTable('social_posts') || ! Schema::hasColumn('social_posts', 'product_id')) {
            return;
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropIndex('social_posts_product_review_index');
            $table->dropColumn('product_id');
        });
    }

    /** Input: schema social_posts không còn product_id. Output: cột nullable được khôi phục. */
    public function down(): void
    {
        if (! Schema::hasTable('social_posts') || Schema::hasColumn('social_posts', 'product_id')) {
            return;
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('campaign_id')->constrained('products')->nullOnDelete();
            $table->index(['product_id', 'review_status', 'created_at'], 'social_posts_product_review_index');
        });
    }
};
