<?php

namespace App\Services\Ai;

use App\Jobs\ProcessAiImageGenerationJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Boundary tạo run, quota, idempotency và dispatch text/image.
 * =====================================================================
 * CÁC HÀM/METHOD: create(), dispatch().
 * INPUT: actor và attributes nội bộ đã được resolver/request whitelist.
 * OUTPUT: AiImport queued hoặc run trùng còn khả dụng.
 * SIDE EFFECT: cache lock theo actor, DB write atomic và queue dispatch.
 * EXCEPTION/TRANSACTION: HTTP 429/409/503 an toàn; không gọi provider trong DB transaction.
 * =====================================================================
 */
final class AiRunService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Xếp hàng run mới, chống double-click trước khi áp quota
     * =====================================================================
     * INPUT: actor ID, run attributes; fresh=true khi admin yêu cầu candidate mới.
     * OUTPUT: AiImport persisted, snapshot không chứa API key.
     * SIDE EFFECT: khóa theo actor (chờ tối đa một giây), ghi metadata trong transaction và dispatch sau commit.
     * EXCEPTION/TRANSACTION: 429 nếu quota giờ đầy, 409 nếu đang tạo run, 503 khi tắt AI.
     * =====================================================================
     */
    public function create(int $actorId, array $attributes, bool $fresh = false): AiImport
    {
        if (! config('ai-import.enabled', true)) {
            throw new HttpException(503, 'AI đang tắt.');
        }
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
                        'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                    ]));
                    $import->forceFill(['session_id' => $attributes['session_id'] ?? $import->id])->save();

                    return $import;
                });
                $this->dispatch($import);

                return $import;
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Một tác vụ AI khác đang được xếp hàng. Hãy thử lại sau.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dispatch job nội dung hoặc ảnh theo operation của run
     * =====================================================================
     * INPUT: AiImport đã được lưu.
     * OUTPUT: Queue job mang UUID run, không mang API key.
     * SIDE EFFECT: Dispatch ProcessAiImageGenerationJob hoặc ProcessAiImportJob.
     * EXCEPTION/TRANSACTION: Không mở transaction riêng; caller dispatch sau khi ghi run.
     * =====================================================================
     */
    public function dispatch(AiImport $import): void
    {
        if ($import->operation === 'image') {
            ProcessAiImageGenerationJob::dispatch($import->id);
        } else {
            ProcessAiImportJob::dispatch($import->id);
        }
    }
}
