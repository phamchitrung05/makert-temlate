<?php

namespace App\Models;

use App\Enums\SocialPublicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu một lần gọi publisher và kết quả external của target.
 * =====================================================================
 * Publication có idempotency key, attempts và bounded metadata để retry từng
 * Page mà không tạo bài trùng hoặc ghi raw provider response.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép status/attempts/metadata/date.
 * - socialPostTarget(): target sở hữu publication.
 * - getActivitylogOptions(): audit status/external ID/error bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : target, idempotency key, provider result/error.
 * - OUTPUT: SocialPublication immutable-ish history của lần publish.
 * - SIDE EFFECT: mutation được activity log; không tự gọi provider.
 * =====================================================================
 */
class SocialPublication extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'social_post_target_id', 'idempotency_key', 'status', 'provider_request_id',
        'external_post_id', 'external_post_url', 'attempts', 'response_metadata_json',
        'error_code', 'error_message', 'queued_at', 'started_at', 'completed_at',
    ];

    /**
     * Ép kiểu publication status, attempts, metadata và lifecycle dates.
     *
     * Input: thuộc tính Eloquent SocialPublication.
     * Output: enum/integer/array/datetime đúng contract; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'status' => SocialPublicationStatus::class,
            'attempts' => 'integer',
            'response_metadata_json' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Lấy target sở hữu publication.
     *
     * Input: publication hiện tại.
     * Output: BelongsTo<SocialPostTarget>; không tự ghi database.
     */
    public function socialPostTarget(): BelongsTo
    {
        return $this->belongsTo(SocialPostTarget::class);
    }

    /**
     * Cấu hình activity log publication, không ghi metadata raw không bounded.
     *
     * Input: model hiện tại.
     * Output: LogOptions ghi idempotency/status/external ID/error.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['social_post_target_id', 'idempotency_key', 'status', 'provider_request_id', 'external_post_id', 'external_post_url', 'attempts', 'error_code', 'error_message'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
