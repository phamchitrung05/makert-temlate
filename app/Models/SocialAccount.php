<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện Facebook Page/account được kết nối vào hệ thống.
 * =====================================================================
 * Token được lưu qua encrypted cast và luôn hidden khỏi serialization. OAuth,
 * refresh/reconnect và health check thuộc MP-06; model MP-01 chỉ giữ schema.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép provider/status/token/date/JSON về kiểu an toàn.
 * - socialPostTargets(): lấy các target dùng account.
 * - createdBy(): admin tạo kết nối.
 * - updatedBy(): admin sửa metadata kết nối.
 * - getActivitylogOptions(): audit metadata account, loại trừ token.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata Page và access token server-side.
 * - OUTPUT: SocialAccount có quan hệ target và trạng thái health.
 * - SIDE EFFECT: encrypted cast bảo vệ token khi Eloquent ghi database.
 * =====================================================================
 */
class SocialAccount extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'provider', 'account_type', 'external_id', 'display_name', 'access_token',
        'token_expires_at', 'scopes_json', 'status', 'metadata_json',
        'last_checked_at', 'created_by', 'updated_by',
    ];

    protected $hidden = ['access_token'];

    /**
     * Ép kiểu token, status, scope/metadata và mốc health check.
     *
     * Input: thuộc tính Eloquent SocialAccount.
     * Output: token encrypted, provider/status enum và JSON/datetime an toàn.
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialPlatform::class,
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes_json' => 'array',
            'status' => SocialAccountStatus::class,
            'metadata_json' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * Lấy target Social Post đang dùng account này.
     *
     * Input: account hiện tại.
     * Output: HasMany<SocialPostTarget>; chỉ query khi relation được nạp.
     */
    public function socialPostTargets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    /**
     * Lấy admin tạo kết nối.
     *
     * Input: account hiện tại.
     * Output: BelongsTo<User|null>; không tự ghi database.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lấy admin sửa metadata kết nối gần nhất.
     *
     * Input: account hiện tại.
     * Output: BelongsTo<User|null>; không tự ghi database.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Cấu hình activity log account và loại trừ token/metadata nhạy cảm.
     *
     * Input: model hiện tại.
     * Output: LogOptions chỉ ghi identity/status bounded.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('marketing')
            ->logOnly(['provider', 'account_type', 'external_id', 'display_name', 'token_expires_at', 'status', 'last_checked_at', 'created_by', 'updated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
