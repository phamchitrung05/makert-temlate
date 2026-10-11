<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo typed settings cho bảo mật và token.
 * =====================================================================
 * Settings chứa giới hạn đăng nhập và thời hạn token; service settings xử
 * lý fallback, validation và persist.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - group(): trả tên group security.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : security settings từ repository.
 * - OUTPUT: object settings typed; không tự ghi database.
 * =====================================================================
 */
final class SecuritySettings extends Settings
{
    public ?int $token_expiration_days;

    public ?int $login_max_attempts;

    public ?int $login_decay_minutes;

    public int $version;

    /**
     * Trả tên group settings được repository sử dụng.
     *
     * Input: không có.
     * Output: string `security`.
     */
    public static function group(): string
    {
        return 'security';
    }
}
