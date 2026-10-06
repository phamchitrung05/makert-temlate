<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Cấu hình typed nhóm seo; null dùng config môi trường.
 * Input: repository settings. Output: giá trị có kiểu; group/encrypted không ghi DB.
 */
final class SeoSettings extends Settings
{
    public ?string $title_format;

    public ?string $default_description;

    public ?string $robots_txt;

    public int $version;

    /** Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'seo';
    }
}
