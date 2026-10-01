<?php

namespace App\Http\Resources;

use App\Enums\MediaAssetField;
use App\Services\SeoMetadataService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize Post, metadata SEO và media đã tải.
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): serialize Post, SEO và media.
 * - primarySlugFromLoadedRelation(): lấy slug primary từ relation đã load.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Post/request -> mảng JSON API.
 * =====================================================================
 */
class PostResource extends JsonResource
{
    /** Input: request và Post đã load quan hệ. Output: payload không chứa score client. */
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

        $slug = $this->primarySlugFromLoadedRelation()?->slug;
        $service = app(SeoMetadataService::class);
        $seo = $service->raw($this->resource);
        $ogImage = $this->resource->seoMetadata?->relationLoaded('ogImage')
            ? $this->resource->seoMetadata?->ogImage
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $slug,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
            'focus_keyword' => $seo['focus_keyword'],
            'seo_title' => $seo['seo_title'],
            'seo_description' => $seo['seo_description'],
            'canonical_url' => $seo['canonical_url'],
            'robots_index' => $seo['robots_index'],
            'robots_follow' => $seo['robots_follow'],
            'og_title' => $seo['og_title'],
            'og_description' => $seo['og_description'],
            'og_image_id' => $seo['og_image_id'],
            'og_image' => $ogImage ? MediaAssetResource::make($ogImage) : null,
            'seo_metadata' => $seo,
            'seo_resolved' => $service->resolve($this->resource),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values()),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()),
            'permalink' => url('/blog/'.$slug),
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
     * Input: relation slugs. Output: slug primary của locale hoặc null.
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
