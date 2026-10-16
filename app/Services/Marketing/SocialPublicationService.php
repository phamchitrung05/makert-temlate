<?php

namespace App\Services\Marketing;

use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Enums\SocialPublicationStatus;
use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xếp publication theo target và giữ idempotency.
 * =====================================================================
 * Service chỉ chạy sau approved + generation ready; mỗi Page có publication
 * riêng, retry lại cùng publication không tạo bài duplicate.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - queue(): tạo/queue publication cho target enabled.
 * - retry(): retry publication failed/retryable.
 * - ensurePublishable(): kiểm review/generation/image/target.
 * - idempotencyKey(): tạo key ổn định theo post/account/generation.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : approved SocialPost, actor và target rows.
 * - OUTPUT: publication IDs queued.
 * - SIDE EFFECT: DB mutation và queue dispatch; không gọi publisher trong HTTP.
 * =====================================================================
 */
final class SocialPublicationService
{
    /**
     * Tạo hoặc lấy publication idempotent cho từng target rồi dispatch worker.
     *
     * Input: SocialPost approved và actor.
     * Output: list publication IDs/status.
     * Side effect: insert/update publication/target/post; dispatch sau commit.
     *
     * @return array<string, mixed>
     */
    public function queue(SocialPost $post, User $actor): array
    {
        $this->ensurePublishable($post);
        $items = DB::transaction(function () use ($post): array {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
            $targets = $locked->targets()->where('enabled', true)->get();
            $items = [];
            foreach ($targets as $target) {
                $key = $this->idempotencyKey($locked, (int) $target->social_account_id);
                $publication = SocialPublication::query()->firstOrCreate(
                    ['idempotency_key' => $key],
                    [
                        'social_post_target_id' => $target->getKey(),
                        'status' => SocialPublicationStatus::Queued,
                        'attempts' => 0,
                        'queued_at' => now(),
                    ],
                );
                if ($publication->status !== SocialPublicationStatus::Published) {
                    $publication->forceFill([
                        'status' => SocialPublicationStatus::Queued,
                        'queued_at' => $publication->queued_at ?? now(),
                        'error_code' => null,
                        'error_message' => null,
                    ])->save();
                    $target->forceFill(['status' => SocialPublicationStatus::Queued, 'last_error_code' => null, 'last_error_message' => null])->save();
                    PublishSocialPostJob::dispatch((int) $publication->getKey());
                }
                $items[] = ['publication_id' => (int) $publication->getKey(), 'target_id' => (int) $target->getKey(), 'status' => $publication->status->value];
            }
            $locked->forceFill(['publication_status' => SocialPostPublicationStatus::Publishing])->save();

            return $items;
        });

        return ['publications' => $items];
    }

    /**
     * Retry một publication failed/retryable bằng cùng idempotency key.
     *
     * Input: SocialPublication.
     * Output: publication queued.
     * Side effect: reset lỗi/target và dispatch job; không tạo row duplicate.
     */
    public function retry(SocialPublication $publication): SocialPublication
    {
        return DB::transaction(function () use ($publication): SocialPublication {
            $locked = SocialPublication::query()->lockForUpdate()->with('socialPostTarget.socialPost')->findOrFail($publication->getKey());
            if (! in_array($locked->status, [SocialPublicationStatus::Failed, SocialPublicationStatus::Retryable], true)) {
                throw ValidationException::withMessages(['publication' => 'Publication hiện tại không thể retry.']);
            }
            $post = $locked->socialPostTarget?->socialPost;
            $this->ensurePublishable($post);
            $locked->forceFill(['status' => SocialPublicationStatus::Queued, 'queued_at' => now(), 'error_code' => null, 'error_message' => null])->save();
            $locked->socialPostTarget?->forceFill(['status' => SocialPublicationStatus::Queued, 'last_error_code' => null, 'last_error_message' => null])->save();
            PublishSocialPostJob::dispatch((int) $locked->getKey());

            return $locked->refresh();
        });
    }

    /**
     * Kiểm tra approved/generation/image/target trước khi tạo publication.
     *
     * Input: SocialPost.
     * Output: void khi publish được; ValidationException nếu bị chặn.
     */
    private function ensurePublishable(?SocialPost $post): void
    {
        if (! $post || $post->review_status !== SocialPostReviewStatus::Approved) {
            throw ValidationException::withMessages(['review_status' => 'Chỉ Social Post đã approved mới được publish.']);
        }
        if ($post->generation_status !== SocialPostGenerationStatus::Ready || blank($post->caption)) {
            throw ValidationException::withMessages(['generation' => 'Caption và ảnh phải ready trước khi publish.']);
        }
        if (! app(SocialPostGenerationStateService::class)->isReady($post)) {
            throw ValidationException::withMessages(['image' => 'Social Post chưa có ảnh canonical sẵn sàng.']);
        }
        if (! $post->targets()->where('enabled', true)->exists()) {
            throw ValidationException::withMessages(['targets' => 'Hãy chọn ít nhất một Page/account trước khi publish.']);
        }
    }

    /**
     * Tạo key dedupe theo post/account/generation.
     *
     * Input: SocialPost và account ID.
     * Output: string dưới 191 ký tự; không side effect.
     */
    private function idempotencyKey(SocialPost $post, int $accountId): string
    {
        return 'social:'.$post->getKey().':'.$accountId.':'.(int) $post->generation_no;
    }
}
