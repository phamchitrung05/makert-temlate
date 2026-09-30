<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mở rộng vòng đời và dữ liệu audit cho AI import.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): thêm tiến trình, input/source metadata và idempotency index.
 * - down(): rollback các cột/index bổ sung.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema `ai_imports` cũ.
 * - OUTPUT: schema có thể polling/retry/cleanup.
 * - SIDE EFFECT: thay đổi schema; không sửa migration tạo bảng ban đầu.
 * - EXCEPTION/TRANSACTION: migration runner quản lý transaction tùy driver.
 */
return new class extends Migration
{
    /**
     * INPUT: database schema hiện tại.
     * OUTPUT: lifecycle/source/idempotency columns mới.
     * SIDE EFFECT: ALTER TABLE và tạo composite index.
     * EXCEPTION/TRANSACTION: migration runner quản lý transaction.
     */
    public function up(): void
    {
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->string('normalized_url', 2048)->nullable()->after('source_url');
            $table->string('source_hash', 64)->nullable()->after('normalized_url');
            $table->string('current_step', 32)->nullable()->after('status');
            $table->unsignedTinyInteger('progress')->default(0)->after('current_step');
            $table->json('input_json')->nullable()->after('progress');
            $table->json('source_meta_json')->nullable()->after('input_json');
            $table->string('error_code', 80)->nullable()->after('error_message');
            $table->timestamp('started_at')->nullable()->after('prompt_version');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->index(['created_by', 'source_hash', 'prompt_version'], 'ai_imports_idempotency_index');
        });
    }

    /**
     * INPUT: database đã chạy `up()`.
     * OUTPUT: schema quay về trước migration.
     * SIDE EFFECT: drop columns/index bổ sung; dữ liệu lifecycle mất.
     * EXCEPTION/TRANSACTION: migration runner quản lý rollback.
     */
    public function down(): void
    {
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->dropIndex('ai_imports_idempotency_index');
            $table->dropColumn([
                'normalized_url', 'source_hash', 'current_step', 'progress',
                'input_json', 'source_meta_json', 'error_code', 'started_at',
                'completed_at',
            ]);
        });
    }
};
