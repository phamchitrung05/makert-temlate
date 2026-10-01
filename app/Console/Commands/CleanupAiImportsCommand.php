<?php

namespace App\Console\Commands;

use App\Models\AiImport;
use App\Services\Ai\AiRunAssetCleaner;
use Illuminate\Console\Command;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn AI import hết hạn và thumbnail tạm chưa attach.
 * =====================================================================
 *
 * Command được scheduler/CLI gọi định kỳ để không giữ candidate và asset tạm
 * quá thời gian retention. Command chỉ xóa asset chưa có usage, không chạm Post
 * hoặc provenance đã Apply.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): quét import hết hạn, dọn thumbnail orphan và trả exit code.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : cấu hình retention và bảng `ai_imports`/`media_assets`.
 * - OUTPUT: số import đã dọn và mã thành công/thất bại của Artisan.
 * - SIDE EFFECT: xóa import hết hạn và MediaAsset không có usage.
 * =====================================================================
 */
class CleanupAiImportsCommand extends Command
{
    protected $signature = 'ai-import:cleanup';

    protected $description = 'Dọn các AI import hết hạn và thumbnail chưa attach';

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn các import hết hạn theo retention policy
     * =====================================================================
     * INPUT:
     * - Không nhận tham số; đọc `ai-import.retention_days` và `expires_at`.
     * OUTPUT:
     * - int: `self::SUCCESS` sau khi quét xong.
     * SIDE EFFECT:
     * - Xóa thumbnail chưa attach rồi xóa bản ghi `AiImport` theo từng chunk.
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction tổng; mỗi thao tác Eloquent có thể rollback độc lập
     *   nếu queue/CLI bị dừng.
     * =====================================================================
     */
    public function handle(): int
    {
        $count = 0;
        AiImport::query()->where(function ($query): void {
            $query->where('expires_at', '<', now())->orWhere(function ($nested): void {
                $nested->whereIn('status', ['queued', 'fetching', 'extracting', 'rewriting', 'seo', 'thumbnail'])->where('created_at', '<', now()->subDays((int) config('ai-import.retention_days', 2)));
            });
        })->chunkById(100, function ($imports) use (&$count): void {
            foreach ($imports as $import) {
                app(AiRunAssetCleaner::class)->cleanup($import);
                $import->delete();
                $count++;
            }
        }, 'id');
        $this->info("Đã dọn {$count} AI import.");

        return self::SUCCESS;
    }
}
