<?php

namespace App\Services\Marketing;

use App\Enums\SocialPostGenerationStatus;
use App\Models\AiImport;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tính trạng thái tạo caption/ảnh của Social Post.
 * =====================================================================
 * Service gom kết quả của hai component độc lập, giữ draft khi một component
 * thành công và chặn publish trước khi cả caption lẫn ảnh sẵn sàng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - refresh(): đọc các AiImport cùng generation và cập nhật SocialPost.
 * - statusFor(): tính enum status từ các branch caption/image.
 * - isReady(): kiểm tra generation đủ điều kiện review/publish.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost và generation number.
 * - OUTPUT: SocialPost được cập nhật generation_status; không đổi review.
 * - SIDE EFFECT: cập nhật database trong transaction ngắn.
 * =====================================================================
 */
final class SocialPostGenerationStateService
{
    public const MIN_GALLERY_IMAGES = 3;
    /**
     * Đồng bộ trạng thái aggregate từ hai branch AI hiện tại.
     *
     * Input: SocialPost hoặc ID và generation number.
     * Output: SocialPost fresh với pending/generating/ready/partial/failed.
     * Side effect: lock post và ghi generation_status; stale generation bỏ qua.
     */
    public function refresh(SocialPost|int $post, int $generationNo): SocialPost
    {
        $postId = $post instanceof SocialPost ? (int) $post->getKey() : $post;

        return DB::transaction(function () use ($postId, $generationNo): SocialPost {
            $current = SocialPost::query()->lockForUpdate()->findOrFail($postId);
            if ((int) $current->generation_no !== $generationNo) {
                return $current;
            }

            $runs = AiImport::query()
                ->where('input_social_post_id', $postId)
                ->where('input_generation_no', $generationNo)
                ->whereIn('operation', ['social_caption', 'social_image_prompt', 'social_image'])
                ->get(['operation', 'status']);

            $variant = SocialPostVariant::query()
                ->where('social_post_id', $postId)
                ->where('platform', 'facebook')
                ->first();

            $caption = $runs->firstWhere('operation', 'social_caption');
            $image = $runs->firstWhere('operation', 'social_image');
            $imagePrompt = $runs->firstWhere('operation', 'social_image_prompt');
            $captionStatus = $caption?->status
                ?? ($current->caption ? 'ready' : null);
            $readyImages = $variant?->mediaAssetUsagesForField(\App\Enums\MediaAssetField::SocialPostFacebookImages)->count() ?? 0;
            $imageStatus = $variant
                ? ($readyImages >= self::MIN_GALLERY_IMAGES
                    ? 'ready'
                    : ($image?->status && in_array($image?->status, ['failed', 'cancelled', 'expired'], true)
                        ? $image->status
                        : ($readyImages > 0 || $imagePrompt?->status ? 'processing' : null)))
                : ($image?->status
                    ?? ($imagePrompt
                        ? (in_array($imagePrompt->status, ['failed', 'cancelled', 'expired'], true) ? $imagePrompt->status : 'processing')
                        : ($current->mediaAssetUsagesForField(\App\Enums\MediaAssetField::SocialPostImage)->count() >= self::MIN_GALLERY_IMAGES ? 'ready' : null)));
            $status = $this->statusFor($captionStatus, $imageStatus);
            $current->forceFill(['generation_status' => $status])->save();

            return $current->refresh();
        });
    }

    /**
     * Tính aggregate status theo lifecycle của caption và ảnh.
     *
     * Input: status string/null của hai branch.
     * Output: giá trị enum string; không query hoặc ghi database.
     */
    public function statusFor(?string $captionStatus, ?string $imageStatus): SocialPostGenerationStatus
    {
        $ready = static fn (?string $status): bool => in_array($status, ['ready', 'completed', 'succeeded'], true);
        $failed = static fn (?string $status): bool => in_array($status, ['failed', 'cancelled', 'expired'], true);

        if ($ready($captionStatus) && $ready($imageStatus)) {
            return SocialPostGenerationStatus::Ready;
        }
        if ($failed($captionStatus) && $failed($imageStatus)) {
            return SocialPostGenerationStatus::Failed;
        }
        if ($ready($captionStatus) || $ready($imageStatus) || $failed($captionStatus) || $failed($imageStatus)) {
            return SocialPostGenerationStatus::Partial;
        }
        if ($captionStatus !== null || $imageStatus !== null) {
            return SocialPostGenerationStatus::Generating;
        }

        return SocialPostGenerationStatus::Pending;
    }

    /**
     * Xác định generation có đủ caption và ảnh để submit review không.
     *
     * Input: SocialPost đã load/đọc mới.
     * Output: boolean; chỉ đọc trạng thái model và usage ảnh.
     */
    public function isReady(SocialPost $post): bool
    {
        $variant = $post->relationLoaded('variants')
            ? $post->variants->firstWhere('platform', 'facebook')
            : $post->variants()->where('platform', 'facebook')->first();
        if ($variant) {
            return $post->generation_status === SocialPostGenerationStatus::Ready
                && filled(data_get($variant->content_json, 'body'))
                && $variant->mediaAssetUsagesForField(\App\Enums\MediaAssetField::SocialPostFacebookImages)->count() >= self::MIN_GALLERY_IMAGES;
        }

        return $post->generation_status === SocialPostGenerationStatus::Ready
            && filled($post->caption)
            && $post->mediaAssetUsagesForField(\App\Enums\MediaAssetField::SocialPostImage)->count() >= self::MIN_GALLERY_IMAGES;
    }
}
