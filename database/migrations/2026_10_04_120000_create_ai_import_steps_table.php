<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo nơi lưu step artifacts độc lập với draft public.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo checkpoint gắn với AiImport và input hash.
 * - down(): xóa bảng checkpoint khi rollback migration này.
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : migration Laravel và bảng ai_imports đã có.
 * - OUTPUT: bảng ai_import_steps; xóa import tự xóa checkpoint kỹ thuật.
 * - SIDE EFFECT: thay đổi schema; không ghi nội dung Post hoặc gọi AI.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bảng checkpoint theo run, bước và hash input
     * =====================================================================
     * INPUT: schema hiện tại.
     * OUTPUT: bảng checkpoint có unique key để resume đúng dữ liệu.
     * SIDE EFFECT: tạo bảng/index/foreign key; không gọi provider.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('ai_import_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('ai_import_id')->constrained('ai_imports')->cascadeOnDelete();
            $table->string('step_key', 80);
            $table->string('input_hash', 64);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->json('output_json')->nullable();
            $table->json('diagnostics_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['ai_import_id', 'step_key', 'input_hash'], 'ai_import_step_input_unique');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback riêng bảng checkpoint kỹ thuật
     * =====================================================================
     * INPUT: migration rollback Laravel.
     * OUTPUT: bảng ai_import_steps được xóa.
     * SIDE EFFECT: thay đổi schema, không xóa Post hoặc profile đã lưu.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_import_steps');
    }
};
