<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo typed settings AI do Spatie lưu và đọc.
 * CÁC HÀM/METHOD TRONG FILE: group().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload nhóm ai trong repository settings.
 * - OUTPUT: model mặc định/fallback và tuning có kiểu dữ liệu rõ ràng.
 * =====================================================================
 */
class AiSettings extends Settings
{
    public ?int $default_text_model_id;

    public ?int $default_image_model_id;

    public ?int $fallback_text_model_id;

    public ?int $fallback_image_model_id;

    public float $default_temperature;

    public int $request_timeout;

    public int $min_word_count;

    public string $default_system_prompt;

    public bool $auto_thumbnail;

    public bool $auto_seo;

    /** Input: Không có. Output: tên nhóm settings ổn định; không ghi dữ liệu. */
    public static function group(): string
    {
        return 'ai';
    }
}
