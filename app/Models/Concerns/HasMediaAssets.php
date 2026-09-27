<?php

namespace App\Models\Concerns;

use App\Enums\MediaAssetField;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp quan hệ MediaAsset cho model nghiệp vụ
 * =====================================================================
 *
 * Trait dùng lại cho Resource, ResourceVersion và Post khi model Post được
 * triển khai. Nó chỉ khai báo relation; kiểm tra field/kind/cardinality và
 * authorization nằm trong MediaAssetUsageService.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - mediaAssetUsages(): toàn bộ usage của model
 * - mediaAssets(): asset qua morph pivot mở rộng
 * - mediaAssetUsagesForField(): usage lọc theo field
 * - mediaAssetsForField(): asset lọc theo field
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : model dùng trait và MediaAssetField cần truy vấn
 * - OUTPUT: quan hệ Eloquent dùng cho eager load, attach query và reorder
 * =====================================================================
 */
trait HasMediaAssets
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy toàn bộ usage polymorphic của model
     * =====================================================================
     *
     * OUTPUT:
     * - MorphMany: MediaAssetUsage thuộc model hiện tại
     */
    public function mediaAssetUsages(): MorphMany
    {
        return $this->morphMany(MediaAssetUsage::class, 'linkable');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy asset qua usage table
     * =====================================================================
     *
     * OUTPUT:
     * - MorphToMany: MediaAsset kèm field và sort_order ở pivot
     */
    public function mediaAssets(): MorphToMany
    {
        return $this->morphToMany(
            MediaAsset::class,
            'linkable',
            'media_asset_usages',
            'linkable_id',
            'media_asset_id',
        )->withPivot(['field', 'sort_order'])->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc usage theo field nghiệp vụ
     * =====================================================================
     *
     * INPUT:
     * - $field: field được phép trong MediaAssetField
     *
     * OUTPUT:
     * - MorphMany: usage chỉ thuộc field được chỉ định
     */
    public function mediaAssetUsagesForField(MediaAssetField $field): MorphMany
    {
        return $this->mediaAssetUsages()->where('field', $field->value);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc asset theo field nghiệp vụ
     * =====================================================================
     *
     * INPUT:
     * - $field: field được phép trong MediaAssetField
     *
     * OUTPUT:
     * - MorphToMany: asset chỉ thuộc field được chỉ định
     */
    public function mediaAssetsForField(MediaAssetField $field): MorphToMany
    {
        return $this->mediaAssets()->wherePivot('field', $field->value);
    }
}
