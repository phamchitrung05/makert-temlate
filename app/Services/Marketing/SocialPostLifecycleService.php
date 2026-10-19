<?php

namespace App\Services\Marketing;

use App\Enums\MediaAssetField;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Models\User;
use App\Services\MediaAssetUsageService;
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
 * - __construct(): nhận service liên kết media.
 * - create(): tạo SocialPost draft và target accounts.
 * - update(): cập nhật payload, reset review nếu nội dung đổi.
 * - transition(): submit/approve/reject/request revision có guard.
 * - syncTargets(): đồng bộ target account IDs không có caption riêng.
 * - ensureGenerationReady(): chặn review/publish khi AI chưa đủ.
 * - loadForResponse(): eager load relation cho resource.
 * - submitStatus()/approveStatus()/rejectStatus()/revisionStatus(): kiểm transition.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload đã validate, actor và SocialPost.
 * - OUTPUT: SocialPost canonical với state transition hợp lệ.
 * - SIDE EFFECT: transaction DB/activity log qua model; không gọi provider.
 * =====================================================================
 */
final class SocialPostLifecycleService
{
    /** Input: service liên kết media. Output: lifecycle service; không ghi dữ liệu. */
    public function __construct(private readonly MediaAssetUsageService $usages) {}

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
            $content = array_key_exists('facebook_content', $data)
                ? FacebookContent::normalize((array) $data['facebook_content'])
                : FacebookContent::normalize(['body' => $data['caption'] ?? null]);
            $caption = array_key_exists('facebook_content', $data)
                ? FacebookContent::caption($content)
                : ($data['caption'] ?? null);
            $post = SocialPost::query()->create([
                'campaign_id' => $data['campaign_id'] ?? null,
                'caption' => $caption,
                'generation_status' => filled($caption) ? SocialPostGenerationStatus::Partial : SocialPostGenerationStatus::Pending,
                'review_status' => SocialPostReviewStatus::Draft,
                'publication_status' => SocialPostPublicationStatus::Pending,
                'generation_no' => 0,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $variant = SocialPostVariant::query()->create([
                'social_post_id' => $post->getKey(),
                'platform' => SocialPlatform::Facebook,
                'format' => 'feed',
                'content_json' => $content,
                'content_status' => filled($caption) ? SocialPostGenerationStatus::Partial : SocialPostGenerationStatus::Pending,
                'generation_status' => filled($caption) ? SocialPostGenerationStatus::Partial : SocialPostGenerationStatus::Pending,
                'review_status' => SocialPostReviewStatus::Draft,
            ]);
            if (array_key_exists('facebook_image_ids', $data)) {
                $this->usages->replaceByIds(
                    User::query()->findOrFail($actorId),
                    $variant,
                    MediaAssetField::SocialPostFacebookImages,
                    $data['facebook_image_ids'],
                );
            }
            $this->syncTargets($post, (array) ($data['target_account_ids'] ?? []), $variant);

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
                || array_key_exists('facebook_content', $data)
                || array_key_exists('facebook_image_ids', $data)
                || array_key_exists('campaign_id', $data);
            $locked->fill(array_intersect_key($data, array_flip(['campaign_id', 'caption'])));
            $locked->updated_by = $actorId;
            if ($contentChanged) {
                $locked->review_status = SocialPostReviewStatus::Draft;
                $locked->reviewed_by = null;
                $locked->reviewed_at = null;
                $locked->review_reason = null;
            }
            $locked->save();
            $variant = $locked->variants()->where('platform', SocialPlatform::Facebook)->first()
                ?? SocialPostVariant::query()->create([
                    'social_post_id' => $locked->getKey(),
                    'platform' => SocialPlatform::Facebook,
                    'format' => 'feed',
                    'review_status' => SocialPostReviewStatus::Draft,
                ]);
            if (array_key_exists('facebook_content', $data) || array_key_exists('caption', $data)) {
                $content = array_key_exists('facebook_content', $data)
                    ? FacebookContent::normalize((array) $data['facebook_content'])
                    : FacebookContent::normalize(['body' => $data['caption'] ?? null]);
                $variant->forceFill([
                    'content_json' => $content,
                    'content_status' => 'ready',
                    'generation_status' => 'ready',
                    'review_status' => SocialPostReviewStatus::Draft,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_reason' => null,
                ])->save();
                if (! array_key_exists('caption', $data)) {
                    $locked->forceFill(['caption' => FacebookContent::caption($content)])->save();
                }
            }
            if (array_key_exists('facebook_image_ids', $data)) {
                $this->usages->replaceByIds(
                    User::query()->findOrFail($actorId),
                    $variant,
                    MediaAssetField::SocialPostFacebookImages,
                    $data['facebook_image_ids'],
                );
                $variant->forceFill(['generation_status' => 'ready'])->save();
            }
            if (array_key_exists('target_account_ids', $data)) {
                $this->syncTargets($locked, (array) $data['target_account_ids'], $variant);
            }

            app(SocialPostGenerationStateService::class)->refresh($locked, (int) $locked->generation_no);

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
            $locked->variants()->where('platform', SocialPlatform::Facebook)->update([
                'review_status' => $newStatus,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Đồng bộ target accounts, mỗi account chỉ xuất hiện một lần.
     *
     * Input: SocialPost, list account IDs và variant thuộc post nếu đã có.
     * Output: void; target rows phản ánh selection hiện tại.
     * Side effect: khóa post, đồng bộ theo khóa variant/account; bỏ chọn chỉ tắt target để giữ lịch sử publish.
     */
    public function syncTargets(SocialPost $post, array $accountIds, ?SocialPostVariant $variant = null): void
    {
        DB::transaction(function () use ($post, $accountIds, $variant): void {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
            $variant ??= $post->variants()->firstOrCreate(
                ['platform' => SocialPlatform::Facebook],
                ['format' => 'feed', 'content_json' => FacebookContent::normalize(['body' => $post->caption])],
            );
            if ((int) $variant->social_post_id !== (int) $post->getKey()) {
                throw ValidationException::withMessages(['variant' => 'Variant không thuộc Social Post này.']);
            }
            $ids = collect($accountIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
            $accounts = SocialAccount::query()->whereIn('id', $ids)->where('provider', $variant->platform)->get()->keyBy('id');
            if ($accounts->count() !== $ids->count()) {
                throw ValidationException::withMessages(['target_account_ids' => 'Một hoặc nhiều Page/account không tồn tại.']);
            }
            $post->targets()->where('social_post_variant_id', $variant->getKey())
                ->whereNotIn('social_account_id', $ids->all())->update(['enabled' => false]);
            foreach ($ids as $id) {
                $account = $accounts->get($id);
                SocialPostTarget::query()->updateOrCreate(
                    ['social_post_variant_id' => $variant->getKey(), 'social_account_id' => $id],
                    [
                        'social_post_id' => $post->getKey(),
                        'platform' => $account->provider ?? SocialPlatform::Facebook,
                        'enabled' => true,
                    ],
                );
            }
        });
    }

    /**
     * Eager load relation mà resource/list cần dùng.
     *
     * Input: SocialPost.
     * Output: model cùng campaign/target/account/version/media.
     * Side effect: query DB; không ghi.
     */
    public function loadForResponse(SocialPost $post): SocialPost
    {
        return $post->load([
            'campaign', 'targets.socialAccount', 'targets.socialPostVariant', 'versions',
            'variants.targets', 'variants.versions', 'variants.mediaAssetUsages.mediaAsset.media',
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
        $state = app(SocialPostGenerationStateService::class);
        if ($state->isReady($post)) {
            return;
        }
        $variant = $post->relationLoaded('variants')
            ? $post->variants->firstWhere('platform', 'facebook')
            : $post->variants()->where('platform', SocialPlatform::Facebook)->first();
        $caption = $variant ? (string) data_get($variant->content_json, 'body') : (string) $post->caption;
        if (! filled($caption)) {
            throw ValidationException::withMessages(['generation' => 'Social Post chưa có caption. Hãy bổ sung caption trước khi review.']);
        }
        $readyImages = $variant
            ? $variant->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)->count()
            : $post->mediaAssetUsagesForField(MediaAssetField::SocialPostImage)->count();
        $missing = max(0, SocialPostGenerationStateService::MIN_GALLERY_IMAGES - $readyImages);

        throw ValidationException::withMessages(['generation' => "Social Post cần thêm {$missing} ảnh (tối thiểu ".SocialPostGenerationStateService::MIN_GALLERY_IMAGES.' ảnh) trước khi review.']);
    }

    /** Input: post draft/cần sửa. Output: pending review hoặc lỗi validation. */
    private function submitStatus(SocialPost $post): SocialPostReviewStatus
    {
        if (! in_array($post->review_status, [SocialPostReviewStatus::Draft, SocialPostReviewStatus::RevisionRequested, SocialPostReviewStatus::Rejected], true)) {
            throw ValidationException::withMessages(['review_status' => 'Bài hiện tại không thể submit review.']);
        }

        return SocialPostReviewStatus::PendingReview;
    }

    /** Input: post chờ duyệt. Output: approved hoặc lỗi validation. */
    private function approveStatus(SocialPost $post): SocialPostReviewStatus
    {
        if ($post->review_status !== SocialPostReviewStatus::PendingReview) {
            throw ValidationException::withMessages(['review_status' => 'Chỉ bài pending_review mới được approve.']);
        }

        return SocialPostReviewStatus::Approved;
    }

    /** Input: post chờ duyệt. Output: rejected hoặc lỗi validation. */
    private function rejectStatus(SocialPost $post): SocialPostReviewStatus
    {
        if ($post->review_status !== SocialPostReviewStatus::PendingReview) {
            throw ValidationException::withMessages(['review_status' => 'Chỉ bài pending_review mới được reject.']);
        }

        return SocialPostReviewStatus::Rejected;
    }

    /** Input: post đã/chờ duyệt. Output: cần sửa hoặc lỗi validation. */
    private function revisionStatus(SocialPost $post): SocialPostReviewStatus
    {
        if (! in_array($post->review_status, [SocialPostReviewStatus::PendingReview, SocialPostReviewStatus::Approved], true)) {
            throw ValidationException::withMessages(['review_status' => 'Bài hiện tại không thể yêu cầu sửa.']);
        }

        return SocialPostReviewStatus::RevisionRequested;
    }
}
