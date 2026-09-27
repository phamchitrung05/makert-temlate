<?php

namespace Database\Factories;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo metadata MediaAsset giả cho test và local development
 * =====================================================================
 *
 * Factory không tự tạo file vật lý; upload và attach file là trách nhiệm của
 * Media Library workflow. Các state cung cấp asset đúng kind/visibility để
 * test model, policy và API mà không phụ thuộc filesystem.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - definition(): metadata mặc định của asset image public
 * - image(): state image public
 * - document(): state document private
 * - archive(): state archive private
 * - video(): state video private
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Faker và state tùy chọn của test
 * - OUTPUT: thuộc tính dùng để tạo MediaAsset
 * =====================================================================
 */
class MediaAssetFactory extends Factory
{
    /** @var class-string<MediaAsset> */
    protected $model = MediaAsset::class;

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo metadata mặc định cho một asset ảnh public
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, mixed>: dữ liệu hợp lệ cho MediaAsset::create()
     */
    public function definition(): array
    {
        return [
            'kind' => MediaAssetKind::Image,
            'title' => fake()->sentence(3),
            'alt_text' => fake()->optional()->sentence(6),
            'visibility' => MediaAssetVisibility::Public,
            'created_by' => User::factory(),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển factory thành image public
     * =====================================================================
     *
     * OUTPUT:
     * - static: factory state image/public
     */
    public function image(): static
    {
        return $this->state([
            'kind' => MediaAssetKind::Image,
            'visibility' => MediaAssetVisibility::Public,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển factory thành document private
     * =====================================================================
     *
     * OUTPUT:
     * - static: factory state document/private
     */
    public function document(): static
    {
        return $this->state([
            'kind' => MediaAssetKind::Document,
            'visibility' => MediaAssetVisibility::Private,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển factory thành archive private
     * =====================================================================
     *
     * OUTPUT:
     * - static: factory state archive/private
     */
    public function archive(): static
    {
        return $this->state([
            'kind' => MediaAssetKind::Archive,
            'visibility' => MediaAssetVisibility::Private,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển factory thành video private
     * =====================================================================
     *
     * OUTPUT:
     * - static: factory state video/private
     */
    public function video(): static
    {
        return $this->state([
            'kind' => MediaAssetKind::Video,
            'visibility' => MediaAssetVisibility::Private,
        ]);
    }
}
