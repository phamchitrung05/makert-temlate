<?php

namespace App\Models;

use App\Enums\ResourceVersionTemplateProcessingStatus;
use Database\Factories\ResourceVersionTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu metadata xử lý template của một ResourceVersion
 * =====================================================================
 *
 * Model là snapshot processing độc lập với status phát hành của
 * ResourceVersion. Manifest ở đây là manifest v1 đã được chuẩn hóa trước khi
 * nhập hệ thống; parser/job ở TPL-03/04 sẽ cập nhật processing state và screens.
 * Source media chỉ là dấu vết input/checksum; package vẫn được liên kết qua
 * MediaAssetUsageService và field resource_version.package.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resourceVersion(): version sở hữu metadata template
 * - sourceMedia(): MediaAsset archive đã được xử lý
 * - screens(): danh sách screen theo thứ tự
 * - invalidateForPackageMutation(): xóa snapshot processing khi package đổi
 * - scopeReady(): lọc template processing đã ready
 * - scopeProcessing(): lọc template đang pending/processing
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata manifest/checksum/token từ action/job template
 * - OUTPUT: model queryable cho ready gate, preview và admin API
 * - SIDE EFFECT: Eloquent persistence khi caller save; model không tự mở transaction
 * - EXCEPTION/TRANSACTION: FK/enum/unique lỗi do database hoặc cast; caller chịu transaction
 * =====================================================================
 */
class ResourceVersionTemplate extends Model
{
    /** @use HasFactory<ResourceVersionTemplateFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'processing_status' => ResourceVersionTemplateProcessingStatus::Pending->value,
    ];

    /** @var list<string> */
    protected $fillable = [
        'resource_version_id',
        'source_media_id',
        'package_sha256',
        'processing_status',
        'processing_token',
        'source_root',
        'entry_screen_slug',
        'manifest_json',
        'preview_path',
        'processing_error',
        'processed_at',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast trạng thái, manifest và thời gian xử lý
     * =====================================================================
     * INPUT: State hydrate từ database.
     * OUTPUT: array cast enum/JSON/datetime cho Eloquent.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Enum cast lỗi nếu status ngoài contract; không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'processing_status' => ResourceVersionTemplateProcessingStatus::class,
            'manifest_json' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy ResourceVersion sở hữu template metadata
     * =====================================================================
     * INPUT: Không có; dùng resource_version_id.
     * OUTPUT: BelongsTo ResourceVersion.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy MediaAsset archive là input processing
     * =====================================================================
     * INPUT: Không có; dùng source_media_id.
     * OUTPUT: BelongsTo MediaAsset hoặc null.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function sourceMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'source_media_id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy screens theo sort order
     * =====================================================================
     * INPUT: Không có; relation đã đặt thứ tự ổn định.
     * OUTPUT: HasMany ResourceVersionTemplateScreen.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function screens(): HasMany
    {
        return $this->hasMany(ResourceVersionTemplateScreen::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Invalidate metadata và preview khi package version thay đổi
     * =====================================================================
     * INPUT: Caller đã khóa version và detach usage screenshot qua service.
     * OUTPUT: ResourceVersionTemplate hiện tại ở trạng thái pending rỗng.
     * SIDE EFFECT: Xóa screen snapshot và pointer preview đã cũ.
     * EXCEPTION/TRANSACTION: Không tự mở transaction; caller khóa version.
     * =====================================================================
     */
    public function invalidateForPackageMutation(): static
    {
        $this->screens()->delete();
        $this->forceFill([
            'source_media_id' => null,
            'package_sha256' => null,
            'processing_status' => ResourceVersionTemplateProcessingStatus::Pending,
            'processing_token' => null,
            'source_root' => null,
            'entry_screen_slug' => null,
            'manifest_json' => null,
            'preview_path' => null,
            'processing_error' => null,
            'processed_at' => null,
        ])->save();
        $this->unsetRelation('screens');

        return $this;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc template đã xử lý ready
     * =====================================================================
     * INPUT: Builder ResourceVersionTemplate.
     * OUTPUT: Builder có processing_status=ready.
     * SIDE EFFECT: Không có; chưa thực thi query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('processing_status', ResourceVersionTemplateProcessingStatus::Ready);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc template đang chờ hoặc đang processing
     * =====================================================================
     * INPUT: Builder ResourceVersionTemplate.
     * OUTPUT: Builder có status pending/processing.
     * SIDE EFFECT: Không có; chưa thực thi query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->whereIn('processing_status', [
            ResourceVersionTemplateProcessingStatus::Pending,
            ResourceVersionTemplateProcessingStatus::Processing,
        ]);
    }
}
