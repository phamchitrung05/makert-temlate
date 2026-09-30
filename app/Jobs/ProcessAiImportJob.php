<?php

namespace App\Jobs;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\ArticleImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker queue xử lý pipeline AI import có retry có kiểm soát.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), handle(), failed().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID AiImport được dispatch từ controller/command.
 * - OUTPUT: trạng thái ready/failed/cancelled và result JSON.
 * - SIDE EFFECT: gọi outbound provider, fetch nguồn và tạo thumbnail asset.
 * - EXCEPTION/TRANSACTION: queue retry tối đa 3 lần, backoff tăng dần; lỗi
 *   input/schema không retry và lifecycle được ghi terminal.
 */
class ProcessAiImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public array $backoff = [10, 60, 180];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo job xử lý một AiImport
     * =====================================================================
     * INPUT: UUID import cần xử lý.
     * OUTPUT: job có timeout đọc từ config.
     * SIDE EFFECT: không truy cập database/provider khi khởi tạo.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function __construct(public readonly string $importId)
    {
        $this->timeout = (int) config('ai-import.job_timeout', 120);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy pipeline và cập nhật lifecycle AiImport
     * =====================================================================
     * INPUT: ArticleImportService từ container.
     * OUTPUT: không trả giá trị; ghi ready/failed/cancelled vào AiImport.
     * SIDE EFFECT: gọi fetch/provider/uploader qua service và cập nhật progress.
     * EXCEPTION/TRANSACTION: lỗi retryable được ném lại cho queue; không mở
     *   transaction bao trùm toàn bộ pipeline.
     * =====================================================================
     */
    public function handle(ArticleImportService $service): void
    {
        $import = AiImport::query()->find($this->importId);
        if (! $import || in_array($import->status, ['ready', 'failed', 'cancelled', 'expired'], true)) {
            return;
        }
        try {
            $result = $service->run($import);
            if ($import->fresh()?->status === 'cancelled') {
                return;
            }
            $import->update([
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                'result_json' => $result, 'provider' => $result['provider'],
                'prompt_version' => $result['prompt_version'], 'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ]);
        } catch (AiImportException $exception) {
            $import->refresh();
            if ($exception->errorCode === 'CANCELLED' || $import->status === 'cancelled') {
                $import->update(['status' => 'cancelled', 'current_step' => 'cancelled', 'error_code' => 'CANCELLED', 'error_message' => $exception->getMessage()]);

                return;
            }
            $import->update(['error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500)]);
            if (! $exception->retryable || $this->attempts() >= $this->tries) {
                $import->update(['status' => 'failed', 'current_step' => 'failed']);

                return;
            }
            throw $exception;
        } catch (Throwable $exception) {
            $import->update(['error_code' => 'AI_IMPORT_FAILED', 'error_message' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi trạng thái failed sau khi queue hết attempt
     * =====================================================================
     * INPUT: throwable terminal hoặc null.
     * OUTPUT: không trả giá trị; AiImport chuyển sang failed nếu chưa ready/cancelled.
     * SIDE EFFECT: ghi mã/thông báo lỗi đã giới hạn độ dài, không lộ secret.
     * EXCEPTION/TRANSACTION: không mở transaction; queue worker quản lý lifecycle.
     * =====================================================================
     */
    public function failed(?Throwable $exception): void
    {
        AiImport::query()->whereKey($this->importId)->whereNotIn('status', ['ready', 'cancelled'])->update([
            'status' => 'failed', 'current_step' => 'failed', 'error_code' => $exception instanceof AiImportException ? $exception->errorCode : 'AI_IMPORT_FAILED',
            'error_message' => Str::limit($exception?->getMessage() ?: 'Import thất bại.', 500),
        ]);
    }
}
