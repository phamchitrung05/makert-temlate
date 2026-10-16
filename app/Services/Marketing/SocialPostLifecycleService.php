<?php

namespace App\Services\Marketing;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Enums\SocialPublicationStatus;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giữ invariant CRUD, target và review Social Post.
 * =====================================================================
 * Lifecycle service bảo đảm caption canonical, approved gate và reset review
 * khi nội dung/ảnh thay đổi. Publication vẫn do publisher worker xử lý.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - create(): tạo SocialPost draft và target accounts.
 * - update(): cập nhật payload, reset review nếu nội dung đổi.
 * - transition(): submit/approve/reject/request revision có guard.
 * - syncTargets(): đồng bộ target account IDs không có caption riêng.
 * - ensureGenerationReady(): chặn review/publish khi AI chưa đủ.
 * - loadForResponse(): eager load relation cho resource.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload đã validate, actor và SocialPost.
 * - OUTPUT: SocialPost canonical với state transition hợp lệ.
 * - SIDE EFFECT: transaction DB/activity log qua model; không gọi provider.
 * =====================================================================
 */
final class SocialPostLifecycleService
{
    /**
     * Tạo SocialPost draft và target Page/account đã chọn.
     *
     * Input: payload SocialPostStoreRequest và actor ID.
     * Output: SocialPost mới, generation_status pending hoặc ready nếu có caption.
     * Side effect: insert post/targets atomically; không gọi AI.
     */
    public function create(array $data, int $actorId): SocialPost
    {
        return DB::transaction(function () use ($data, $actorId): SocialPost {
            $post = SocialPost::query()->create([
                'campaign_id' => $data['campaign_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'caption' => $data['caption'] ?? null,
                'generation_status' => filled($data['caption'] ?? null) ? SocialPostGenerationStatus::Partial : SocialPostGenerationStatus::Pending,
                'review_status' => SocialPostReviewStatus::Draft,
                'publication_status' => SocialPostPublicationStatus::Pending,
                'generation_no' => 0,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->syncTargets($post, (array) ($data['target_account_ids'] ?? []));

            return $post;
        });
    }

    /**
     * Cập nhật canonical caption/context và target accounts.
     *
     * Input: SocialPost, payload update và actor ID.
     * Output: SocialPost fresh sau update.
     * Side effect: reset approved/pending review về draft khi nội dung đổi.
     */
    public function update(SocialPost $post, array $data, int $actorId): SocialPost
    {
        return DB::transaction(function () use ($post, $data, $actorId): SocialPost {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
            $contentChanged = array_key_exists('caption', $data)
                || array_key_exists('campaign_id', $data)
                || array_key_exists('product_id', $data);
            $locked->fill(array_intersect_key($data, array_flip(['campaign_id', 'product_id', 'caption'])));
            $locked->updated_by = $actorId;
            if ($contentChanged) {
                $locked->review_status = SocialPostReviewStatus::Draft;
                $locked->reviewed_by = null;
                $locked->reviewed_at = null;
                $locked->review_reason = null;
            }
            $locked->save();
            if (array_key_exists('target_account_ids', $data)) {
                $this->syncTargets($locked, (array) $data['target_account_ids']);
            }

            return $locked->refresh();
        });
    }

    /**
     * Thực hiện một transition review đã kiểm tra state/generation.
     *
     * Input: action submit|approve|reject|request_revision, reason, actor.
     * Output: SocialPost state mới.
     * Side effect: ghi actor/time/reason trong transaction; không publish.
     */
    public function transition(SocialPost $post, string $action, User $actor, ?string $reason = null): SocialPost
    {
        return DB::transaction(function () use ($post, $action, $actor, $reason): SocialPost {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
            if ($action !== 'reject' || $locked->review_status === SocialPostReviewStatus::PendingReview) {
                $this->ensureGenerationReady($locked);
            }

            $newStatus = match ($action) {
                'submit' => $this->submitStatus($locked),
                'approve' => $this->approveStatus($locked),
                'reject' => $this->rejectStatus($locked),
                'request_revision' => $this->revisionStatus($locked),
                default => throw ValidationException::withMessages(['action' => 'Transition review không hợp lệ.']),
            };
            $locked->forceFill([
                'review_status' => $newStatus,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Đồng bộ target accounts, mỗi account chỉ xuất hiện một lần.
     *
     * Input: SocialPost đã lock và list account IDs.
     * Output: void; target rows phản ánh selection hiện tại.
     * Side effect: delete/create targets trong transaction caller.
     */
    public function syncTargets(SocialPost $post, array $accountIds): void
    {
        $ids = collect($accountIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $accounts = SocialAccount::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($accounts->count() !== $ids->count()) {
            throw ValidationException::withMessages(['target_account_ids' => 'Một hoặc nhiều Page/account không tồn tại.']);
        }
        $post->targets()->whereNotIn('social_account_id', $ids->all())->delete();
        foreach ($ids as $id) {
            $account = $accounts->get($id);
            SocialPostTarget::query()->updateOrCreate(
                ['social_post_id' => $post->getKey(), 'social_account_id' => $id],
                ['platform' => $account->provider ?? SocialPlatform::Facebook, 'enabled' => true, 'status' => SocialPublicationStatus::Pending],
            );
        }
    }

    /**
     * Eager load relation mà resource/list cần dùng.
     *
     * Input: SocialPost.
     * Output: model cùng campaign/product/target/account/version/media.
     * Side effect: query DB; không ghi.
     */
    public function loadForResponse(SocialPost $post): SocialPost
    {
        return $post->load([
            'campaign', 'product', 'targets.socialAccount', 'versions',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }

    /**
     * Chặn submit/approve nếu generation chưa có caption và ảnh.
     *
     * Input: SocialPost đã lock.
     * Output: void khi ready; ValidationException nếu chưa đủ.
     */
    private function ensureGenerationReady(SocialPost $post): void
    {
        if (! app(SocialPostGenerationStateService::class)->isReady($post)) {
            throw ValidationException::withMessages(['generation' => 'Social Post phải có caption và ảnh sẵn sàng trước khi review.']);
        }
    }

    private function submitStatus(SocialPost $post): SocialPostReviewStatus
    {
        if (! in_array($post->review_status, [SocialPostReviewStatus::Draft, SocialPostReviewStatus::RevisionRequested, SocialPostReviewStatus::Rejected], true)) {
            throw ValidationException::withMessages(['review_status' => 'Bài hiện tại không thể submit review.']);
        }

        return SocialPostReviewStatus::PendingReview;
    }

    private function approveStatus(SocialPost $post): SocialPostReviewStatus
    {
        if ($post->review_status !== SocialPostReviewStatus::PendingReview) {
            throw ValidationException::withMessages(['review_status' => 'Chỉ bài pending_review mới được approve.']);
        }

        return SocialPostReviewStatus::Approved;
    }

    private function rejectStatus(SocialPost $post): SocialPostReviewStatus
    {
        if ($post->review_status !== SocialPostReviewStatus::PendingReview) {
            throw ValidationException::withMessages(['review_status' => 'Chỉ bài pending_review mới được reject.']);
        }

        return SocialPostReviewStatus::Rejected;
    }

    private function revisionStatus(SocialPost $post): SocialPostReviewStatus
    {
        if (! in_array($post->review_status, [SocialPostReviewStatus::PendingReview, SocialPostReviewStatus::Approved], true)) {
            throw ValidationException::withMessages(['review_status' => 'Bài hiện tại không thể yêu cầu sửa.']);
        }

        return SocialPostReviewStatus::RevisionRequested;
    }
}
