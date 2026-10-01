<?php

namespace App\Models;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho owner nghiệp vụ của một media asset
 * =====================================================================
 *
 * MediaAsset giữ metadata dùng chung cho ảnh, tài liệu, archive và video.
 * Spatie Media Library lưu file vật lý trong bảng `media` và liên kết về model
 * này qua collection `library`; các model nghiệp vụ sẽ liên kết asset qua
 * media_asset_usages ở Task 3.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): cast kind và visibility thành enum
 * - createdBy(): admin đã tạo asset
 * - usages(): các model/field đang sử dụng asset
 * - scopeOfKind(): lọc theo kind
 * - scopeWithVisibility(): lọc theo visibility
 * - mediaDisk(): chọn disk theo visibility
 * - registerMediaCollections(): đăng ký collection library
 * - registerMediaConversions(): đăng ký conversion preview và ảnh canonical
 * - getActivitylogOptions(): cấu hình audit log media
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata asset và file được thêm qua Spatie Media Library
 * - OUTPUT: MediaAsset có collection `library`, policy disk và metadata audit
 * - SIDE EFFECT: file do Spatie ghi vào disk; thay đổi model được activity log
 * =====================================================================
 */
class MediaAsset extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\MediaAssetFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /**
     * Spatie phải dùng instance hiện tại để conversion biết kind của asset.
     * Nếu dùng model rỗng, archive/document có thể bị đăng ký conversion sai.
     */
    public bool $registerMediaConversionsUsingModelInstance = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'title',
        'alt_text',
        'visibility',
        'created_by',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Cast metadata domain thành enum khi hydrate model
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: kind và visibility dùng enum contract
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaAssetKind::class,
            'visibility' => MediaAssetVisibility::class,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy admin đã tạo asset
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: User hoặc null nếu asset do hệ thống tạo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các usage đang trỏ tới asset
     * =====================================================================
     *
     * OUTPUT:
     * - HasMany: MediaAssetUsage của các model/field nghiệp vụ
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaAssetUsage::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc asset theo nhóm file
     * =====================================================================
     *
     * INPUT:
     * - $kind: enum MediaAssetKind cần lọc
     *
     * OUTPUT:
     * - Builder: query chỉ chứa asset cùng kind
     */
    public function scopeOfKind(Builder $query, MediaAssetKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc asset theo visibility
     * =====================================================================
     *
     * INPUT:
     * - $visibility: enum MediaAssetVisibility cần lọc
     *
     * OUTPUT:
     * - Builder: query chỉ chứa asset cùng visibility
     */
    public function scopeWithVisibility(Builder $query, MediaAssetVisibility $visibility): Builder
    {
        return $query->where('visibility', $visibility->value);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn disk lưu file theo visibility của asset
     * =====================================================================
     *
     * OUTPUT:
     * - string: disk cấu hình cho asset public hoặc private; archive luôn private
     */
    public function mediaDisk(): string
    {
        if ($this->kind === MediaAssetKind::Archive) {
            return (string) config('media-library.asset_disks.private', 'media_private');
        }

        $visibility = $this->visibility instanceof MediaAssetVisibility
            ? $this->visibility
            : MediaAssetVisibility::tryFrom((string) $this->getRawOriginal('visibility'))
                ?? MediaAssetVisibility::Private;

        return (string) config(
            'media-library.asset_disks.'.$visibility->value,
            $visibility === MediaAssetVisibility::Public ? 'media_public' : 'media_private',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký collection trung tâm cho file của asset
     * =====================================================================
     *
     * OUTPUT:
     * - Collection `library` dùng disk theo visibility hiện tại
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('library')->useDisk($this->mediaDisk());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký conversion ảnh dùng chung cho Media Library
     * =====================================================================
     *
     * INPUT:
     * - $media: media item đang được xử lý, có thể null khi package khởi tạo
     *
     * OUTPUT:
     * - Không trả giá trị; image asset có conversion legacy `thumb`/`web` và
     *   canonical `featured`/`og` theo config/media-assets.php
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; conversion được Spatie xử lý đồng bộ hoặc qua queue
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->kind !== MediaAssetKind::Image) {
            return;
        }

        $this->addMediaConversion('thumb')
            ->width(320)
            ->height(240)
            ->sharpen(5)
            ->performOnCollections('library');

        $this->addMediaConversion('web')
            ->width(1600)
            ->height(1200)
            ->performOnCollections('library');

        $this->registerCanonicalImageConversion('featured');
        $this->registerCanonicalImageConversion('og');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký một profile ảnh canonical có crop và format ổn định
     * =====================================================================
     *
     * INPUT:
     * - $name: key `featured` hoặc `og` trong config media-assets
     *
     * OUTPUT:
     * - Không trả giá trị; Media Library nhận conversion crop đúng kích thước
     *   và format public contract.
     *
     * EXCEPTION/TRANSACTION:
     * - Bỏ qua profile thiếu hoặc kích thước không hợp lệ; không mở transaction.
     */
    private function registerCanonicalImageConversion(string $name): void
    {
        $profile = config("media-assets.image_conversions.{$name}", []);
        $width = (int) ($profile['width'] ?? 0);
        $height = (int) ($profile['height'] ?? 0);

        if ($width < 1 || $height < 1) {
            return;
        }

        $conversion = $this->addMediaConversion($name)
            ->fit(Fit::Crop, $width, $height)
            ->performOnCollections('library');

        $format = strtolower((string) ($profile['format'] ?? 'webp'));
        if (in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            $conversion->format($format);
        }

        $quality = (int) ($profile['quality'] ?? 0);
        if ($quality >= 1 && $quality <= 100) {
            $conversion->quality($quality);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cấu hình activity log cho thay đổi metadata asset
     * =====================================================================
     *
     * OUTPUT:
     * - LogOptions: log_name media, chỉ ghi thay đổi metadata cần audit
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('media')
            ->logOnly([
                'kind',
                'title',
                'alt_text',
                'visibility',
                'created_by',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
