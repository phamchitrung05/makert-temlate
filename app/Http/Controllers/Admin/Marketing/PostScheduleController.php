<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Enums\DailyContentPlanStatus;
use App\Enums\SocialPostReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\BaseResponse;
use App\Models\Campaign;
use App\Models\DailyContentPlan;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\SocialPostLifecycleService;
use App\Services\Settings\ProjectSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API gom cho trang Lịch Đăng Bài (/marketing/post-schedule).
 * =====================================================================
 * Controller điều phối các endpoint cấu hình hệ thống, danh sách lịch tuần,
 * trigger batch Chủ Nhật thủ công và duyệt cả tuần. Quyền/validation/lifecycle
 * nằm ở middleware/FormRequest/service tương ứng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - settings(): đọc group marketing_schedule.
 * - updateSettings(): ghi group marketing_schedule.
 * - week(): danh sách slot lịch của tuần.
 * - runBatch(): chạy weekly-batch thủ công.
 * - approveWeek(): duyệt toàn bộ bài pending trong tuần.
 * - actor(): resolve admin user.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : admin request có quyền marketing/social_posts.
 * - OUTPUT: JSON BaseResponse bounded.
 * - SIDE EFFECT: ghi settings/plan/review; dispatch AI jobs khi batch chạy.
 * =====================================================================
 */
final class PostScheduleController extends Controller
{
    /** Input: không. Output: settings group marketing_schedule. */
    public function settings(ProjectSettingsService $settings): JsonResponse
    {
        return BaseResponse::success($settings->section('marketing_schedule'), 'Cấu hình lịch đăng.');
    }

    /** Input: payload settings. Output: section đã cập nhật. */
    public function updateSettings(Request $request, ProjectSettingsService $settings): JsonResponse
    {
        $section = $settings->update('marketing_schedule', (array) $request->all(), $this->actor($request));

        return BaseResponse::success($section, 'Đã lưu cấu hình lịch đăng.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả lịch slot của một tuần (Thứ 2 -> Chủ Nhật).
     * =====================================================================
     * Input: ?date=YYYY-MM-DD bất kỳ trong tuần (mặc định tuần hiện tại).
     * Output: list DailyContentPlan kèm post/campaign/profile.
     * Side effect: chỉ đọc DB.
     * =====================================================================
     */
    public function week(Request $request): JsonResponse
    {
        $date = $request->date('date') ?? now('Asia/Ho_Chi_Minh');
        $start = CarbonImmutable::instance($date)->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->endOfWeek()->endOfDay();

        $plans = DailyContentPlan::query()
            ->with(['socialPost.variants', 'campaign:id,name,objective', 'writingProfile:id,name', 'imageProfile:id,name'])
            ->whereBetween('planned_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('planned_date')->orderBy('slot')
            ->get();

        return BaseResponse::success([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'items' => $plans->map(fn (DailyContentPlan $plan): array => [
                'id' => (int) $plan->id,
                'planned_date' => $plan->planned_date?->toDateString(),
                'slot' => (int) $plan->slot,
                'scheduled_at' => $plan->scheduled_at?->toIso8601String(),
                'pillar_type' => $plan->pillar_type,
                'topic_title' => $plan->topic_title,
                'status' => $plan->status?->value,
                'campaign' => $plan->campaign ? ['id' => (int) $plan->campaign->id, 'name' => $plan->campaign->name, 'objective' => $plan->campaign->objective] : null,
                'writing_profile' => $plan->writingProfile ? ['id' => (int) $plan->writingProfile->id, 'name' => $plan->writingProfile->name] : null,
                'image_profile' => $plan->imageProfile ? ['id' => (int) $plan->imageProfile->id, 'name' => $plan->imageProfile->name] : null,
                'image_count' => (int) $plan->image_count,
                'post' => $this->postPayload($plan->socialPost),
            ])->values()->all(),
        ], 'Lịch tuần.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy batch tạo bài tuần tới thủ công (như nút UI).
     * =====================================================================
     * Input: không.
     * Output: số slot đã tạo (đọc từ output command).
     * Side effect: Artisan::call marketing:social-post-drafts --weekly-batch.
     * =====================================================================
     */
    public function runBatch(): JsonResponse
    {
        Artisan::call('marketing:social-post-drafts', ['--weekly-batch' => true]);

        return BaseResponse::success(['output' => trim(Artisan::output())], 'Đã chạy batch tuần tới.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Duyệt toàn bộ bài pending_review của một tuần.
     * =====================================================================
     * Input: ?date=YYYY-MM-DD.
     * Output: số post đã approve.
     * Side effect: transition từng post sang approved trong transaction service.
     * =====================================================================
     */
    public function approveWeek(Request $request, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $date = $request->date('date') ?? now('Asia/Ho_Chi_Minh');
        $start = CarbonImmutable::instance($date)->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->endOfWeek()->endOfDay();
        $actor = $this->actor($request);

        $posts = SocialPost::query()
            ->where('review_status', SocialPostReviewStatus::PendingReview)
            ->whereHas('dailyContentPlans', function ($q) use ($start, $end): void {
                $q->whereBetween('planned_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->get();

        $approved = 0;
        foreach ($posts as $post) {
            try {
                $lifecycle->transition($post, 'approve', $actor);
                $approved++;
            } catch (\Throwable) {
                // Bài thiếu caption/ảnh bị bỏ qua, admin tự xử lý sau.
            }
        }

        return BaseResponse::success(['approved' => $approved], "Đã duyệt {$approved} bài trong tuần.");
    }

    /** Input: SocialPost|null. Output: bounded JSON post. */
    private function postPayload(?SocialPost $post): ?array
    {
        if (! $post) {
            return null;
        }
        $variant = $post->variants->firstWhere('platform', 'facebook');

        return [
            'id' => (int) $post->id,
            'caption' => $post->caption,
            'review_status' => $post->review_status?->value,
            'publication_status' => $post->publication_status?->value,
            'generation_status' => $post->generation_status?->value,
            'variant' => $variant ? ['id' => (int) $variant->id, 'content_json' => $variant->content_json] : null,
        ];
    }

    /** Input: Request. Output: admin User. */
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
