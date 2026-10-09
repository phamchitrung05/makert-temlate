<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo tracker dùng chung và backfill task cũ.
     * =====================================================================
     * INPUT: schema ai_imports/ai_writing_profile_analyses đã tồn tại.
 * OUTPUT: bảng ai_task_runs với projection lifecycle và dedupe key ổn định.
 * SIDE EFFECT: tạo bảng/index và chép metadata allowlist; không chép payload/secret.
 * EXCEPTION/TRANSACTION: DDL/insert do migration runner quản lý; insertOrIgnore an toàn khi chạy lại.
 * CÁC HÀM/METHOD TRONG FILE: up(): tạo/backfill tracker; down(): rollback bảng tracker.
 * =====================================================================
     */
    public function up(): void
    {
        Schema::create('ai_task_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedupe_key', 191)->unique();
            $table->string('task_type', 80)->index();
            $table->string('source', 100)->index();
            $table->string('model', 191)->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('status', 32)->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job_id', 191)->nullable()->index();
            $table->string('taskable_type', 191);
            $table->string('taskable_id', 191);
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['taskable_type', 'taskable_id']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        $now = now();
        foreach (DB::table('ai_writing_profile_analyses')->get() as $analysis) {
            $snapshot = json_decode((string) $analysis->connection_snapshot_json, true) ?: [];
            DB::table('ai_task_runs')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'dedupe_key' => 'writing-profile-analysis:'.$analysis->id,
                'task_type' => 'writing_profile_analysis', 'source' => 'ai_writing_profile',
                'model' => $snapshot['model'] ?? null, 'provider' => $snapshot['provider'] ?? null,
                'status' => $analysis->status, 'progress' => $analysis->status === 'ready' ? 100 : ($analysis->status === 'analyzing' ? 50 : 0),
                'user_id' => $analysis->created_by, 'job_id' => 'AnalyzeAiWritingProfileJob:'.$analysis->id,
                'taskable_type' => 'App\\Models\\AiWritingProfileAnalysis', 'taskable_id' => (string) $analysis->id,
                'error_code' => $analysis->error_code, 'error_message' => $analysis->error_code ? 'Tác vụ AI thất bại. Mở module tương ứng để kiểm tra cấu hình hoặc tạo lại.' : null,
                'metadata_json' => json_encode(['name' => $analysis->name, 'draft_profile_id' => $analysis->draft_profile_id]),
                'started_at' => $analysis->started_at, 'completed_at' => $analysis->completed_at, 'expires_at' => $analysis->expires_at,
                'created_at' => $analysis->created_at ?? $now, 'updated_at' => $analysis->updated_at ?? $now,
            ]);
        }
        foreach (DB::table('ai_imports')->get() as $import) {
            $input = json_decode((string) $import->input_json, true) ?: [];
            $isImage = ($import->operation ?? null) === 'image';
            $taskType = $isImage ? 'image_generation' : 'article_generation';
            $status = match (true) {
                in_array($import->status, ['completed', 'succeeded'], true) => 'ready',
                in_array($import->status, ['ready', 'failed', 'cancelled', 'expired'], true) => $import->status,
                $import->status === 'queued' => 'queued',
                default => 'processing',
            };
            DB::table('ai_task_runs')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'dedupe_key' => $taskType.':'.$import->id.':'.((int) ($import->generation_no ?: 1)),
                'task_type' => $taskType, 'source' => $isImage ? 'ai_image' : 'ai_content',
                'model' => data_get($input, 'ai_connection.model'), 'provider' => $import->provider ?: data_get($input, 'ai_connection.provider'),
                'status' => $status, 'progress' => (int) ($import->progress ?? 0),
                'user_id' => $import->created_by, 'job_id' => ($isImage ? 'ProcessAiImageGenerationJob:' : 'ProcessAiImportJob:').$import->id,
                'taskable_type' => 'App\\Models\\AiImport', 'taskable_id' => (string) $import->id,
                'error_code' => $import->error_code, 'error_message' => $import->error_code ? 'Tác vụ AI thất bại. Hãy kiểm tra cấu hình và thử lại.' : null,
                'metadata_json' => json_encode(['operation' => $import->operation, 'target_type' => data_get($input, 'target_type'), 'generation_no' => (int) ($import->generation_no ?: 1)]),
                'started_at' => $import->started_at, 'completed_at' => $import->completed_at, 'expires_at' => $import->expires_at,
                'created_at' => $import->created_at ?? $now, 'updated_at' => $import->updated_at ?? $now,
            ]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ tracker dùng chung khi rollback migration.
     * =====================================================================
     * INPUT: schema có thể có hoặc chưa có bảng ai_task_runs.
     * OUTPUT: bảng tracker được xóa an toàn.
     * SIDE EFFECT: xóa dữ liệu projection, không xóa nguồn nghiệp vụ.
     * EXCEPTION/TRANSACTION: Schema chịu transaction/DDL theo driver.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_task_runs');
    }
};
