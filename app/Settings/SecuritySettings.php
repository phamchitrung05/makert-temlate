<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Cấu hình typed nhóm security; null dùng config môi trường.
 * Input: repository settings. Output: giá trị có kiểu; group/encrypted không ghi DB.
 */
final class SecuritySettings extends Settings
{
    public ?int $token_expiration_days;

    public ?int $login_max_attempts;

    public ?int $login_decay_minutes;

    public int $version;

    /** Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'security';
    }
}
