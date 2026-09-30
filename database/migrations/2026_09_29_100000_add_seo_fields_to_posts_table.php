<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu metadata SEO độc lập với nội dung Post.
 * CÁC HÀM/METHOD TRONG FILE: up(): thêm cột; down(): rollback cột SEO.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): schema posts -> schema có metadata.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema hiện tại. Output: thêm cột nullable, boolean mặc định true. */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->text('excerpt')->nullable();
            $table->string('focus_keyword')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
        });
    }

    /** Input: schema đã migrate. Output: bỏ các cột SEO khi rollback. */
    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn([
            'excerpt', 'focus_keyword', 'seo_title', 'seo_description',
            'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description',
        ]));
    }
};
