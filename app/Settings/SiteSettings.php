<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình typed thông tin website và đường dẫn branding.
 * CÁC HÀM/METHOD TRONG FILE: group().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : repository Settings; null dùng cấu hình/tài nguyên mặc định.
 * - OUTPUT: giá trị có kiểu; file upload được settings writer quản lý.
 * =====================================================================
 */
final class SiteSettings extends Settings
{
    public ?string $site_name;

    public ?string $site_url;

    public ?string $site_description;

    public ?string $contact_email;

    public ?string $timezone;

    public ?string $logo_path;

    public ?string $favicon_path;

    public int $version;

    /** Input: không có. Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'site';
    }
}
