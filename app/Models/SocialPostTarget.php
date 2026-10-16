<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Enums\SocialPublicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện một target Page/account của Social Post.
 * =====================================================================
 * Target tách status/error theo từng account; caption vẫn đọc từ SocialPost
 * canonical và không có cột platform_caption.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép platform/status/enabled/date.
 * - socialPost(): Social Post canonical.
 * - socialAccount(): Page/account đích.
 * - publications(): các lần gọi publisher/retry.
 * - getActivitylogOptions(): audit status target bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : social_post_id, social_account_id, platform và lifecycle status.
 * - OUTPUT: target có publication history riêng theo Page.
 * - SIDE EFFECT: status/error mutation được activity log.
 * =====================================================================
 */
class SocialPostTarget extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'social_post_id', 'social_account_id', 'platform', 'enabled', 'status',
        'published_at', 'last_error_code', 'last_error_message',
    ];

    /**
     * Ép kiểu platform/status và cờ target.
     *
     * Input: thuộc tính Eloquent SocialPostTarget.
     * Output: enum/bool/datetime đúng contract; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'enabled' => 'boolean',
            'status' => SocialPublicationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Lấy Social Post canonical.
     *
     * Input: target hiện tại.
     * Output: BelongsTo<SocialPost>; không tự ghi database.
     */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    /**
     * Lấy account/page đích.
     *
     * Input: target hiện tại.
     * Output: BelongsTo<SocialAccount>; account soft delete vẫn giữ lịch sử.
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Lấy các lần publication/retry của target.
     *
     * Input: target hiện tại.
     * Output: HasMany<SocialPublication> theo thứ tự tạo.
     */
    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class)->orderBy('id');
    }

    /**
     * Cấu hình activity log target, loại trừ response raw.
     *
     * Input: model hiện tại.
     * Output: LogOptions ghi status/error bounded.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['social_post_id', 'social_account_id', 'platform', 'enabled', 'status', 'published_at', 'last_error_code', 'last_error_message'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
