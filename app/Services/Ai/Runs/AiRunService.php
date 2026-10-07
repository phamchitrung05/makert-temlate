<?php

namespace App\Services\Ai\Runs;

use App\Jobs\ProcessAiImageGenerationJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Services\Ai\WritingProfiles\WritingProfileSnapshotService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Boundary tạo run, quota, idempotency và dispatch text/image.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: create(), snapshotInput(), dispatch(), ensureAsyncQueue().
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : actor và attributes nội bộ đã được resolver/request whitelist.
 * - OUTPUT: AiImport queued hoặc run trùng còn khả dụng; profile/config bất biến.
 *   Post text mới bật archive v1/generation 1; image/target khác có lifecycle riêng.
 * - SIDE EFFECT: cache lock theo actor, DB write atomic và queue dispatch.
 * - EXCEPTION/TRANSACTION: HTTP 429/409/503; không gọi provider trong transaction.
 * =====================================================================
 */
final class AiRunService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Xếp hàng run mới, chống double-click trước khi áp quota
     * =====================================================================
     * INPUT: actor ID, run attributes; fresh=true khi admin yêu cầu candidate mới.
     * OUTPUT: AiImport persisted; connection snapshot private không trả qua API.
     * SIDE EFFECT: khóa theo actor (chờ tối đa một giây), ghi metadata trong transaction và dispatch sau commit.
     * EXCEPTION/TRANSACTION: 429 nếu quota giờ đầy, 409 nếu đang tạo run, 503 khi tắt AI.
     * =====================================================================
     */
    public function create(int $actorId, array $attributes, bool $fresh = false): AiImport
    {
        if (! config('ai-import.enabled', true)) {
            throw new HttpException(503, 'AI đang tắt.');
        }
        $this->ensureAsyncQueue();
        $attributes = $this->snapshotInput($attributes);
        try {
            return Cache::lock('ai-run-create-'.$actorId, 10)->block(1, function () use ($actorId, $attributes, $fresh): AiImport {
                if (! $fresh) {
                    $existing = AiImport::query()->where('created_by', $actorId)
                        ->where('source_hash', $attributes['source_hash'])
                        ->where('created_at', '>=', now()->subMinutes((int) config('ai-import.idempotency_window_minutes', 30)))
                        ->whereNotIn('status', ['failed', 'cancelled', 'expired'])->latest()->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                if (AiImport::query()->where('created_by', $actorId)->where('created_at', '>=', now()->subHour())->count()
                    >= (int) config('ai-import.quota_per_hour', 20)) {
                    throw new HttpException(429, 'Bạn đã đạt giới hạn tác vụ AI trong giờ này.');
                }
                $import = DB::transaction(function () use ($actorId, $attributes): AiImport {
                    $import = AiImport::query()->create(array_merge($attributes, [
                        'created_by' => $actorId, 'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
                        'generation_no' => 1,
                        'archive_version' => ($attributes['operation'] ?? 'create') !== 'image'
                            && data_get($attributes, 'input_json.target_type', 'post') === 'post' ? 1 : null,
                        'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                    ]));
                    $import->forceFill(['session_id' => $attributes['session_id'] ?? $import->id])->save();

                    return $import;
                });
                DB::afterCommit(fn () => $this->dispatch($import));

                return $import;
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Một tác vụ AI khác đang được xếp hàng. Hãy thử lại sau.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chụp profile/config trước quota, idempotency và queue dispatch
     * =====================================================================
     * INPUT: run attributes đã được HTTP boundary allowlist.
     * OUTPUT: input có profile/config snapshot; hash thay khi lựa chọn/version đổi.
     * SIDE EFFECT: đọc profile/default settings; không gọi provider hoặc tạo Post.
     * EXCEPTION/TRANSACTION: ValidationException khi profile explicit không khả dụng.
     * =====================================================================
     */
    private function snapshotInput(array $attributes): array
    {
        if (($attributes['operation'] ?? 'create') === 'image') {
            return $attributes;
        }
        $input = (array) ($attributes['input_json'] ?? []);
        if (! array_key_exists('writing_profile_snapshot', $input)) {
            $input['writing_profile_snapshot'] = app(WritingProfileSnapshotService::class)->snapshot(
                isset($input['writing_profile_id']) ? (int) $input['writing_profile_id'] : null,
            );
        }
        $input['pipeline_snapshot'] ??= (array) config('ai-content', []);
        $input['pipeline_snapshot']['pipeline'] = 'three_step';
        $input['pipeline_snapshot']['output_definitions'] ??= (array) config('ai-agent.output_definitions', []);
        $input['taxonomy_origin'] = 'manual';
        $attributes['input_json'] = $input;
        $attributes['source_hash'] = hash('sha256', ($attributes['source_hash'] ?? '').'|'.json_encode([
            $input['writing_profile_snapshot'], $input['pipeline_snapshot'],
            $input['category_ids'] ?? [], $input['tag_ids'] ?? [], $input['writing_brief'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $attributes;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dispatch job nội dung hoặc ảnh theo operation của run
     * =====================================================================
     * INPUT: AiImport đã được lưu.
     * OUTPUT: Queue job mang UUID và ngân sách HTTP của run, không mang API key.
     * SIDE EFFECT: Dispatch ProcessAiImageGenerationJob hoặc ProcessAiImportJob.
     * EXCEPTION/TRANSACTION: Không mở transaction riêng; caller dispatch sau khi ghi run.
     * =====================================================================
     */
    public function dispatch(AiImport $import): void
    {
        $this->ensureAsyncQueue();
        $requestTimeout = (int) data_get($import->input_json, 'ai_connection.timeout', 30);
        if ($import->operation === 'image') {
            ProcessAiImageGenerationJob::dispatch($import->id, $requestTimeout);
        } else {
            ProcessAiImportJob::dispatch($import->id, $requestTimeout, AiRunBudget::calls((array) $import->input_json), (int) $import->generation_no);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Yêu cầu queue bất đồng bộ trước khi tạo hoặc dispatch run
     * =====================================================================
     * INPUT: cấu hình queue hiện tại. OUTPUT: không trả giá trị khi hợp lệ.
     * SIDE EFFECT: không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: lỗi 422 nếu cấu hình chạy inline hoặc bỏ job.
     * =====================================================================
     */
    private function ensureAsyncQueue(): void
    {
        $connection = (string) config('queue.default');
        if (in_array(config('queue.connections.'.$connection.'.driver', $connection), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['queue' => 'Tạo nội dung AI cần queue bất đồng bộ. Hãy cấu hình database hoặc redis và chạy worker.']);
        }
    }
}
