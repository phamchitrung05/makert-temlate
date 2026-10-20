<?php

namespace App\Console\Commands;

use App\Exceptions\AiArticleArchiveException;
use App\Models\AiImport;
use App\Services\Ai\Content\Archives\AiArticleArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Phục hồi archive/checkpoint đã được duyệt có giới hạn.
 * =====================================================================
 *
 * Command phục hồi/kiểm kê chỉ đọc run Post đã được duyệt. Cờ dry-run không ghi; không gọi AI hoặc enqueue.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : CLI run-id/limit/dry-run và service archive.
 * - OUTPUT: Số run kiểm tra/lý do và exit code.
 * - SIDE EFFECT: Đọc có giới hạn theo id; ngoài dry-run có thể lưu kho approved.
 * - EXCEPTION/TRANSACTION: Process lock tránh worker/retry; service dùng row lock/transaction, lỗi kho tổng hợp theo mã.
 * =====================================================================
 */
class ArchiveAiArticlesCommand extends Command
{
    protected $signature = 'ai-articles:archive {--run-id=*} {--limit=100} {--dry-run}';

    protected $description = 'Phục hồi kho bản gốc AI đã duyệt, không gọi provider';

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm kê hoặc phục hồi kho cho run Post đã được duyệt
     * =====================================================================
     *
     * INPUT:
     * - Service archive và CLI run-id UUID/limit 1..1000/dry-run.
     *
     * OUTPUT:
     * - Exit SUCCESS khi kiểm an toàn, INVALID khi options sai hoặc FAILURE khi lỗi kho; in số lượng/lý do.
     *
     * SIDE EFFECT:
     * - Query Post non-image đã approved, terminal hoặc có checkpoint; lọc IDs, sort id, giới hạn số row, không pagination/eager load. Dry-run không ghi; ngoài dry-run ghi kho approved, không enqueue/gọi AI.
     *
     * EXCEPTION/TRANSACTION:
     * - Process cache lock từng run tránh worker/retry; service dùng transaction/row lock. Lỗi archive được tổng hợp theo mã, worker bận được bỏ qua.
     *
     * =====================================================================
     */
    public function handle(AiArticleArchiveService $archives): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $ids = (array) $this->option('run-id');
        if ($limit === false || count($ids) > 1000 || array_filter($ids, fn (string $id): bool => ! Str::isUuid($id)) !== []) {
            $this->error('Limit phải từ 1 đến 1000; run-id phải là UUID, tối đa 1000 mã.');

            return self::INVALID;
        }
        $query = AiImport::query()
            ->where(fn ($query) => $query->where('input_json->target_type', 'post')->orWhereNull('input_json->target_type'))
            ->where(fn ($query) => $query->where('operation', '!=', 'image')->orWhereNull('operation'))
            ->where(fn ($query) => $query->whereNotNull('applied_target_id')->orWhere('source_meta_json->editorial->status', 'approved'))
            ->where(fn ($query) => $query->whereIn('status', AiImport::TERMINAL_STATUSES)->orWhereNotNull('archive_pending_json'))
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids));
        $totals = [];
        $failed = false;
        foreach ($query->orderBy('id')->limit($limit)->get() as $run) {
            if ($this->option('dry-run')) {
                $kind = 'eligible_approved';
            } else {
                try {
                    $kind = Cache::lock('ai-import-process-'.$run->id, 10)->get(function () use ($run, $archives): string {
                        $archive = $archives->archiveApproved($run->id, (int) $run->generation_no);

                        return $archive ? 'preserved_approved' : 'no_approved_archive';
                    });
                    $kind = $kind === false ? 'worker_busy' : $kind;
                } catch (AiArticleArchiveException $exception) {
                    $kind = $exception->reason;
                    $failed = true;
                }
            }
            $totals[$kind] = ($totals[$kind] ?? 0) + 1;
        }
        $this->info(($this->option('dry-run') ? 'Dry-run; không ghi dữ liệu. ' : '').'Đã kiểm tra '.array_sum($totals).' run (giới hạn '.$limit.').');
        foreach ($totals as $kind => $total) {
            $this->line("{$kind}: {$total}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
