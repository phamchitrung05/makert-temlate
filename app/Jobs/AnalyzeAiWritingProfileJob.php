<?php

namespace App\Jobs;

use App\Exceptions\AiImportException;
use App\Models\AiWritingProfileAnalysis;
use App\Services\Ai\WritingProfiles\WritingProfileAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker phân tích bài mẫu, lưu lỗi an toàn và không tự thử lại.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), handle(), failed(), markFailed().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID analysis và timeout server-side; không serialize bài mẫu/key.
 * - OUTPUT: lifecycle tác vụ cập nhật để polling.
 * - SIDE EFFECT: gọi analysis service trong queue; lỗi không tự tạo profile.
 * =====================================================================
 */
final class AnalyzeAiWritingProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ UUID và ngân sách job lớn hơn request timeout.
     * =====================================================================
     * Input: analysisId/requestTimeout. Output: job chỉ chứa scalar không secret.
     * Side effect: không ghi DB/gọi provider.
     * =====================================================================
     */
    public function __construct(public readonly string $analysisId, int $requestTimeout = 30)
    {
        $this->timeout = max(60, $requestTimeout + 45);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thực hiện analysis, chuyển mọi lỗi sang thông báo bounded.
     * =====================================================================
     * Input: analysis service từ container. Output: void; ready/failed/cancelled.
     * Side effect: provider call trong service; không log raw body hoặc secret.
     * =====================================================================
     */
    public function handle(WritingProfileAnalysisService $service): void
    {
        try {
            $service->process($this->analysisId);
        } catch (Throwable $exception) {
            $this->markFailed($exception);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu trạng thái failed khi worker bị timeout hoặc crash.
     * =====================================================================
     * Input: Throwable nullable do queue cung cấp. Output: lifecycle failed an toàn.
     * Side effect: ghi DB; không ghi đè cancelled hoặc ready.
     * =====================================================================
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Persist lỗi domain hoặc message chung, không expose exception gốc.
     * =====================================================================
     * Input: exception. Output: void; error_code/error_message bounded.
     * Side effect: cập nhật analysis còn active, không auto retry.
     * =====================================================================
     */
    private function markFailed(?Throwable $exception): void
    {
        $domain = $exception instanceof AiImportException ? $exception : null;
        AiWritingProfileAnalysis::query()->whereKey($this->analysisId)->whereIn('status', ['queued', 'analyzing'])->update([
            'status' => 'failed', 'error_code' => $domain?->errorCode ?? 'AI_WRITING_PROFILE_FAILED',
            'error_message' => mb_substr($domain?->getMessage() ?? 'Không thể phân tích bài mẫu. Hãy kiểm tra model/kết nối và tạo tác vụ mới.', 0, 1000),
            'completed_at' => now(), 'updated_at' => now(),
        ]);
    }
}
