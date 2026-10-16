<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu một fact có nguồn và trạng thái xác minh của product.
 * =====================================================================
 * Fact là dữ liệu grounding cho AI; source/verified/version giúp audit caption
 * và ảnh đã sử dụng thông tin nào.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép verified/version/date về kiểu an toàn.
 * - product(): product sở hữu fact.
 * - verifiedBy(): admin xác minh fact.
 * - getActivitylogOptions(): cấu hình audit fact và trạng thái xác minh.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : key/value/source do admin cung cấp hoặc duyệt.
 * - OUTPUT: ProductFact truy vết được nguồn và phiên bản.
 * - SIDE EFFECT: Eloquent đọc/ghi database khi caller thực hiện.
 * =====================================================================
 */
class ProductFact extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'product_id', 'fact_key', 'fact_value', 'source', 'verified',
        'verified_by', 'verified_at', 'version', 'is_allowed_for_ai',
    ];

    /**
     * Ép kiểu dữ liệu xác minh fact.
     *
     * Input: thuộc tính Eloquent ProductFact.
     * Output: boolean/integer/datetime đúng contract; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'verified_at' => 'datetime',
            'version' => 'integer',
            'is_allowed_for_ai' => 'boolean',
        ];
    }

    /**
     * Lấy product sở hữu fact.
     *
     * Input: fact hiện tại.
     * Output: BelongsTo<Product>; chỉ query khi relation được nạp.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Lấy admin đã xác minh fact.
     *
     * Input: fact hiện tại.
     * Output: BelongsTo<User|null>; không tự thay đổi trạng thái.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Cấu hình activity log fact, giữ source/giá trị để truy vết thay đổi.
     *
     * Input: model hiện tại.
     * Output: LogOptions ghi fact mutation; không ghi raw provider response.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['product_id', 'fact_key', 'fact_value', 'source', 'verified', 'verified_by', 'verified_at', 'version', 'is_allowed_for_ai'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
