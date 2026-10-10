<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng lưu điểm tạm cho candidate bài AI.
 * =====================================================================
 * Bảng tách khỏi ai_imports và ai_article_archives để điểm của từng
 * generation/content hash có thể hết hạn mà không làm mất archive đã duyệt.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo schema evaluator và các index chống chấm trùng.
 * - down(): gỡ schema evaluator khi rollback.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : migration runner và schema AI hiện có.
 * - OUTPUT: bảng ai_article_evaluations lưu lifecycle, điểm, dẫn chứng và hash.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo schema lưu một lần chấm của candidate.
     *
     * Input: schema database hiện tại.
     * Output: bảng evaluator có unique theo run/generation/content hash.
     * Side effect: thay đổi schema; không gọi provider hoặc đọc payload nguồn.
     */
    public function up(): void
    {
        Schema::create('ai_article_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->uuid('session_id')->nullable()->index();
            $table->unsignedInteger('generation_no')->default(1);
            $table->string('candidate_hash', 64);
            $table->string('source_hash', 64)->nullable();
            $table->string('rubric_version', 80);
            $table->string('prompt_version', 80)->nullable();
            $table->string('schema_version', 80)->nullable();
            $table->string('status', 24)->index();
            $table->decimal('score_total', 4, 2)->nullable();
            $table->json('scores_json')->nullable();
            $table->json('evidence_json')->nullable();
            $table->json('source_references_json')->nullable();
            $table->json('eligibility_json')->nullable();
            $table->json('connection_snapshot_json')->nullable();
            $table->json('diagnostics_json')->nullable();
            $table->json('usage_json')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['run_id', 'generation_no', 'candidate_hash'], 'ai_article_eval_identity');
            $table->index(['session_id', 'status', 'created_at']);
        });
    }

    /**
     * Gỡ bảng evaluator mà không đụng archive hoặc candidate nghiệp vụ.
     *
     * Input: schema có thể đang chứa bảng evaluator.
     * Output: schema quay về trước migration.
     * Side effect: xóa điểm tạm; archive đã duyệt vẫn giữ nguyên.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_article_evaluations');
    }
};
