<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bổ sung candidate lineage và audit nguồn AI, không tạo pipeline mới.
 * CÁC HÀM/METHOD: up(), down(). INPUT: schema hiện tại. OUTPUT: nhóm run và provenance.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng `ai_imports` đã có lifecycle fields.
 * - OUTPUT: session/parent/applied fields và bảng `ai_provenances`.
 * - SIDE EFFECT: thay đổi schema; rollback sẽ mất lịch sử AI bổ sung, không mất Post.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Thêm lineage fields và bảng provenance
     * =====================================================================
     * INPUT: schema database hiện tại.
     * OUTPUT: cột nullable tương thích import cũ và bảng audit provenance.
     * SIDE EFFECT: tạo index/cột/bảng; không thay đổi dữ liệu Post.
     * EXCEPTION/TRANSACTION: migration runner quản lý transaction tùy driver.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('parent_id')->nullable();
            $table->string('operation', 32)->default('create');
            $table->unsignedBigInteger('applied_target_id')->nullable();
            $table->json('applied_fields')->nullable();
        });
        Schema::create('ai_provenances', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id');
            $table->string('field', 80);
            $table->uuid('run_id');
            $table->string('provider', 80);
            $table->string('model')->nullable();
            $table->string('prompt_key')->nullable();
            $table->string('prompt_version', 80)->nullable();
            $table->string('value_hash', 64);
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['target_type', 'target_id', 'field']);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback lineage và provenance schema
     * =====================================================================
     * INPUT: database đã chạy `up()`.
     * OUTPUT: schema quay về trước migration; audit bổ sung không khôi phục được.
     * SIDE EFFECT: drop bảng/cột AI lineage; không xóa Post.
     * EXCEPTION/TRANSACTION: migration runner quản lý rollback; dữ liệu audit bị mất.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provenances');
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->dropIndex(['session_id']);
            $table->dropColumn(['session_id', 'parent_id', 'operation', 'applied_target_id', 'applied_fields']);
        });
    }
};
