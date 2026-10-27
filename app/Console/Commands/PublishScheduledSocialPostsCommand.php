<?php

namespace App\Console\Commands;

use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\SocialPublicationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tự động đăng các Social Post đã duyệt đến giờ hẹn.
 * =====================================================================
 * Cronjob quét mỗi 5 phút các bài có DailyContentPlan.scheduled_at <= now()
 * và review_status = approved rồi xếp publication qua SocialPublicationService.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): quét và publish các post đến hạn.
 * - actor(): chọn admin system actor.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : thời điểm hiện tại, plan scheduled_at, review approved.
 * - OUTPUT: exit code; dispatch PublishSocialPostJob cho từng post đến hạn.
 * - SIDE EFFECT: ghi publication/queue; không gọi Graph API trực tiếp.
 * =====================================================================
 */
final class PublishScheduledSocialPostsCommand extends Command
{
    protected $signature = 'marketing:publish-scheduled-posts {--limit=50}';

    protected $description = 'Đăng các Social Post approved đã đến scheduled_at';

    /**
     * Quét bài đến hạn và xếp publication cho từng target.
     *
     * Input: --limit số post tối đa mỗi lần quét.
     * Output: SUCCESS/FAILURE; số post đã xếp ghi log.
     * Side effect: tạo publication + dispatch job publish.
     */
    public function handle(SocialPublicationService $publication): int
    {
        $actor = $this->actor();
        if (! $actor) {
            $this->error('Không tìm thấy admin actor cho publish scheduler.');

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));

        $posts = SocialPost::query()
            ->where('review_status', SocialPostReviewStatus::Approved)
            ->whereIn('publication_status', [SocialPostPublicationStatus::Pending, SocialPostPublicationStatus::Scheduled])
            ->whereHas('dailyContentPlans', function ($query): void {
                $query->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now());
            })
            ->limit($limit)
            ->get();

        if ($posts->isEmpty()) {
            $this->line('Không có bài đến hạn.');

            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($posts as $post) {
            try {
                $publication->queue($post, $actor);
                $queued++;
                $this->line('  Đã xếp publish Post #'.$post->getKey());
            } catch (Throwable $exception) {
                $this->error('  Post #'.$post->getKey().' lỗi: '.$exception->getMessage());
            }
        }

        $this->info('Đã xếp '.$queued.' bài để publish.');

        return self::SUCCESS;
    }

    /**
     * Chọn admin actor deterministic cho scheduler.
     *
     * Input: users/permissions.
     * Output: User|null; không tạo user tự động.
     */
    private function actor(): ?User
    {
        return User::query()->where(function ($query): void {
            $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['super-admin', 'admin']))
                ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'social_posts.update'));
        })->orderBy('id')->first();
    }
}
