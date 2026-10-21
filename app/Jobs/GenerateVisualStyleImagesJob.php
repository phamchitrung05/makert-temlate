<?php

namespace App\Jobs;

use App\Models\AiImport;
use App\Services\Ai\VisualStyle\VisualStyleGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker sinh ảnh Visual Style theo từng Shot Recipe.
 * =====================================================================
 * Job nhận UUID AiImport operation visual_style_generate và gọi
 * VisualStyleGenerationService->process() trong queue worker. Service tự
 * xử lý per-shot status nên job chỉ cần nhận id; mọi lỗi terminal được quy về
 * status failed an toàn, không expose exception ra client.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận import UUID và timeout worker.
 * - handle(): chạy service; lỗi bất ngờ chuyển sang failed().
 * - failed(): ghi status=failed khi job exception/timeout.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID AiImport đã queue; không serialize connection/key.
 * - OUTPUT: import ready/failed với outputs per-shot trong result_json.
 * - SIDE EFFECT: service gọi provider và ghi MediaAsset; job chỉ orchestrate.
 * =====================================================================
 */
final class GenerateVisualStyleImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ UUID import và ngân sách job lớn hơn provider timeout.
     * =====================================================================
     * Input: importId/requestTimeout. Output: job chỉ chứa scalar không secret.
     * Side effect: không ghi DB/gọi provider.
     * =====================================================================
     */
    public function __construct(public readonly string $importId, int $requestTimeout = 60)
    {
        // Mỗi shot × count là một provider call tuần tự; đệm đủ cho nhiều shot.
        $this->timeout = max(300, $requestTimeout * 4 + 60);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thực hiện sinh ảnh per-shot; lỗi chuyển sang lifecycle failed.
     * =====================================================================
     * Input: service từ container. Output: void; ready/partial/failed.
     * Side effect: nhiều provider call + upload media trong service; không
     * log raw body/secret.
     * =====================================================================
     */
    public function handle(VisualStyleGenerationService $service): void
    {
        try {
            $service->process($this->importId);
        } catch (\Throwable $exception) {
            $this->failed($exception);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu trạng thái failed khi worker lỗi hoặc bị timeout.
     * =====================================================================
     * Input: Throwable nullable do queue cung cấp. Output: import failed an
     * toàn nếu vẫn active; không ghi đè ready/cancelled.
     * =====================================================================
     */
    public function failed(?\Throwable $exception): void
    {
        AiImport::query()->whereKey($this->importId)
            ->whereNotIn('status', AiImport::TERMINAL_STATUSES)
            ->update([
                'status' => 'failed',
                'current_step' => 'failed',
                'progress' => 100,
                'error_code' => 'AI_VISUAL_STYLE_JOB_FAILED',
                'error_message' => 'Không thể hoàn thành tác vụ sinh ảnh. Hãy kiểm tra kết nối image provider rồi thử lại.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
