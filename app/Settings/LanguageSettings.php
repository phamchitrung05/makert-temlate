<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Cấu hình typed nhóm languages; null dùng config môi trường.
 * Input: repository settings. Output: giá trị có kiểu; group/encrypted không ghi DB.
 */
final class LanguageSettings extends Settings
{
    public ?string $default_locale;

    public ?array $enabled_locales;

    public int $version;

    /** Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'languages';
    }
}
