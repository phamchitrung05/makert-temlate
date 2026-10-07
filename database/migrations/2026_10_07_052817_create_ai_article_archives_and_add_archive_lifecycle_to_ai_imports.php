<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo kho bản AI gốc độc lập với run/Post tạm.
 * =====================================================================
 *
 * Migration tạo kho bản AI đã chọn độc lập với run/Post tạm và bổ sung checkpoint/lifecycle. Không backfill và không FK/cascade theo run/Post/user để lịch sử được giữ khi liên kết bị xóa.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up().
 * - down().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Schema hiện tại do migration runner chọn.
 * - OUTPUT: Bảng ai_article_archives và ba cột archive_version/generation_no/archive_pending_json.
 * - SIDE EFFECT: Thay schema bằng DDL; không gọi AI hoặc sửa nội dung cũ.
 * - EXCEPTION/TRANSACTION: Runner/driver quản lý transaction; rollback xóa kho/checkpoint, dữ liệu cần giữ nên dùng migration sửa tiến.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo schema kho approved và checkpoint tạm của AI import
     * =====================================================================
     *
     * INPUT:
     * - Schema ai_imports hiện có; migration runner gọi khi migration chưa áp dụng.
     *
     * OUTPUT:
     * - void: thêm ba cột lifecycle và tạo ai_article_archives với hash/index/unique key.
     *
     * SIDE EFFECT:
     * - Thực hiện DDL; không backfill, sửa nội dung ứng dụng hoặc gọi AI. Không FK/cascade theo run/Post/user để bản đã chọn tồn tại độc lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Transaction và thứ tự migration do runner/driver DB quản lý; lỗi schema truyền ra.
     *
     * =====================================================================
     */
    public function up(): void
    {
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('archive_version')->nullable();
            $table->unsignedInteger('generation_no')->default(1);
            $table->json('archive_pending_json')->nullable();
        });
        Schema::create('ai_article_archives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('parent_run_id')->nullable();
            $table->unsignedInteger('generation_no');
            $table->unsignedSmallInteger('snapshot_version');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('target_type', 80);
            $table->string('operation', 32);
            $table->string('generation_status', 24);
            $table->string('content_origin', 32);
            $table->boolean('has_generated_content')->default(false);
            $table->string('source_hash', 64)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('payload_hash', 64);
            $table->json('source_snapshot_json')->nullable();
            $table->json('draft_snapshot_json')->nullable();
            $table->json('context_snapshot_json');
            $table->json('diagnostics_json')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('generation_completed_at');
            $table->json('lifecycle_json')->nullable();
            $table->unsignedBigInteger('applied_target_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['run_id', 'generation_no'], 'ai_article_archive_generation_unique');
            $table->index(['has_generated_content', 'generation_completed_at'], 'ai_article_archive_evaluation_index');
            $table->index(['created_by', 'generation_completed_at'], 'ai_article_archive_actor_index');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hoàn tác schema kho AI và checkpoint của migration này
     * =====================================================================
     *
     * INPUT:
     * - Schema đã áp dụng migration kho approved.
     *
     * OUTPUT:
     * - void: xóa ai_article_archives và ba cột lifecycle khỏi ai_imports.
     *
     * SIDE EFFECT:
     * - DDL xóa kho lịch sử/checkpoint; không xóa Post, provenance hoặc các cột cũ khác.
     *
     * EXCEPTION/TRANSACTION:
     * - Runner/driver quản lý rollback; lỗi schema truyền ra. Dữ liệu cần giữ nên dùng migration sửa tiến.
     *
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_article_archives');
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->dropColumn(['archive_version', 'generation_no', 'archive_pending_json']);
        });
    }
};
