<?php

namespace App\Models\Concerns;

use App\Models\SeoMetadata;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp quan hệ SEO metadata cho model nội dung.
 * =====================================================================
 *
 * Trait chuẩn hóa quan hệ một-một polymorphic để model mới chỉ cần thêm trait
 * là có thể dùng SeoMetadataService và API serializer dùng chung.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - seoMetadata(): quan hệ metadata SEO duy nhất
 * - seoFallbacks(): fallback title/description/canonical theo model
 * - seoFeaturedImageId(): lấy ID ảnh đại diện public cho OG fallback
 * - bootHasSeoMetadata(): giữ metadata khi soft delete, dọn khi force delete
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : model nội dung có title hoặc trường mô tả tương ứng.
 * - OUTPUT: MorphOne và fallback data cho service SEO.
 * =====================================================================
 */
trait HasSeoMetadata
{
    /** Input: vòng đời model. Output: xóa metadata khi xóa vĩnh viễn, giữ khi soft delete. */
    public static function bootHasSeoMetadata(): void
    {
        static::deleted(function ($model): void {
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                $model->seoMetadata()->delete();
            }
        });
    }

    /** Input: model hiện tại. Output: một bản ghi SeoMetadata. */
    public function seoMetadata(): MorphOne
    {
        return $this->morphOne(SeoMetadata::class, 'seoable');
    }

    /** Input: model hiện tại. Output: giá trị fallback khi metadata để trống. */
    public function seoFallbacks(): array
    {
        return [
            'title' => $this->title ?? null,
            'description' => $this->excerpt ?? $this->short_description ?? null,
            'canonical_url' => null,
            'og_image_id' => $this->seoFeaturedImageId('resource.cover'),
        ];
    }

    /** Input: field media. Output: ID ảnh public chưa xóa; ưu tiên relation eager load. */
    public function seoFeaturedImageId(string $field): ?int
    {
        $this->loadMissing('mediaAssetUsages.mediaAsset');
        $asset = $this->mediaAssetUsages->first(fn ($usage) => $usage->field->value === $field)?->mediaAsset;

        return $asset?->kind === \App\Enums\MediaAssetKind::Image
            && $asset->visibility === \App\Enums\MediaAssetVisibility::Public ? $asset->id : null;
    }
}
