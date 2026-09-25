<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu identity OAuth của customer
 * =====================================================================
 *
 * Một customer có thể liên kết nhiều provider. Cặp provider và
 * provider_user_id là định danh ổn định; email chỉ dùng cho hiển thị và
 * onboarding, không dùng một mình để tự động hợp nhất tài khoản.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - customer(): lấy customer sở hữu identity
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : provider, provider_user_id và metadata tối thiểu từ Socialite
 * - OUTPUT: Eloquent identity model liên kết với Customer
 * - SIDE EFFECT: không tự gọi provider và không lưu access token
 * =====================================================================
 */
class CustomerIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'provider_name',
        'provider_avatar_url',
        'last_used_at',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast cho thời điểm dùng identity
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: last_used_at được hydrate thành Carbon datetime
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy customer sở hữu OAuth identity
     * =====================================================================
     *
     * INPUT:
     * - customer_id của identity
     *
     * OUTPUT:
     * - BelongsTo<Customer>: customer liên quan
     *
     * SIDE EFFECT:
     * - Không có; query chạy khi relation được truy cập
     * =====================================================================
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
