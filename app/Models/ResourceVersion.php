<?php

namespace App\Models;

use App\Enums\ResourceVersionStatus;
use App\Models\Concerns\HasMediaAssets;
use Database\Factories\ResourceVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho một phiên bản của resource
 * =====================================================================
 *
 * Mỗi resource có nhiều phiên bản, mỗi phiên bản gắn một package riêng qua
 * Media Library ở Đợt 3. Trường `is_default` đánh dấu phiên bản được tải mặc
 * định khi khách không chỉ định cụ thể; mỗi resource chỉ có một bản default.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resource(): resource cha của phiên bản
 * - template(): metadata xử lý template 1:1 nếu resource type là template
 * - templateScreens(): screens của template qua metadata 1:1
 * - scopeDefault(): lọc phiên bản mặc định
 * - scopeReady(): lọc phiên bản sẵn sàng phát hành
 * - scopeArchived(): lọc phiên bản đã archive
 * - scopePromotable(): lọc version ready chưa là default
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu phiên bản từ CreateResourceVersionAction
 * - OUTPUT: ResourceVersion dùng cho tải package và thông báo cập nhật
 * =====================================================================
 */
class ResourceVersion extends Model
{
    /** @use HasFactory<ResourceVersionFactory> */
    use HasFactory, HasMediaAssets, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'version',
        'changelog',
        'requirements',
        'status',
        'is_default',
        'released_at',
        'created_by',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast enum, boolean, JSON và datetime
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: status thành enum, requirements thành array
     * Input: tham số theo chữ ký `casts()` và state đã hydrate của class.
     */
    protected function casts(): array
    {
        return [
            'status' => ResourceVersionStatus::class,
            'is_default' => 'boolean',
            'requirements' => 'array',
            'released_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy resource cha của phiên bản
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: Resource
     * Input: tham số theo chữ ký `resource()` và state đã hydrate của class.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy admin đã tạo phiên bản
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: User hoặc null
     * Input: tham số theo chữ ký `createdBy()` và state đã hydrate của class.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy metadata template gắn một-một với version
     * =====================================================================
     * INPUT: Không có; dùng resource_version_id của model hiện tại.
     * OUTPUT: HasOne ResourceVersionTemplate hoặc null.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function template(): HasOne
    {
        return $this->hasOne(ResourceVersionTemplate::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy screens của template version qua metadata
     * =====================================================================
     * INPUT: Không có; relation đi qua resource_version_templates.
     * OUTPUT: HasManyThrough ResourceVersionTemplateScreen.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function templateScreens(): HasManyThrough
    {
        return $this->hasManyThrough(
            ResourceVersionTemplateScreen::class,
            ResourceVersionTemplate::class,
            'resource_version_id',
            'resource_version_template_id',
            'id',
            'id',
        )->orderBy('resource_version_template_screens.sort_order')
            ->orderBy('resource_version_template_screens.id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn query chỉ lấy phiên bản mặc định
     * =====================================================================
     *
     * INPUT:
     * - Không có; điều kiện cố định là is_default = true
     *
     * OUTPUT:
     * - Builder: query đã lọc
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn query chỉ lấy phiên bản sẵn sàng phát hành
     * =====================================================================
     *
     * INPUT:
     * - Không có; điều kiện cố định là status = ready
     *
     * OUTPUT:
     * - Builder: query đã lọc
     */
    public function scopeReady($query)
    {
        return $query->where('status', ResourceVersionStatus::Ready);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc version đã archive
     * =====================================================================
     * INPUT: Builder ResourceVersion.
     * OUTPUT: Builder có status archived.
     * SIDE EFFECT: Không thực thi query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', ResourceVersionStatus::Archived);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc version ready có thể promote
     * =====================================================================
     * INPUT: Builder ResourceVersion.
     * OUTPUT: Builder status ready và chưa là default.
     * SIDE EFFECT: Không thực thi query; template gate vẫn phải chạy trước mutate.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function scopePromotable(Builder $query): Builder
    {
        return $query->where('status', ResourceVersionStatus::Ready)
            ->where('is_default', false);
    }
}
