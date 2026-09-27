<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng liên kết MediaAsset với model/field nghiệp vụ
 * =====================================================================
 *
 * Bảng này là usage table polymorphic của application, không thay thế bảng
 * `media` của Spatie. Một asset có thể được dùng ở nhiều model/field; unique
 * rule chỉ ngăn cùng một asset bị attach trùng vào cùng một field.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo bảng usage, foreign key, index và unique rule
 * - down(): xóa bảng usage khi rollback
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : schema đã có `media_assets`
 * - OUTPUT: bảng media_asset_usages cho attach/detach/reorder
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo schema usage polymorphic của MediaAsset
     * =====================================================================
     *
     * OUTPUT:
     * - Bảng usage có FK tới media_assets và index cho list/filter theo model
     *
     * SIDE EFFECT:
     * - Tạo bảng và các index trong database hiện tại
     */
    public function up(): void
    {
        Schema::create('media_asset_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_asset_id')
                ->constrained('media_assets')
                ->cascadeOnDelete();
            $table->string('linkable_type', 64);
            $table->unsignedBigInteger('linkable_id');
            $table->string('field', 64);
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->index(
                ['linkable_type', 'linkable_id', 'field'],
                'media_asset_usages_linkable_field_index',
            );
            $table->index(
                ['media_asset_id', 'field'],
                'media_asset_usages_media_asset_field_index',
            );
            $table->unique(
                ['media_asset_id', 'linkable_type', 'linkable_id', 'field'],
                'media_asset_usages_unique_asset_link',
            );
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback schema usage của MediaAsset
     * =====================================================================
     *
     * OUTPUT:
     * - Bảng media_asset_usages không còn tồn tại
     *
     * SIDE EFFECT:
     * - Xóa liên kết nghiệp vụ; không xóa MediaAsset hoặc bảng media trực tiếp
     */
    public function down(): void
    {
        Schema::dropIfExists('media_asset_usages');
    }
};
