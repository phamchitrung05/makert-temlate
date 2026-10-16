<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện campaign gom product và thông điệp Marketing.
 * =====================================================================
 * Campaign cung cấp mục tiêu/thông điệp cho AI context và daily plan; nó không
 * trực tiếp quyết định publish.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép status và thời gian campaign.
 * - products(): lấy product qua pivot campaign_products.
 * - socialPosts(): lấy Social Post thuộc campaign.
 * - createdBy(): lấy admin tạo campaign.
 * - updatedBy(): lấy admin sửa campaign.
 * - scopeActive(): lọc campaign đang active.
 * - getActivitylogOptions(): cấu hình audit metadata campaign.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tên, mục tiêu, thông điệp, lịch và product được chọn.
 * - OUTPUT: Campaign cùng quan hệ product/social post.
 * - SIDE EFFECT: Eloquent lưu database và ghi activity log khi caller mutate.
 * =====================================================================
 */
class Campaign extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'objective', 'message', 'starts_at', 'ends_at', 'timezone',
        'status', 'created_by', 'updated_by',
    ];

    /**
     * Ép kiểu status và mốc thời gian campaign.
     *
     * Input: thuộc tính Eloquent Campaign.
     * Output: CampaignStatus và Carbon datetime; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Lấy product thuộc campaign qua pivot.
     *
     * Input: campaign hiện tại.
     * Output: BelongsToMany<Product> kèm sort_order/timestamps.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_products')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * Lấy Social Post thuộc campaign.
     *
     * Input: campaign hiện tại.
     * Output: HasMany<SocialPost>; bài đã snapshot vẫn giữ dữ liệu riêng.
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * Lấy admin tạo campaign.
     *
     * Input: campaign hiện tại.
     * Output: BelongsTo<User|null>; chỉ query khi relation được nạp.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lấy admin sửa campaign gần nhất.
     *
     * Input: campaign hiện tại.
     * Output: BelongsTo<User|null>; chỉ query khi relation được nạp.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Giới hạn query campaign đang active.
     *
     * Input: Eloquent builder của Campaign.
     * Output: Builder có điều kiện status=active; không ghi database.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Active);
    }

    /**
     * Cấu hình activity log cho metadata campaign.
     *
     * Input: model hiện tại.
     * Output: LogOptions chỉ ghi field nghiệp vụ thay đổi.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['name', 'objective', 'message', 'starts_at', 'ends_at', 'timezone', 'status', 'created_by', 'updated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
