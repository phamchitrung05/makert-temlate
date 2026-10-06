<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Cấu hình typed nhóm media; null dùng config môi trường.
 * Input: repository settings. Output: giá trị có kiểu; group/encrypted không ghi DB.
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

    /** Output: tên nhóm lưu trong settings. */
    public static function group(): string
    {
        return 'media';
    }
}
