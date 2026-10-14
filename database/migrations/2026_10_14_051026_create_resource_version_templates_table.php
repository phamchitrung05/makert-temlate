<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo metadata xử lý template theo ResourceVersion
 * =====================================================================
 *
 * Một ResourceVersion template có đúng một row metadata. Bảng này lưu
 * checksum/input token và snapshot manifest đã được AI chuẩn hóa trước khi
 * nhập hệ thống; không tự lưu navigation heuristic hoặc chạy package.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo metadata processing, FK, unique và query indexes
 * - down(): rollback bảng metadata template
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : schema users, media_assets và resource_versions đã tồn tại
 * - OUTPUT: bảng resource_version_templates cho TPL-02–TPL-08
 * - SIDE EFFECT: tạo/xóa schema; không đọc ZIP, không ghi file hoặc queue
 * - EXCEPTION/TRANSACTION: database exception do schema; migration không tự mở transaction
 * =====================================================================
 */

return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bảng metadata xử lý template
     * =====================================================================
     * INPUT: Schema resource_versions/media_assets đã migrate.
     * OUTPUT: Bảng 1:1 theo ResourceVersion với processing status riêng.
     * SIDE EFFECT: Tạo FK/index/unique trong database hiện tại.
     * EXCEPTION/TRANSACTION: Database exception truyền lên; không xử lý ZIP.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('resource_version_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_version_id')
                ->constrained('resource_versions')
                ->cascadeOnDelete();
            $table->foreignId('source_media_id')
                ->nullable()
                ->constrained('media_assets')
                ->nullOnDelete();
            $table->string('package_sha256', 64)->nullable();
            $table->string('processing_status', 32)->default('pending');
            $table->uuid('processing_token')->nullable();
            $table->string('source_root', 512)->nullable();
            $table->string('entry_screen_slug', 120)->nullable();
            $table->json('manifest_json')->nullable();
            $table->string('preview_path', 1024)->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('resource_version_id', 'rv_templates_version_unique');
            $table->unique('processing_token', 'rv_templates_token_unique');
            $table->index(['processing_status', 'processed_at'], 'rv_templates_status_processed_index');
            $table->index(['source_media_id', 'package_sha256'], 'rv_templates_source_hash_index');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback bảng metadata xử lý template
     * =====================================================================
     * INPUT: Database đang có resource_version_templates.
     * OUTPUT: Bảng metadata bị xóa khi rollback migration.
     * SIDE EFFECT: Xóa metadata template, không xóa ResourceVersion/MediaAsset.
     * EXCEPTION/TRANSACTION: Database exception truyền lên; không mở transaction.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_version_templates');
    }
};
