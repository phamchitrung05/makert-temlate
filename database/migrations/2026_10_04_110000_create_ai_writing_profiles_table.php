<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng mẫu văn phong đã được quản trị viên duyệt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema database khi migrate hoặc rollback.
 * - OUTPUT: bảng profile có phiên bản, rules và bằng chứng độc lập với bài mẫu.
 * - SIDE EFFECT: thay đổi schema; không gọi provider AI.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo nơi lưu mẫu đã duyệt, không lưu API key.
     * =====================================================================
     * Input: schema hiện tại. Output: bảng ai_writing_profiles và index.
     * Side effect: tạo bảng; không gọi AI.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('ai_writing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('rules_json');
            $table->json('evidence_json')->nullable();
            $table->text('style_instructions');
            $table->unsignedInteger('version')->default(1);
            $table->string('origin', 30)->default('manual');
            $table->string('source_hash', 64)->nullable();
            $table->json('analysis_metadata_json')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ bảng khi rollback migration.
     * =====================================================================
     * Input: schema đã migrate. Output: bảng bị gỡ; có mất dữ liệu profile.
     * Side effect: xóa bảng theo rollback.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_writing_profiles');
    }
};
