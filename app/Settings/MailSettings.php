<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo typed settings cho mail transport.
 * =====================================================================
 * Settings giữ cấu hình mail đã lưu; password được đánh dấu encrypted và
 * chỉ service nội bộ được giải mã khi gửi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - group(): trả tên group mail.
 * - encrypted(): khai báo field cần mã hóa.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị mail settings từ repository.
 * - OUTPUT: object settings typed và metadata mã hóa; không tự ghi database.
 * =====================================================================
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

    /**
     * Trả tên group settings được repository sử dụng.
     *
     * Input: không có.
     * Output: string `mail`.
     */
    public static function group(): string
    {
        return 'mail';
    }

    /**
     * Khai báo field password phải được repository mã hóa.
     *
     * Input: không có.
     * Output: danh sách field encrypted, không trả password.
     */
    public static function encrypted(): array
    {
        return ['password'];
    }
}
