<?php

namespace App\Models;

use App\Enums\DailyContentPlanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện một slot tạo draft Social Post trong ngày.
 * =====================================================================
 * Unique planned_date/slot ngăn scheduler tạo trùng. Plan chỉ là projection
 * điều phối generation; publish vẫn cần approval riêng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép ngày/status/count/date về kiểu an toàn.
 * - campaign(): campaign tùy chọn của slot.
 * - product(): product tùy chọn của slot.
 * - socialPost(): draft được tạo từ slot.
 * - getActivitylogOptions(): audit lifecycle slot bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ngày/slot/timezone và context tùy chọn của scheduler.
 * - OUTPUT: DailyContentPlan có lifecycle và liên kết draft.
 * - SIDE EFFECT: Eloquent lưu trạng thái điều phối; không tự dispatch job.
 * =====================================================================
 */
class DailyContentPlan extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'planned_date', 'slot', 'timezone', 'campaign_id', 'product_id',
        'social_post_id', 'target_draft_count', 'status', 'error_code',
        'error_message', 'started_at', 'completed_at',
    ];

    /**
     * Ép kiểu daily plan status, date/count và lifecycle timestamps.
     *
     * Input: thuộc tính Eloquent DailyContentPlan.
     * Output: enum/integer/date/datetime đúng contract; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'slot' => 'integer',
            'target_draft_count' => 'integer',
            'status' => DailyContentPlanStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Lấy campaign context của slot.
     *
     * Input: plan hiện tại.
     * Output: BelongsTo<Campaign|null>; không tự ghi database.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Lấy product context của slot.
     *
     * Input: plan hiện tại.
     * Output: BelongsTo<Product|null>; không tự ghi database.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Lấy draft Social Post được tạo từ slot.
     *
     * Input: plan hiện tại.
     * Output: BelongsTo<SocialPost|null>; plan chưa chạy có thể null.
     */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    /**
     * Cấu hình activity log lifecycle daily plan.
     *
     * Input: model hiện tại.
     * Output: LogOptions ghi status/error bounded, không ghi provider response.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['planned_date', 'slot', 'timezone', 'campaign_id', 'product_id', 'social_post_id', 'target_draft_count', 'status', 'error_code', 'error_message', 'started_at', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
