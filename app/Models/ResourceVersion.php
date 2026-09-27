<?php

namespace App\Models;

use App\Enums\ResourceVersionStatus;
use App\Models\Concerns\HasMediaAssets;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * - scopeDefault(): lọc phiên bản mặc định
 * - scopeReady(): lọc phiên bản sẵn sàng phát hành
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu phiên bản từ CreateResourceVersionAction
 * - OUTPUT: ResourceVersion dùng cho tải package và thông báo cập nhật
 * =====================================================================
 */
class ResourceVersion extends Model
{
    /** @use HasFactory<\Database\Factories\ResourceVersionFactory> */
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
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
