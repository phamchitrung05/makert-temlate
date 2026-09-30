<?php

namespace App\Jobs;

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
 * CHỨC NĂNG FILE: Job xử lý pipeline AI import có thể chạy trong queue.
 * =====================================================================
 * Job cập nhật trạng thái AiImport và giữ retry giới hạn cho worker.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận UUID import.
 * - handle(): chạy service và ghi completed/failed.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID bản ghi AiImport.
 * - OUTPUT: cập nhật DB; ném lại exception để queue retry.
 * =====================================================================
 */
class ProcessAiImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Input: UUID AiImport. Output: job đã chứa định danh xử lý. */
    public function __construct(public readonly string $importId) {}

    /** Input: ArticleImportService từ container. Output: cập nhật trạng thái import. */
    public function handle(ArticleImportService $service): void
    {
        $import = AiImport::query()->find($this->importId);
        if (! $import || $import->status === 'completed') {
            return;
        }
        try {
            $result = $service->run($import);
            $import->update(['status' => 'completed', 'result_json' => $result, 'provider' => $result['provider'], 'prompt_version' => $result['prompt_version'], 'expires_at' => now()->addDay()]);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'error_message' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }
    }
}
