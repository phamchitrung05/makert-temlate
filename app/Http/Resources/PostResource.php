<?php

namespace App\Http\Resources;

use App\Enums\MediaAssetField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Định hình Post cùng thumbnail và content images. */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $usages = $this->resource->relationLoaded('mediaAssetUsages')
            ? $this->mediaAssetUsages
            : collect();
        $thumbnail = $usages
            ->first(fn ($usage): bool => $usage->field === MediaAssetField::PostThumbnail)
            ?->mediaAsset;
        $contentImages = $usages
            ->filter(fn ($usage): bool => $usage->field === MediaAssetField::PostContentImages)
            ->sortBy('sort_order')
            ->map(fn ($usage) => $usage->mediaAsset)
            ->filter();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->primarySlugFromLoadedRelation()?->slug,
            'content' => $this->content,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'media' => [
                'thumbnail' => $thumbnail ? MediaAssetResource::make($thumbnail) : null,
                'content_images' => MediaAssetResource::collection($contentImages),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Trả slug primary từ relation đã eager load để list Post không tạo N+1;
     * fallback về model helper cho các caller chỉ hydrate một Post.
     */
    private function primarySlugFromLoadedRelation(): ?\App\Models\Slug
    {
        if (! $this->resource->relationLoaded('slugs')) {
            return $this->primarySlug();
        }

        return $this->slugs
            ->first(fn ($slug): bool => $slug->is_primary
                && $slug->locale === config('app.locale'));
    }
}
