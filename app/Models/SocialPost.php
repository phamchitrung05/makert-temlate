<?php

namespace App\Models;

use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Models\Concerns\HasMediaAssets;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện Social Post canonical dùng cho nhiều target.
 * =====================================================================
 * Model giữ identity/lifecycle aggregate và caption legacy để tương thích; content
 * Facebook mới nằm trong SocialPostVariant. Không có platform_caption.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép trạng thái và mốc review/schedule.
 * - campaign(): campaign context tùy chọn.
 * - targets(): các Page/account đích.
 * - variants(): nội dung theo từng nền tảng.
 * - publications(): publication qua target.
 * - versions(): snapshot bất biến theo version_no.
 * - dailyContentPlans(): các slot đã gắn vào post.
 * - createdBy(): admin/system tạo post.
 * - updatedBy(): admin sửa nội dung.
 * - reviewedBy(): admin quyết định review.
 * - getActivitylogOptions(): audit caption/lifecycle bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : caption canonical, context IDs, status và actor metadata.
 * - OUTPUT: SocialPost có MediaAsset usage và quan hệ publication đầy đủ.
 * - SIDE EFFECT: thay đổi model được activity log; file ảnh do MediaAsset quản lý.
 * =====================================================================
 */
class SocialPost extends Model
{
    use HasFactory, HasMediaAssets, LogsActivity, SoftDeletes;

    protected $fillable = [
        'campaign_id', 'caption', 'generation_status', 'review_status',
        'publication_status', 'generation_no', 'created_by', 'updated_by', 'reviewed_by',
        'reviewed_at', 'review_reason', 'scheduled_at',
    ];

    /**
     * Ép kiểu ba nhóm trạng thái và mốc lifecycle.
     *
     * Input: thuộc tính Eloquent SocialPost.
     * Output: enum status và Carbon datetime; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'generation_status' => SocialPostGenerationStatus::class,
            'review_status' => SocialPostReviewStatus::class,
            'publication_status' => SocialPostPublicationStatus::class,
            'generation_no' => 'integer',
            'reviewed_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    /**
     * Lấy campaign context của Social Post.
     *
     * Input: post hiện tại.
     * Output: BelongsTo<Campaign|null>; snapshot version không phụ thuộc lazy load.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Lấy target/account của Social Post.
     *
     * Input: post hiện tại.
     * Output: HasMany<SocialPostTarget> theo thứ tự tạo.
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class)->orderBy('id');
    }

    /**
     * Lấy các content variant theo nền tảng.
     *
     * Input: post hiện tại.
     * Output: HasMany<SocialPostVariant> theo thứ tự tạo.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class)->orderBy('id');
    }

    /**
     * Lấy mọi publication qua các target.
     *
     * Input: post hiện tại.
     * Output: HasManyThrough<SocialPublication>; không tạo query khi định nghĩa.
     */
    public function publications(): HasManyThrough
    {
        return $this->hasManyThrough(
            SocialPublication::class,
            SocialPostTarget::class,
            'social_post_id',
            'social_post_target_id',
            'id',
            'id',
        );
    }

    /**
     * Lấy lịch sử snapshot của Social Post.
     *
     * Input: post hiện tại.
     * Output: HasMany<SocialPostVersion> tăng dần version_no.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SocialPostVersion::class)->orderBy('version_no');
    }

    /**
     * Lấy daily plan đã liên kết post này.
     *
     * Input: post hiện tại.
     * Output: HasMany<DailyContentPlan>; một post có thể được truy vết từ slot.
     */
    public function dailyContentPlans(): HasMany
    {
        return $this->hasMany(DailyContentPlan::class);
    }

    /**
     * Lấy admin/system tạo Social Post.
     *
     * Input: post hiện tại.
     * Output: BelongsTo<User|null>; không tự ghi database.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lấy admin sửa Social Post gần nhất.
     *
     * Input: post hiện tại.
     * Output: BelongsTo<User|null>; không tự ghi database.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Lấy admin thực hiện review gần nhất.
     *
     * Input: post hiện tại.
     * Output: BelongsTo<User|null>; không tự ghi database.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Cấu hình activity log lifecycle/caption của Social Post.
     *
     * Input: model hiện tại.
     * Output: LogOptions ghi field cần audit, không ghi token/provider raw.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly([
                'campaign_id', 'caption', 'generation_status',
                'review_status', 'publication_status', 'generation_no', 'created_by', 'updated_by',
                'reviewed_by', 'reviewed_at', 'review_reason', 'scheduled_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
