<?php

namespace App\Console\Commands;

use App\Models\AiWritingProfileAnalysis;
use Illuminate\Console\Command;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn bài mẫu/tác vụ kỹ thuật hết retention, giữ profile đã duyệt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: handle().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : analysis expires_at trong database.
 * - OUTPUT: số tác vụ được dọn và exit code Artisan.
 * - SIDE EFFECT: xóa bài tham khảo/raw result quá hạn; profile tồn tại độc lập.
 * =====================================================================
 */
final class CleanupAiWritingProfileAnalysesCommand extends Command
{
    protected $signature = 'ai:cleanup-writing-profile-analyses';

    protected $description = 'Dọn phân tích văn phong và bài tham khảo hết thời hạn';

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa analysis quá hạn theo lô, không đọc profile/snapshot run.
     * =====================================================================
     * Input: thời điểm hiện tại. Output: SUCCESS và tổng đã xóa.
     * Side effect: xóa analysis, không xóa profile đã duyệt hoặc Post.
     * =====================================================================
     */
    public function handle(): int
    {
        $count = AiWritingProfileAnalysis::query()->where('expires_at', '<=', now())->delete();
        $this->info("Đã dọn {$count} phân tích văn phong hết hạn.");

        return self::SUCCESS;
    }
}
