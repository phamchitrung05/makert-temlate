<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho hồ sơ và tài khoản của customer
 * =====================================================================
 *
 * Customer được xác thực qua customer guard và các identity OAuth trong
 * customer_identities. Model này không dùng password mặc định và không
 * nhận permission back-office của Spatie.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - identities(): lấy các Google/Facebook identity của customer
 * - isActive(): kiểm tra customer có được phép dùng protected account route
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : profile customer và trạng thái identity được lưu trong database
 * - OUTPUT: Authenticatable customer model cho guard `customer`
 * - SIDE EFFECT: không tự ghi database ngoài thao tác Eloquent của caller
 * =====================================================================
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast cho dữ liệu customer
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: các timestamp được hydrate thành Carbon datetime
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các OAuth identity thuộc customer
     * =====================================================================
     *
     * INPUT:
     * - Không có input ngoài customer id của model hiện tại
     *
     * OUTPUT:
     * - HasMany<CustomerIdentity>: quan hệ lazy/eager load được
     *
     * SIDE EFFECT:
     * - Không có; query chỉ chạy khi relation được truy cập
     * =====================================================================
     */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra customer có đang active
     * =====================================================================
     *
     * INPUT:
     * - Không có input ngoài state hiện tại của model
     *
     * OUTPUT:
     * - bool: true khi status là `active`
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
