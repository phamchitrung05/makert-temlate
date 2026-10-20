<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Models\Concerns\HasMediaAssets;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Nội dung Social Post theo từng nền tảng.
 * =====================================================================
 *
 * Variant giữ contract riêng của một nền tảng (Facebook ở v1), trong khi
 * SocialPost giữ identity/lifecycle aggregate dùng chung. Ảnh của variant
 * được liên kết qua MediaAssetUsage với field multiple có thứ tự.
 */
class SocialPostVariant extends Model
{
    use HasFactory, HasMediaAssets, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'social_post_id',
        'platform',
        'format',
        'image_count',
        'content_json',
        'content_status',
        'generation_status',
        'review_status',
        'prompt_snapshot_json',
        'writing_profile_snapshot_json',
        'provider_snapshot_json',
        'current_version_no',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
    ];

    /**
     * Ép kiểu platform, lifecycle status và snapshot JSON.
     *
     * Input: thuộc tính Eloquent SocialPostVariant.
     * Output: enum/array/integer/datetime đúng contract; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'content_json' => 'array',
            'image_count' => 'integer',
            'content_status' => SocialPostGenerationStatus::class,
            'generation_status' => SocialPostGenerationStatus::class,
            'review_status' => SocialPostReviewStatus::class,
            'prompt_snapshot_json' => 'array',
            'writing_profile_snapshot_json' => 'array',
            'provider_snapshot_json' => 'array',
            'current_version_no' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Lấy Social Post cha.
     *
     * Input: variant hiện tại.
     * Output: BelongsTo<SocialPost>; không tự ghi database.
     */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    /**
     * Lấy các Page/account dùng variant này.
     *
     * Input: variant hiện tại.
     * Output: HasMany<SocialPostTarget> theo thứ tự tạo.
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class)->orderBy('id');
    }

    /**
     * Lấy các snapshot content/media của variant.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SocialPostVersion::class)->orderBy('version_no');
    }

    /**
     * Cấu hình activity log cho content/lifecycle variant, không ghi raw secret.
     *
     * Input: model hiện tại.
     * Output: LogOptions bounded cho audit marketing.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly([
                'social_post_id', 'platform', 'format', 'content_json',
                'content_status', 'generation_status', 'review_status',
                'current_version_no', 'reviewed_by', 'reviewed_at', 'review_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
