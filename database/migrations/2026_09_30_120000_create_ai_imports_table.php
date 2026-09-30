<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng lưu trạng thái AI import bài viết.
 * =====================================================================
 * Bảng lưu URL, trạng thái, kết quả JSON và lỗi để API polling an toàn.
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo bảng ai_imports và chỉ mục trạng thái.
 * - down(): xóa bảng khi rollback migration.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema database hiện tại.
 * - OUTPUT: bảng ai_imports hoặc schema được khôi phục khi rollback.
 * =====================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('source_url');
            $table->string('status', 24)->default('queued')->index();
            $table->json('result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_imports');
    }
};
