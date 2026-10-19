<?php

namespace App\Console\Commands;

use App\Models\AiArticleEvaluation;
use App\Models\AiImport;
use App\Models\MediaAssetUsage;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Services\Ai\Runs\AiRunAssetCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn dữ liệu AI/Social cũ để đưa toàn bộ dữ liệu về luồng mới.
 * =====================================================================
 * Chỉ chạm candidate/evaluation của run social và Social Post do AI tạo.
 * Social Post đã publish ra ngoài (có external_post_id) được giữ cùng run nguồn
 * để không mất provenance của post đang sống. Không chạm user, Post, taxonomy,
 * settings hay dữ liệu nghiệp vụ khác.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): quét run social terminal/hết hạn và dọn trong một transaction mỗi run.
 * - purgeableRunsQuery(): tập run social đủ điều kiện dọn.
 * - isPurgeable(): kiểm lại điều kiện dọn sau khi khóa row.
 * - postIsPublished(): post đã có publication ra ngoài hay chưa.
 * - detachPostMedia(): gỡ usage ảnh của post/variant trước khi xóa post.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : --dry-run, --force và dữ liệu ai_imports/social_posts hiện có.
 * - OUTPUT: số post/run đã dọn, lý do giữ lại và exit code Artisan.
 * - SIDE EFFECT: xóa vĩnh viễn dữ liệu AI/Social trong phạm vi cho phép khi không dry-run.
 * - EXCEPTION/TRANSACTION: process lock + row lock theo từng run; lỗi được đếm, không chặn phần còn lại.
 * =====================================================================
 */
final class PurgeAiSocialDataCommand extends Command
{
    protected $signature = 'ai-social:purge {--dry-run : Chỉ liệt kê, không xóa dữ liệu} {--force : Bỏ qua xác nhận tương tác}';

    protected $description = 'Dọn AI run/evaluation và Social Post do AI tạo (chỉ phạm vi AI/Social)';

    /**
     * Dọn dữ liệu AI/Social cũ theo từng run dưới lock.
     *
     * Input: options Artisan; dữ liệu ai_imports/social_posts hiện có.
     * Output: SUCCESS khi quét xong; FAILURE nếu còn mục bị giữ vì lỗi bất ngờ.
     * Side effect: xóa post/usage/evaluation/run khi không dry-run.
     */
    public function handle(): int
    {
        if (! $this->option('dry-run') && ! $this->option('force')
            && ! $this->confirm('Thao tác này xóa vĩnh viễn AI run/evaluation và Social Post do AI tạo. Tiếp tục?', false)) {
            $this->warn('Đã hủy, không xóa dữ liệu.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $skipped = [];
        $postsPurged = 0;
        $runsPurged = 0;

        $this->purgeableRunsQuery()->chunkById(100, function ($imports) use ($dryRun, &$postsPurged, &$runsPurged, &$skipped): void {
            foreach ($imports as $import) {
                try {
                    $outcome = Cache::lock('ai-import-process-'.$import->id, 10)->get(function () use ($import, $dryRun, &$postsPurged): string {
                        return DB::transaction(function () use ($import, $dryRun, &$postsPurged): string {
                            $run = AiImport::query()->lockForUpdate()->find($import->id);
                            if (! $run || ! $this->isPurgeable($run)) {
                                return 'busy_or_changed';
                            }
                            $post = $run->applied_target_id !== null
                                ? SocialPost::query()->lockForUpdate()->find($run->applied_target_id)
                                : null;
                            if ($post && $this->postIsPublished($post)) {
                                // Giữ cả post lẫn run để bảo toàn provenance của post đã lên sóng.
                                return 'post_published';
                            }
                            if ($dryRun) {
                                return 'purged';
                            }
                            if ($post) {
                                $this->detachPostMedia($post);
                                $post->forceDelete();
                                $postsPurged++;
                            }
                            app(AiRunAssetCleaner::class)->cleanup($run);
                            AiArticleEvaluation::query()->where('run_id', $run->id)->delete();
                            $run->delete();

                            return 'purged';
                        });
                    });
                    if ($outcome === 'purged') {
                        $runsPurged++;
                    } elseif ($outcome !== 'gone') {
                        $skipped[$outcome] = ($skipped[$outcome] ?? 0) + 1;
                    }
                } catch (Throwable) {
                    $skipped['purge_failed'] = ($skipped['purge_failed'] ?? 0) + 1;
                }
            }
        }, 'id');

        $this->info($dryRun
            ? "Có {$runsPurged} AI run đủ điều kiện dọn (kèm {$postsPurged} Social Post)."
            : "Đã dọn {$runsPurged} AI run và {$postsPurged} Social Post.");
        foreach ($skipped as $reason => $total) {
            $this->warn("Đã giữ lại {$total} mục: {$reason}.");
        }

        return array_diff(array_keys($skipped), ['busy_or_changed', 'post_published']) === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Query các AI run social ở trạng thái terminal hoặc đã hết hạn.
     *
     * Input: không có.
     * Output: Builder đọc ai_imports theo cột virtual input_target_type; không ghi database.
     */
    private function purgeableRunsQuery()
    {
        return AiImport::query()
            ->where('input_target_type', 'social_post')
            ->where(function ($query): void {
                $query->where('expires_at', '<', now())
                    ->orWhereIn('status', AiImport::TERMINAL_STATUSES);
            });
    }

    /**
     * Kiểm lại điều kiện dọn cho run sau khi đã khóa row.
     *
     * Input: AiImport đã lock.
     * Output: bool true khi target social và run terminal/hết hạn.
     */
    private function isPurgeable(AiImport $run): bool
    {
        if (data_get($run->input_json, 'target_type') !== 'social_post') {
            return false;
        }

        return $run->expires_at?->isPast() === true
            || in_array($run->status, AiImport::TERMINAL_STATUSES, true);
    }

    /**
     * Kiểm post đã publish ra ngoài hay chưa.
     *
     * Input: SocialPost đã lock.
     * Output: bool true khi tồn tại publication có external_post_id; không ghi database.
     */
    private function postIsPublished(SocialPost $post): bool
    {
        return SocialPublication::query()
            ->whereIn('social_post_target_id', DB::table('social_post_targets')
                ->where('social_post_id', $post->getKey())->select('id'))
            ->whereNotNull('external_post_id')
            ->exists();
    }

    /**
     * Gỡ usage ảnh của post và các variant trước khi xóa để không còn tham chiếu mồ côi.
     *
     * Input: SocialPost cần xóa.
     * Output: void; usage của post/variant bị xóa khỏi DB.
     */
    private function detachPostMedia(SocialPost $post): void
    {
        $variantIds = SocialPostVariant::query()->where('social_post_id', $post->getKey())->pluck('id')->all();
        MediaAssetUsage::query()
            ->where(function ($query) use ($post, $variantIds): void {
                $query->where(function ($inner) use ($post): void {
                    $inner->where('linkable_type', 'social_post')->where('linkable_id', $post->getKey());
                });
                if ($variantIds !== []) {
                    $query->orWhere(function ($inner) use ($variantIds): void {
                        $inner->where('linkable_type', 'social_post_variant')->whereIn('linkable_id', $variantIds);
                    });
                }
            })
            ->delete();
    }
}
