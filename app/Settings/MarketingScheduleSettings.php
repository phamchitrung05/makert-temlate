<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Typed settings cho lịch đăng bài tự động theo chiến dịch.
 * =====================================================================
 * Giữ cấu hình hệ thống dùng chung của trang /marketing/post-schedule: model
 * AI mặc định, ngôn ngữ/múi giờ, cơ chế batch sáng Chủ Nhật và trần nền tảng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - group(): trả tên group marketing_schedule.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : repository settings đã persist.
 * - OUTPUT: giá trị typed; null thì dùng default của ProjectSettingsService.
 * =====================================================================
 */
final class MarketingScheduleSettings extends Settings
{
    public ?int $default_text_model_id;

    public ?int $default_image_model_id;

    public ?string $default_language;

    public ?string $default_timezone;

    public bool $sunday_batch_enabled;

    public ?string $sunday_batch_time;

    public bool $auto_submit_review;

    public bool $auto_schedule_publish;

    public ?array $platform_quotas_json;

    public ?array $global_pairing_rules_json;

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả tên group typed Settings.
     * Input: không có. Output: marketing_schedule; không ghi dữ liệu.
     * =====================================================================
     */
    public static function group(): string
    {
        return 'marketing_schedule';
    }
}
