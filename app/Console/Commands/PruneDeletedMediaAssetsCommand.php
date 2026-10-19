<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Models\ResourceVersionTemplate;
use App\Models\SeoMetadata;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn file của media đã xóa mềm sau thời gian giữ lại.
 * =====================================================================
 * Giữ asset còn usage, tham chiếu bài viết/SEO/template hoặc snapshot AI.
 * Force-delete từng model để Spatie xóa cả file gốc và conversion.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): kiểm thời hạn và dọn theo lô; hỗ trợ xem trước bằng --dry-run.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : --days (mặc định 30), --dry-run và asset đã soft-delete.
 * - OUTPUT: số asset đủ điều kiện và exit code Artisan.
 * - SIDE EFFECT: xóa vĩnh viễn metadata/file đã hết thời hạn khi không dry-run.
 * =====================================================================
 */
final class PruneDeletedMediaAssetsCommand extends Command
{
    protected $signature = 'media:prune-deleted {--days=30 : Số ngày giữ asset đã xóa mềm} {--dry-run : Chỉ đếm, không xóa dữ liệu}';

    protected $description = 'Dọn vĩnh viễn file của media đã xóa mềm và không còn được tham chiếu';

    /**
     * Dọn asset hết retention dưới row lock để không xóa file đang được sử dụng.
     * Input: options Artisan và service kiểm tham chiếu nội dung.
     * Output: SUCCESS với số asset, INVALID nếu days không phải số nguyên dương.
     * Side effect: forceDelete kích hoạt Spatie xóa file; dry-run chỉ đọc dữ liệu.
     */
    public function handle(ContentMediaReferenceService $references): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false) {
            $this->error('--days phải là số nguyên dương.');

            return self::INVALID;
        }
        $cutoff = now()->subDays($days);
        $count = 0;
        // Đọc theo ID, kiểm lại dưới lock trước mỗi force-delete.
        foreach (MediaAsset::onlyTrashed()->where('deleted_at', '<=', $cutoff)->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate, $cutoff, $references, &$count): void {
                $asset = MediaAsset::onlyTrashed()->whereKey($candidate->getKey())
                    ->where('deleted_at', '<=', $cutoff)->lockForUpdate()->first();
                if (! $asset) {
                    return;
                }
                // MorphTo của Spatie mặc định không thấy owner đã soft-delete.
                // Giữ owner thật để đọc URL conversion và xóa đúng các file phái sinh.
                $asset->load('media');
                foreach ($asset->media as $media) {
                    $media->setRelation('model', $asset);
                }
                if ($asset->usages()->exists()
                    || ResourceVersionTemplate::query()->where('source_media_id', $asset->getKey())->exists()
                    || SeoMetadata::query()->where('og_image_id', $asset->getKey())->exists()
                    || $references->isLinkedFromPost($asset)
                    || $references->isReferencedByRetainedAiRun((int) $asset->getKey())) {
                    return;
                }
                if (! $this->option('dry-run')) {
                    foreach ($asset->media as $media) {
                        $media->delete();
                    }
                    $asset->forceDelete();
                }
                $count++;
            });
        }
        $this->info($this->option('dry-run') ? "Có {$count} media đủ điều kiện dọn." : "Đã dọn {$count} media hết thời hạn giữ lại.");

        return self::SUCCESS;
    }
}
