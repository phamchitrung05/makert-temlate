<?php

namespace App\Models;

use App\Enums\ProductStatus;
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
 * CHỨC NĂNG FILE: Đại diện product làm nguồn context cho Social Post.
 * =====================================================================
 * Product lưu thông tin mô tả/CTA và các fact xác thực; Social Post giữ
 * snapshot riêng nên thay đổi product không làm đổi bài đã tạo.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép status và JSON claim về kiểu an toàn.
 * - facts(): lấy các fact thuộc product.
 * - campaigns(): lấy campaign liên kết qua pivot.
 * - socialPosts(): lấy Social Post tham chiếu product.
 * - createdBy(): lấy admin tạo product.
 * - updatedBy(): lấy admin sửa cuối.
 * - scopeActive(): lọc product đang active.
 * - getActivitylogOptions(): cấu hình audit metadata product.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata product và fact được admin xác minh.
 * - OUTPUT: Product cùng quan hệ facts/campaigns/social posts.
 * - SIDE EFFECT: Eloquent lưu database và ghi activity log khi caller mutate.
 * =====================================================================
 */
class Product extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'short_description', 'default_cta', 'prohibited_claims_json',
        'status', 'created_by', 'updated_by',
    ];

    /**
     * Ép kiểu trạng thái và danh sách claim cấm của product.
     *
     * Input: thuộc tính Eloquent của Product.
     * Output: status enum và prohibited claims dạng array; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'prohibited_claims_json' => 'array',
        ];
    }

    /**
     * Lấy các fact thuộc product theo thứ tự tạo.
     *
     * Input: product hiện tại.
     * Output: HasMany<ProductFact>; query chỉ chạy khi relation được truy cập.
     */
    public function facts(): HasMany
    {
        return $this->hasMany(ProductFact::class)->orderBy('id');
    }

    /**
     * Lấy campaign có product này qua bảng pivot.
     *
     * Input: product hiện tại.
     * Output: BelongsToMany<Campaign> kèm sort_order/timestamps.
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_products')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * Lấy Social Post tham chiếu product.
     *
     * Input: product hiện tại.
     * Output: HasMany<SocialPost>; snapshot bài không bị sửa bởi relation này.
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * Lấy admin đã tạo product.
     *
     * Input: product hiện tại.
     * Output: BelongsTo<User|null>; chỉ query khi eager/lazy load.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lấy admin sửa product gần nhất.
     *
     * Input: product hiện tại.
     * Output: BelongsTo<User|null>; chỉ query khi relation được nạp.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Giới hạn query product đang active.
     *
     * Input: Eloquent builder của Product.
     * Output: Builder có điều kiện status=active; không ghi database.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active);
    }

    /**
     * Cấu hình activity log cho metadata product, không log payload fact dài.
     *
     * Input: model hiện tại.
     * Output: LogOptions chỉ ghi field nghiệp vụ thay đổi.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['name', 'short_description', 'default_cta', 'status', 'created_by', 'updated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
