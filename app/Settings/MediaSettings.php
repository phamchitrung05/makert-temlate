<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo typed settings cho upload và conversion media.
 * =====================================================================
 * Settings mô tả giới hạn dung lượng, extension và chất lượng conversion;
 * service settings chịu trách nhiệm fallback và persist.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - group(): trả tên group media.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : media settings từ repository.
 * - OUTPUT: object settings typed; không tự ghi database.
 * =====================================================================
 */
final class MediaSettings extends Settings
{
    public ?int $image_max_size_kb;

    public ?int $document_max_size_kb;

    public ?int $video_max_size_kb;

    public ?int $archive_max_size_kb;

    public ?array $allowed_extensions;

    public ?string $conversion_format;

    public ?int $conversion_quality;

    public int $version;

    /**
     * Trả tên group settings được repository sử dụng.
     *
     * Input: không có.
     * Output: string `media`.
     */
    public static function group(): string
    {
        return 'media';
    }
}
