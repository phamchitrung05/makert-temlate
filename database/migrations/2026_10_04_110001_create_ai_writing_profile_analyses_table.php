<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu tác vụ kỹ thuật phân tích văn phong, tách candidate Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : migrate/rollback schema.
 * - OUTPUT: bảng analysis UUID có lifecycle, snapshot không secret và retention.
 * - SIDE EFFECT: thay đổi schema, không tạo profile tự động.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bảng analysis có expiry và dữ liệu bài tham khảo.
     * =====================================================================
     * Input: schema hiện tại. Output: bảng tác vụ phân tích.
     * Side effect: tạo bảng, không gửi bài mẫu tới provider.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('ai_writing_profile_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->longText('reference_text');
            $table->string('source_hash', 64);
            $table->string('status', 30)->default('queued')->index();
            $table->json('connection_snapshot_json');
            $table->string('prompt_version', 50);
            $table->string('schema_version', 80);
            $table->json('result_json')->nullable();
            $table->json('diagnostics_json')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->index(['created_by', 'created_at']);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ bảng khi rollback.
     * =====================================================================
     * Input: schema đã migrate. Output: tác vụ và bài tham khảo bị gỡ.
     * Side effect: xóa bảng, không gỡ profile đã duyệt.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_writing_profile_analyses');
    }
};
