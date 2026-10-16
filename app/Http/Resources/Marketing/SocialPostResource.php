<?php

namespace App\Http\Resources\Marketing;

use App\Enums\MediaAssetField;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize Social Post, image usage và target lifecycle.
 * =====================================================================
 * Resource trả legacy caption để tương thích và Facebook variant structured
 * content/gallery; không có platform_caption/token/raw provider response.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ Social Post và relation đã eager load.
 * - image(): lấy image asset từ usage đã load.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost/request.
 * - OUTPUT: JSON payload cho list/detail/editor.
 * - SIDE EFFECT: chỉ đọc relation; không ghi DB.
 * =====================================================================
 */
final class SocialPostResource extends JsonResource
{
    /**
     * Serialize canonical Social Post và trạng thái từng target.
     *
     * Input: Request và SocialPost đã eager load.
     * Output: array JSON bounded, image asset public-safe khi có.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'campaign_id' => $this->campaign_id,
            'product_id' => $this->product_id,
            'campaign' => $this->whenLoaded('campaign', fn () => $this->campaign ? [
                'id' => (int) $this->campaign->id, 'name' => $this->campaign->name,
            ] : null),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => (int) $this->product->id, 'name' => $this->product->name,
            ] : null),
            'caption' => $this->caption,
            'facebook_variant' => $this->when($this->relationLoaded('variants'), fn () => $this->facebookVariant()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant): array => $this->variant($variant))->values()),
            'generation_no' => (int) $this->generation_no,
            'generation_status' => $this->generation_status?->value,
            'review_status' => $this->review_status?->value,
            'publication_status' => $this->publication_status?->value,
            'review' => [
                'reviewed_by' => $this->reviewed_by,
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
                'reason' => $this->review_reason,
            ],
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'image' => $this->image(),
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(fn ($target): array => [
                'id' => (int) $target->id,
                'platform' => $target->platform?->value,
                'enabled' => (bool) $target->enabled,
                'status' => $target->status?->value,
                'published_at' => $target->published_at?->toIso8601String(),
                'last_error_code' => $target->last_error_code,
                'last_error_message' => $target->last_error_message,
                'account' => $target->relationLoaded('socialAccount') && $target->socialAccount ? [
                    'id' => (int) $target->socialAccount->id,
                    'display_name' => $target->socialAccount->display_name,
                    'external_id' => $target->socialAccount->external_id,
                    'provider' => $target->socialAccount->provider?->value,
                    'status' => $target->socialAccount->status?->value,
                ] : null,
            ])->values()),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($version): array => [
                'id' => (int) $version->id,
                'version_no' => (int) $version->version_no,
                'caption' => $version->caption,
                'content' => $version->content_json ?? [],
                'variant_id' => $version->social_post_variant_id ? (int) $version->social_post_variant_id : null,
                'asset_ids' => $version->asset_ids_json ?? [],
                'reason' => $version->reason,
                'version_hash' => $version->version_hash,
                'created_by' => $version->created_by,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->values()),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Lấy image asset từ relation usages đã eager load.
     *
     * Input: SocialPost mediaAssetUsages relation.
     * Output: MediaAssetResource/null; không query thêm ngoài relation.
     */
    private function image(): mixed
    {
        if (! $this->resource->relationLoaded('mediaAssetUsages')) {
            return null;
        }
        $asset = $this->mediaAssetUsages
            ->first(fn ($usage): bool => $usage->field === MediaAssetField::SocialPostImage)
            ?->mediaAsset;

        return $asset ? MediaAssetResource::make($asset) : null;
    }

    private function facebookVariant(): ?array
    {
        $variant = $this->variants->first(fn ($variant): bool => ($variant->platform?->value ?? (string) $variant->platform) === 'facebook');

        return $variant ? $this->variant($variant) : null;
    }

    private function variant($variant): array
    {
        $images = $variant->relationLoaded('mediaAssetUsages')
            ? $variant->mediaAssetUsages
                ->where('field', \App\Enums\MediaAssetField::SocialPostFacebookImages)
                ->sortBy(fn ($usage): int => (int) ($usage->sort_order ?? 0))
                ->map(fn ($usage): array => [
                    'usage_id' => (int) $usage->id,
                    'sort_order' => (int) ($usage->sort_order ?? 0),
                    'asset' => $usage->relationLoaded('mediaAsset') && $usage->mediaAsset
                        ? MediaAssetResource::make($usage->mediaAsset)->resolve()
                        : null,
                ])->values()->all()
            : [];

        return [
            'id' => (int) $variant->id,
            'platform' => $variant->platform?->value ?? (string) $variant->platform,
            'format' => $variant->format,
            'content' => $variant->content_json ?? [],
            'content_status' => $variant->content_status?->value,
            'generation_status' => $variant->generation_status?->value,
            'review_status' => $variant->review_status?->value,
            'current_version_no' => (int) $variant->current_version_no,
            'review' => [
                'reviewed_by' => $variant->reviewed_by,
                'reviewed_at' => $variant->reviewed_at?->toIso8601String(),
                'reason' => $variant->review_reason,
            ],
            'images' => $images,
        ];
    }
}
