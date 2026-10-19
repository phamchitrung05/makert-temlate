<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo danh sách screen của template version
 * =====================================================================
 *
 * Mỗi row là một HTML screen đã được AI chuẩn hóa trong manifest v1. Bảng
 * giữ slug/title/path/thứ tự và metadata bounded; screenshot sẽ dùng
 * MediaAssetUsageService qua field template_screen.preview ở task sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo screen rows, FK, unique slug/path và sort index
 * - down(): rollback bảng screen
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : resource_version_templates đã tồn tại
 * - OUTPUT: bảng resource_version_template_screens cho preview/admin UI
 * - SIDE EFFECT: tạo/xóa schema; không đọc package hoặc attach media
 * - EXCEPTION/TRANSACTION: database exception do schema; migration không tự mở transaction
 * =====================================================================
 */

return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bảng screen theo template version
     * =====================================================================
     * INPUT: Schema resource_version_templates đã migrate.
     * OUTPUT: Screen rows có unique slug/path trong từng template.
     * SIDE EFFECT: Tạo FK/index/unique trong database hiện tại.
     * EXCEPTION/TRANSACTION: Database exception truyền lên; không xử lý file.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('resource_version_template_screens', function (Blueprint $table): void {
            $table->id();
            // Đặt tên FK ngắn vì tên tự sinh vượt giới hạn 64 ký tự của MySQL.
            $table->foreignId('resource_version_template_id')
                ->constrained('resource_version_templates', indexName: 'rv_template_screens_template_fk')
                ->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('title', 160);
            $table->string('source_path', 512);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['resource_version_template_id', 'slug'],
                'rv_template_screens_template_slug_unique',
            );
            $table->unique(
                ['resource_version_template_id', 'source_path'],
                'rv_template_screens_template_path_unique',
            );
            $table->index(
                ['resource_version_template_id', 'sort_order'],
                'rv_template_screens_template_sort_index',
            );
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback bảng screen template
     * =====================================================================
     * INPUT: Database đang có resource_version_template_screens.
     * OUTPUT: Bảng screen bị xóa khi rollback migration.
     * SIDE EFFECT: Xóa screen metadata, không xóa MediaAsset đã attach.
     * EXCEPTION/TRANSACTION: Database exception truyền lên; không mở transaction.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_version_template_screens');
    }
};
