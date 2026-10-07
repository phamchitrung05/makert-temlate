<?php

namespace App\Console\Commands;

use App\Exceptions\AiArticleArchiveException;
use App\Models\AiImport;
use App\Services\Ai\Content\Archives\AiArticleArchiveService;
use App\Services\Ai\Runs\AiRunAssetCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn AI import hết hạn và thumbnail tạm chưa attach.
 * =====================================================================
 *
 * Scheduler/CLI dọn run hết hạn và asset tạm chưa có usage. Candidate chưa chọn cùng checkpoint bị xóa; archive của bản approved v1 cần hợp lệ trước khi dọn. Không chạm Post hoặc provenance đã Apply.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Cấu hình retention, ai_imports/media_assets và service kho/asset cleaner.
 * - OUTPUT: Số run đã dọn/bỏ qua cùng lý do và exit code.
 * - SIDE EFFECT: Xóa run/asset chưa attach theo chunk; không tạo archive cho candidate chưa duyệt.
 * - EXCEPTION/TRANSACTION: Process lock/row lock và transaction từng run; lỗi kho giữ run, lỗi cleanup tổng hợp, worker bận được bỏ qua.
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
     * - int: SUCCESS khi quét an toàn; FAILURE nếu giữ run vì lỗi kho/cleanup.
     * SIDE EFFECT:
     * - Khóa, đối chiếu archive, xóa thumbnail chưa attach rồi xóa run theo chunk.
     * EXCEPTION/TRANSACTION:
     * - Transaction từng run; rollback giữ dữ liệu phục hồi khi archive lỗi.
     * =====================================================================
     */
    public function handle(): int
    {
        $count = 0;
        $skipped = [];
        AiImport::query()->where(function ($query): void {
            $query->where('expires_at', '<', now())->orWhere(function ($nested): void {
                $nested->whereIn('status', AiImport::RUNNING_STATUSES)->where('created_at', '<', now()->subDays((int) config('ai-import.retention_days', 2)));
            });
        })->chunkById(100, function ($imports) use (&$count, &$skipped): void {
            foreach ($imports as $import) {
                try {
                    $removed = Cache::lock('ai-import-process-'.$import->id, 10)->get(fn () => DB::transaction(function () use ($import): bool {
                        $run = AiImport::query()->lockForUpdate()->find($import->id);
                        if (! $run || ! ($run->expires_at?->isPast()
                            || (in_array($run->status, AiImport::RUNNING_STATUSES, true)
                                && $run->created_at->lt(now()->subDays((int) config('ai-import.retention_days', 2)))))) {
                            return false;
                        }
                        if (in_array($run->status, AiImport::RUNNING_STATUSES, true)) {
                            $run->forceFill(['status' => 'expired', 'current_step' => 'expired',
                                'error_code' => 'EXPIRED', 'completed_at' => now()])->save();
                        }
                        app(AiArticleArchiveService::class)->preserve($run, removalReason: 'retention_cleanup');
                        app(AiRunAssetCleaner::class)->cleanup($run);
                        $run->delete();

                        return true;
                    }));
                    if ($removed) {
                        $count++;
                    } else {
                        $skipped['busy_or_changed'] = ($skipped['busy_or_changed'] ?? 0) + 1;
                    }
                } catch (AiArticleArchiveException $exception) {
                    $skipped[$exception->reason] = ($skipped[$exception->reason] ?? 0) + 1;
                } catch (Throwable) {
                    $skipped['cleanup_failed'] = ($skipped['cleanup_failed'] ?? 0) + 1;
                }
            }
        }, 'id');
        $this->info("Đã dọn {$count} AI import.");
        foreach ($skipped as $reason => $total) {
            $this->warn("Đã giữ lại {$total} AI import: {$reason}.");
        }

        return array_diff(array_keys($skipped), ['busy_or_changed']) === [] ? self::SUCCESS : self::FAILURE;
    }
}
