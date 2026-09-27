<?php

namespace App\Models;

use App\Enums\MediaAssetField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho một lần MediaAsset được dùng trong domain
 * =====================================================================
 *
 * Usage lưu ngữ cảnh model/field và thứ tự hiển thị, ví dụ asset image được
 * dùng ở `resource.preview`. File vật lý không nằm ở model này; quan hệ
 * `mediaAsset()` trỏ về owner MediaAsset và `linkable()` trỏ về model nghiệp vụ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - mediaAsset(): asset được sử dụng
 * - linkable(): model nghiệp vụ sử dụng asset
 * - casts(): cast field thành MediaAssetField và sort_order thành integer
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : media_asset_id, linkable morph và field từ usage service
 * - OUTPUT: bản ghi liên kết có thể reorder hoặc detach trong transaction
 * =====================================================================
 */
class MediaAssetUsage extends Model
{
    /** @use HasFactory<\Database\Factories\MediaAssetUsageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'media_asset_id',
        'linkable_type',
        'linkable_id',
        'field',
        'sort_order',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast field và thứ tự usage
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: field thành enum, sort_order thành integer
     */
    protected function casts(): array
    {
        return [
            'field' => MediaAssetField::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy MediaAsset owner của usage
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: MediaAsset liên kết
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy model nghiệp vụ đang sử dụng asset
     * =====================================================================
     *
     * OUTPUT:
     * - MorphTo: Resource, ResourceVersion hoặc model linkable khác
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
