<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho tài khoản quản trị của hệ thống
 * =====================================================================
 *
 * User là model admin được xác thực qua Sanctum. Customer OAuth dùng model
 * Customer và customer guard riêng, vì vậy credential và permission của hai
 * nhóm người dùng không bị trộn lẫn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - isActive(): kiểm tra admin có được phép đăng nhập và gọi protected route
 * - casts(): khai báo kiểu dữ liệu và password hash
 * - booted(): thu hồi token khi credential/trạng thái thay đổi hoặc xóa mềm.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu admin từ seeder, Form Request hoặc authentication provider
 * - OUTPUT: Authenticatable admin model cho Sanctum và HasRoles của Spatie
 * - SIDE EFFECT: thu hồi token khi email/password/status thay đổi hoặc tài khoản bị xóa.
 * =====================================================================
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'admin';

    /** Input: sự kiện Eloquent. Output: xóa token khi đổi credential/status hoặc xóa tài khoản; caller giữ transaction. */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged(['email', 'password', 'status'])) {
                $user->tokens()->delete();
            }
        });
        static::deleted(fn (User $user) => $user->tokens()->delete());
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast cho trạng thái và credential admin
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: email_verified_at thành datetime và password thành hash
     *
     * SIDE EFFECT:
     * - Không có; Laravel dùng cấu hình này khi hydrate/serialize model
     * =====================================================================
     * Input: tham số theo chữ ký `casts()` và state đã hydrate của class.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra trạng thái hoạt động của admin
     * =====================================================================
     *
     * INPUT:
     * - Không có input ngoài state hiện tại của model
     *
     * OUTPUT:
     * - bool: true khi admin có status `active`
     *
     * SIDE EFFECT:
     * - Không có
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction và không phát sinh exception nghiệp vụ
     * =====================================================================
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
