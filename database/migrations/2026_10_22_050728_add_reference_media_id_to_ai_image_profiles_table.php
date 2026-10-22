<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm khóa ngoại reference_media_id tới media_assets cho
 * Visual Style (ai_image_profiles).
 * =====================================================================
 * reference_path vẫn giữ làm path file phục vụ worker/serve hiện tại; khi
 * reference_media_id có giá trị thì đó là nguồn chuẩn — file thật nằm trong
 * Spatie `media` collection của MediaAsset, không phải ai-visual-styles raw.
 */
return new class extends Migration
{
    /**
     * Thêm cột reference_media_id nullable + index + FK tới media_assets.
     * nullable để các style hiện có (chưa qua pipeline media) không bị lỗi.
     */
    public function up(): void
    {
        Schema::table('ai_image_profiles', function (Blueprint $table): void {
            $table->foreignId('reference_media_id')
                ->nullable()
                ->after('reference_mime')
                ->constrained('media_assets')
                ->nullOnDelete();
            $table->index('reference_media_id');
        });
    }

    /**
     * Rollback: gỡ index + FK + cột.
     */
    public function down(): void
    {
        Schema::table('ai_image_profiles', function (Blueprint $table): void {
            $table->dropIndex(['reference_media_id']);
            $table->dropConstrainedForeignId('reference_media_id');
        });
    }
};
