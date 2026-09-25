<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho tài khoản quản trị của hệ thống
 * =====================================================================
 *
 * User chỉ được dùng bởi admin guard. Customer OAuth dùng model Customer
 * và customer guard riêng, vì vậy credential và permission của hai nhóm
 * người dùng không bị trộn lẫn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - isActive(): kiểm tra admin có được phép đăng nhập và gọi protected route
 * - casts(): khai báo kiểu dữ liệu và password hash
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu admin từ seeder, Form Request hoặc authentication provider
 * - OUTPUT: Authenticatable admin model cho guard `admin` và HasRoles của Spatie
 * - SIDE EFFECT: không tự ghi database ngoài thao tác Eloquent của caller
 * =====================================================================
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'admin';

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
