<?php

namespace App\Console\Commands;

use App\Services\Ai\WritingProfiles\WritingProfileAnalysisService;
use Illuminate\Console\Command;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bù profile nháp cho analysis đã hoàn thành nhưng thiếu liên kết.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: handle().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : analysis ready đã có result_json và tùy chọn UUID cần đồng bộ.
 * - OUTPUT: thống kê profile được tạo/liên kết/bỏ qua.
 * - SIDE EFFECT: ghi database qua service, không gọi provider AI.
 * =====================================================================
 */
final class SyncAiWritingProfileDraftsCommand extends Command
{
    protected $signature = 'ai:sync-writing-profile-drafts {--analysis= : Chỉ đồng bộ một analysis UUID}';

    protected $description = 'Bù profile nháp cho các phân tích văn phong đã hoàn thành';

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy đồng bộ idempotent cho các analysis ready còn thiếu draft.
     * =====================================================================
     * Input: tùy chọn --analysis. Output: SUCCESS cùng số lượng từng kết quả.
     * Side effect: tạo/liên kết profile nháp; không gửi request AI.
     * =====================================================================
     */
    public function handle(WritingProfileAnalysisService $service): int
    {
        $counts = $service->syncReadyDrafts($this->option('analysis'));
        $this->info("Đã tạo {$counts['created']} draft, liên kết {$counts['linked']} profile và bỏ qua {$counts['skipped']} analysis.");

        return self::SUCCESS;
    }
}
