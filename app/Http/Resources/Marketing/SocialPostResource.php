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
 * Resource chỉ trả caption canonical, không có platform_caption/token/raw
 * provider response.
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
}
