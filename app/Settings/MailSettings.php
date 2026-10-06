<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Cấu hình typed nhóm mail; null dùng config môi trường.
 * Input: repository settings. Output: giá trị có kiểu; group/encrypted không ghi DB.
 */
final class MailSettings extends Settings
{
    public ?string $mailer;

    public ?string $host;

    public ?int $port;

    public ?string $scheme;

    public ?string $username;

    public ?string $password;

    public ?string $from_name;

    public ?string $from_address;

    public int $version;

    /** Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'mail';
    }

    /** Password mã hóa trong repository; chỉ service nội bộ được giải mã. */
    public static function encrypted(): array
    {
        return ['password'];
    }
}
