<?php

namespace Database\Factories;

use App\Enums\MediaAssetField;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo usage MediaAsset giả cho test và local development
 * =====================================================================
 *
 * Factory mặc định tạo một usage `resource.cover`; các state hỗ trợ đổi field
 * khi test gallery hoặc resource version. Factory không attach file vật lý.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - definition(): usage resource.cover mặc định
 * - resourcePreview(): state resource.preview
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Faker và model factory liên quan
 * - OUTPUT: thuộc tính dùng để tạo MediaAssetUsage
 * =====================================================================
 */
class MediaAssetUsageFactory extends Factory
{
    /** @var class-string<MediaAssetUsage> */
    protected $model = MediaAssetUsage::class;

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo usage resource.cover mặc định
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, mixed>: dữ liệu hợp lệ cho MediaAssetUsage::create()
     */
    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory()->image(),
            'linkable_type' => 'resource',
            'linkable_id' => Resource::factory(),
            'field' => MediaAssetField::ResourceCover,
            'sort_order' => 0,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển factory thành resource.preview
     * =====================================================================
     *
     * OUTPUT:
     * - static: factory state resource.preview multiple
     */
    public function resourcePreview(): static
    {
        return $this->state([
            'field' => MediaAssetField::ResourcePreview,
        ]);
    }
}
