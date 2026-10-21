<?php

namespace App\Jobs;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\VisualStyleAnalyzerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker phân tích ảnh mẫu thành Style DNA, không tự retry.
 * =====================================================================
 * Job nhận profile id + timeout, gọi VisualStyleAnalyzerService->process()
 * trong queue worker. Mọi lỗi (provider, validate DNA, ảnh đổi) được quy về
 * status failed với message an toàn, không expose exception gốc cho client.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận profile id và timeout worker.
 * - handle(): chạy service; lỗi chuyển sang failed().
 * - failed(): ghi status=failed khi job lỗi hoặc timeout.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : id AiImageProfile đã queue; không serialize ảnh/key/secret.
 * - OUTPUT: lifecycle profile analyzed/failed để UI polling.
 * - SIDE EFFECT: một provider call trong service; lỗi ghi error_message bounded.
 * =====================================================================
 */
final class AnalyzeVisualStyleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ id profile và ngân sách job lớn hơn request timeout.
     * =====================================================================
     * Input: profileId/requestTimeout. Output: job chỉ chứa scalar không secret.
     * Side effect: không ghi DB/gọi provider.
     * =====================================================================
     */
    public function __construct(public readonly int $profileId, int $requestTimeout = 30)
    {
        $this->timeout = max(60, $requestTimeout + 45);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thực hiện phân tích, chuyển mọi lỗi sang lifecycle failed.
     * =====================================================================
     * Input: analyzer service từ container. Output: void; analyzed hoặc failed.
     * Side effect: provider call trong service; không log raw body/secret.
     * =====================================================================
     */
    public function handle(VisualStyleAnalyzerService $service): void
    {
        try {
            $service->process($this->profileId);
        } catch (\Throwable $exception) {
            $this->failed($exception);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu trạng thái failed khi worker lỗi hoặc bị timeout.
     * =====================================================================
     * Input: Throwable nullable do queue cung cấp. Output: status failed an toàn.
     * Side effect: UPDATE profile còn queued/analyzing; không ghi đè analyzed/
     * active/archived và không expose message nội bộ ra client.
     * =====================================================================
     */
    public function failed(?\Throwable $exception): void
    {
        AiImageProfile::query()->whereKey($this->profileId)->whereIn('status', ['queued', 'analyzing'])->update([
            'status' => 'failed',
            'error_message' => 'Không thể phân tích phong cách từ ảnh mẫu. Hãy kiểm tra model Vision và kết nối, rồi tạo tác vụ mới.',
            'updated_at' => now(),
        ]);
    }
}
