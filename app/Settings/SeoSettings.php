<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo typed settings cho SEO mặc định.
 * =====================================================================
 * Settings lưu format title, description và robots.txt; service settings
 * xử lý fallback và persist theo version.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - group(): trả tên group seo.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SEO settings từ repository.
 * - OUTPUT: object settings typed; không tự ghi database.
 * =====================================================================
 */
final class SeoSettings extends Settings
{
    public ?string $title_format;

    public ?string $default_description;

    public ?string $robots_txt;

    public int $version;

    /**
     * Trả tên group settings được repository sử dụng.
     *
     * Input: không có.
     * Output: string `seo`.
     */
    public static function group(): string
    {
        return 'seo';
    }
}
