<?php

namespace App\Console\Commands;

use App\Enums\DailyContentPlanStatus;
use App\Models\Campaign;
use App\Models\DailyContentPlan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\SocialPostGenerationService;
use App\Services\Marketing\SocialPostLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo 2--3 Social Post draft mỗi ngày theo slot idempotent.
 * =====================================================================
 * Command chỉ tạo draft và xếp AI jobs; không submit review hay publish.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): tạo slot, draft và generation.
 * - actor(): chọn admin system actor đã có quyền.
 * - activeTargets(): lấy Page accounts active.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : date/count options và campaign active.
 * - OUTPUT: exit code; daily_content_plans liên kết Social Post draft.
 * - SIDE EFFECT: DB/queue/AI quota; không publish.
 * =====================================================================
 */
final class GenerateDailySocialPostDraftsCommand extends Command
{
    protected $signature = 'marketing:social-post-drafts {--date=} {--count=3}';

    protected $description = 'Tạo 2--3 Social Post draft mỗi ngày và xếp AI generation';

    /**
     * Tạo các slot daily plan idempotent và queue generation.
     *
     * Input: --date YYYY-MM-DD tùy chọn, --count 2..3.
     * Output: SUCCESS/FAILURE exit code; không tự publish.
     * Side effect: tạo DailyContentPlan/SocialPost và jobs.
     */
    public function handle(SocialPostLifecycleService $lifecycle, SocialPostGenerationService $generation): int
    {
        $actor = $this->actor();
        if (! $actor) {
            $this->error('Không tìm thấy admin actor cho daily planner.');

            return self::FAILURE;
        }
        $date = $this->option('date') ?: now('Asia/Ho_Chi_Minh')->toDateString();
        $count = max(2, min(3, (int) $this->option('count')));
        $campaigns = Campaign::query()->active()->orderBy('id')->get();
        if ($campaigns->isEmpty()) {
            $this->warn('Không có campaign active để tạo daily draft.');

            return self::SUCCESS;
        }
        $targetIds = $this->activeTargets();
        $usedCampaignIds = [];
        foreach (range(1, $count) as $slot) {
            $campaign = $campaigns->first(function (Campaign $candidate) use (&$usedCampaignIds): bool {
                return ! in_array((int) $candidate->getKey(), $usedCampaignIds, true)
                    && ! SocialPost::query()->where('campaign_id', $candidate->getKey())->where('created_at', '>=', now()->subDays(7))->exists();
            });
            // Khi catalog nhỏ hơn số slot, vẫn tạo đủ draft nhưng không lặp
            // campaign trong cùng lần chạy nếu còn lựa chọn khác.
            $campaign ??= $campaigns[($slot - 1) % $campaigns->count()];
            $usedCampaignIds[] = (int) $campaign->getKey();
            $plan = DailyContentPlan::query()->firstOrCreate(
                ['planned_date' => $date, 'slot' => $slot],
                ['timezone' => 'Asia/Ho_Chi_Minh', 'campaign_id' => $campaign->getKey(), 'target_draft_count' => 1, 'status' => DailyContentPlanStatus::Pending],
            );
            if ($plan->social_post_id || $plan->status === DailyContentPlanStatus::Completed) {
                continue;
            }
            try {
                $post = DB::transaction(function () use ($plan, $campaign, $targetIds, $actor, $lifecycle): SocialPost {
                    $plan->forceFill(['status' => DailyContentPlanStatus::Running, 'started_at' => now(), 'error_code' => null, 'error_message' => null])->save();

                    return $lifecycle->create([
                        'campaign_id' => $campaign->getKey(),
                        'target_account_ids' => $targetIds, 'caption' => null,
                    ], (int) $actor->getKey());
                });
                $generation->queue($post, (int) $actor->getKey(), ['component' => 'all']);
                $plan->forceFill(['social_post_id' => $post->getKey(), 'status' => DailyContentPlanStatus::Completed, 'completed_at' => now()])->save();
                $this->line('Đã xếp slot '.$slot.' -> SocialPost #'.$post->getKey());
            } catch (Throwable $exception) {
                $plan->forceFill(['status' => DailyContentPlanStatus::Failed, 'error_code' => 'DAILY_SOCIAL_POST_FAILED', 'error_message' => substr($exception->getMessage(), 0, 500), 'completed_at' => now()])->save();
                $this->error('Slot '.$slot.' lỗi: '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Chọn admin actor deterministic cho scheduler.
     *
     * Input: users/permissions hiện tại.
     * Output: User|null; không tạo user tự động.
     */
    private function actor(): ?User
    {
        return User::query()->where(function ($query): void {
            $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['super-admin', 'admin']))
                ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'social_posts.create'));
        })->orderBy('id')->first();
    }

    /**
     * Lấy tất cả Facebook Page active để daily draft có target mặc định.
     *
     * Input: social_accounts.
     * Output: list<int> account IDs; không gọi Graph API.
     */
    private function activeTargets(): array
    {
        return SocialAccount::query()->where('provider', 'facebook')->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
