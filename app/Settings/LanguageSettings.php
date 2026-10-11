<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo typed settings cho ngôn ngữ hệ thống.
 * =====================================================================
 * Spatie Laravel Settings hydrate các thuộc tính từ repository; null cho
 * phép service dùng fallback config môi trường.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - group(): trả tên group languages.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị settings đã lưu từ repository.
 * - OUTPUT: object settings typed; không tự ghi database.
 * =====================================================================
 */
final class LanguageSettings extends Settings
{
    public ?string $default_locale;

    public ?array $enabled_locales;

    public int $version;

    /**
     * Trả tên group settings được repository sử dụng.
     *
     * Input: không có.
     * Output: string `languages`.
     */
    public static function group(): string
    {
        return 'languages';
    }
}
