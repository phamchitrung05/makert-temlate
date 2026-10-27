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
use App\Services\Marketing\SocialSchedulePairingService;
use App\Services\Marketing\WeeklyContentKeyPlannerService;
use Carbon\CarbonImmutable;
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
    protected $signature = 'marketing:social-post-drafts {--date=} {--count=3} {--weekly-batch : Tạo trọn bộ slot cho tuần tới (Thứ 2 -> Chủ Nhật)}';

    protected $description = 'Tạo 2--3 Social Post draft mỗi ngày hoặc cả tuần theo chiến dịch';

    /**
     * Tạo các slot daily plan idempotent và queue generation.
     *
     * Input: --date YYYY-MM-DD tùy chọn, --count 2..3, --weekly-batch.
     * Output: SUCCESS/FAILURE exit code; không tự publish.
     * Side effect: tạo DailyContentPlan/SocialPost và jobs.
     */
    public function handle(
        SocialPostLifecycleService $lifecycle,
        SocialPostGenerationService $generation,
        WeeklyContentKeyPlannerService $planner,
        SocialSchedulePairingService $pairing,
    ): int {
        if ($this->option('weekly-batch')) {
            return $this->handleWeeklyBatch($lifecycle, $generation, $planner, $pairing);
        }

        return $this->handleDaily($lifecycle, $generation);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Luồng mặc định tạo draft theo ngày (giữ nguyên hành vi cũ).
     * =====================================================================
     * Input: --date/--count.
     * Output: exit code; không gọi planner/pairing.
     * Side effect: tạo plan/post/jobs như phiên bản trước.
     * =====================================================================
     */
    private function handleDaily(SocialPostLifecycleService $lifecycle, SocialPostGenerationService $generation): int
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
     * =====================================================================
     * CHỨC NĂNG: Tạo trọn bộ slot bài của tuần tới cho mọi chiến dịch active.
     * =====================================================================
     * Batch sáng Chủ Nhật: planner sinh slot -> pairing gán pool -> tạo plan
     * + post -> queue AI -> chuyển pending_review khi auto_submit_review bật.
     *
     * Input: planner/pairing/lifecycle/generation services.
     * Output: exit code; số slot đã tạo ghi log.
     * Side effect: nhiều transaction ghi DB + dispatch AI jobs.
     * =====================================================================
     */
    private function handleWeeklyBatch(
        SocialPostLifecycleService $lifecycle,
        SocialPostGenerationService $generation,
        WeeklyContentKeyPlannerService $planner,
        SocialSchedulePairingService $pairing,
    ): int {
        $actor = $this->actor();
        if (! $actor) {
            $this->error('Không tìm thấy admin actor cho weekly batch.');

            return self::FAILURE;
        }

        $tz = 'Asia/Ho_Chi_Minh';
        $weekStart = CarbonImmutable::now($tz)->next(CarbonImmutable::MONDAY)->startOfDay();
        $weekEnd = $weekStart->endOfWeek()->endOfDay();
        $this->info('Weekly batch tuần '.$weekStart->toDateString().' → '.$weekEnd->toDateString());

        $slots = $planner->planWeek($weekStart, $weekEnd);
        if ($slots === []) {
            $this->warn('Không có campaign active giao tuần tới; không tạo bài.');

            return self::SUCCESS;
        }

        $targetIds = $this->activeTargets();
        $autoReview = (bool) (app(\App\Services\Settings\ProjectSettingsService::class)->effective('marketing_schedule')['auto_submit_review'] ?? true);
        $created = 0;

        // Gom slot theo campaign để theo dõi pool đã dùng trong tuần.
        $byCampaign = collect($slots)->groupBy('campaign_id');
        foreach ($byCampaign as $campaignId => $campaignSlots) {
            $campaign = Campaign::query()->find((int) $campaignId);
            if (! $campaign) {
                continue;
            }
            $usedToneIds = [];
            $usedStyleIds = [];
            $usedSubjectIds = [];
            $combos = [];

            foreach ($campaignSlots as $slot) {
                $pair = $pairing->pairForSlot($campaign, $slot, $usedToneIds, $usedStyleIds, $usedSubjectIds, $combos);
                $usedToneIds[] = (int) ($pair['writing_profile_id'] ?? 0);
                $usedStyleIds[] = (int) ($pair['image_profile_id'] ?? 0);
                $usedSubjectIds = array_merge($usedSubjectIds, (array) ($pair['subject_media_ids'] ?? []));
                $combos[] = $pair;

                $plan = DailyContentPlan::query()->firstOrCreate(
                    ['planned_date' => $slot['planned_date'], 'slot' => $slot['slot']],
                    [
                        'timezone' => $tz,
                        'campaign_id' => (int) $campaign->getKey(),
                        'target_draft_count' => 1,
                        'status' => DailyContentPlanStatus::Pending,
                        'pillar_type' => $slot['pillar_type'],
                        'topic_title' => $slot['topic_title'],
                        'keywords_json' => $slot['keywords_json'],
                        'content_angle' => $slot['content_angle'],
                        'subject_media_ids' => $slot['subject_media_ids'],
                        'subject_description' => $slot['subject_description'],
                        'writing_profile_id' => $pair['writing_profile_id'],
                        'image_profile_id' => $pair['image_profile_id'],
                        'image_count' => $slot['image_count'],
                        'scheduled_at' => $slot['scheduled_at'],
                    ],
                );
                if ($plan->social_post_id || in_array($plan->status, [DailyContentPlanStatus::Completed, DailyContentPlanStatus::Running], true)) {
                    continue;
                }

                try {
                    $post = DB::transaction(function () use ($plan, $campaign, $targetIds, $actor, $lifecycle): SocialPost {
                        $plan->forceFill(['status' => DailyContentPlanStatus::Running, 'started_at' => now()])->save();

                        return $lifecycle->create([
                            'campaign_id' => (int) $campaign->getKey(),
                            'target_account_ids' => $targetIds,
                            'caption' => null,
                        ], (int) $actor->getKey());
                    });

                    $generation->queue($post, (int) $actor->getKey(), [
                        'component' => 'all',
                        'writing_profile_id' => $pair['writing_profile_id'],
                        'image_profile_id' => $pair['image_profile_id'],
                        'image_count' => $slot['image_count'],
                        'source_text' => $slot['content_angle']."\n".$slot['topic_title'],
                    ]);

                    $plan->forceFill([
                        'social_post_id' => $post->getKey(),
                        'status' => DailyContentPlanStatus::Completed,
                        'completed_at' => now(),
                    ])->save();

                    // auto_submit_review chỉ áp dụng khi caption đã sẵn sàng (manual
                    // daily flow). Weekly batch queue AI async nên caption chưa có —
                    // submit đồng bộ sẽ throw; bắt riêng để không làm plan thất bại.
                    if ($autoReview) {
                        try {
                            $lifecycle->transition($post, 'submit', $actor);
                        } catch (Throwable $submitError) {
                            // Caption chưa có: giữ draft, admin duyệt sau qua approve-week.
                            $this->warn('  ['.$slot['planned_date'].' slot '.$slot['slot'].'] Post #'.$post->getKey().' giữ draft (chờ AI caption).');
                        }
                    }
                    $created++;
                    $this->line('  ['.$slot['planned_date'].' slot '.$slot['slot'].'] '.$slot['pillar_type'].' -> Post #'.$post->getKey());
                } catch (Throwable $exception) {
                    $plan->forceFill([
                        'status' => DailyContentPlanStatus::Failed,
                        'error_code' => 'WEEKLY_SLOT_FAILED',
                        'error_message' => substr($exception->getMessage(), 0, 500),
                        'completed_at' => now(),
                    ])->save();
                    $this->error('  Slot '.$slot['planned_date'].'#'.$slot['slot'].' lỗi: '.$exception->getMessage());
                }
            }
        }

        $this->info('Đã tạo '.$created.' slot bài cho tuần tới.');

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
