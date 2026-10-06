<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Cấu hình typed nhóm site; null dùng config môi trường.
 * Input: repository settings. Output: giá trị có kiểu; group/encrypted không ghi DB.
 */
final class SiteSettings extends Settings
{
    public ?string $site_name;

    public ?string $site_url;

    public ?string $site_description;

    public ?string $contact_email;

    public ?string $timezone;

    public int $version;

    /** Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'site';
    }
}
