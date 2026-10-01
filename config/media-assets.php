<?php

use App\Enums\MediaAssetKind;

return [

    /*
     * Profile ảnh canonical dùng cho Post featured image và Open Graph.
     * `thumb`/`web` cũ vẫn được giữ trong MediaAsset để asset hiện tại không
     * mất URL; profile mới là nguồn chuẩn cho các consumer mới.
     */
    'image_conversions' => [
        'featured' => [
            'width' => (int) env('MEDIA_FEATURED_WIDTH', 1200),
            'height' => (int) env('MEDIA_FEATURED_HEIGHT', 675),
            'format' => env('MEDIA_FEATURED_FORMAT', 'webp'),
            'quality' => (int) env('MEDIA_FEATURED_QUALITY', 85),
        ],
        'og' => [
            'width' => (int) env('MEDIA_OG_WIDTH', 1200),
            'height' => (int) env('MEDIA_OG_HEIGHT', 630),
            'format' => env('MEDIA_OG_FORMAT', 'webp'),
            'quality' => (int) env('MEDIA_OG_QUALITY', 85),
        ],
    ],

    /*
     * Temporary upload luôn nằm trên private disk trước khi được attach vào
     * collection library. API Task 5 sẽ chỉ gọi action sau FormRequest.
     */
    'temporary_disk' => env('MEDIA_TEMPORARY_DISK', 'media_private'),
    'temporary_directory' => env('MEDIA_TEMPORARY_DIRECTORY', 'media-assets/tmp'),

    'kinds' => [
        MediaAssetKind::Image->value => [
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'mime_types' => [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
            ],
            'max_size_kb' => (int) env('MEDIA_IMAGE_MAX_SIZE_KB', 10240),
            'default_visibility' => 'public',
        ],
        MediaAssetKind::Document->value => [
            'extensions' => ['pdf', 'txt', 'md'],
            'mime_types' => [
                'application/pdf',
                'text/plain',
                'text/markdown',
            ],
            'max_size_kb' => (int) env('MEDIA_DOCUMENT_MAX_SIZE_KB', 25600),
            'default_visibility' => 'private',
        ],
        MediaAssetKind::Archive->value => [
            'extensions' => ['zip'],
            'mime_types' => [
                'application/zip',
                'application/x-zip-compressed',
            ],
            'max_size_kb' => (int) env('MEDIA_ARCHIVE_MAX_SIZE_KB', 512000),
            'default_visibility' => 'private',
        ],
        MediaAssetKind::Video->value => [
            'extensions' => ['mp4', 'webm'],
            'mime_types' => [
                'video/mp4',
                'video/webm',
            ],
            'max_size_kb' => (int) env('MEDIA_VIDEO_MAX_SIZE_KB', 512000),
            'default_visibility' => 'private',
        ],
    ],

    /* Defense-in-depth; every kind still uses the allowlist above first. */
    'blocked_extensions' => [
        'php', 'phtml', 'phar', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi',
        'sh', 'bash', 'ps1', 'vbs', 'js', 'jar',
    ],

    'archive' => [
        'max_entries' => (int) env('MEDIA_ARCHIVE_MAX_ENTRIES', 10000),
        'max_uncompressed_bytes' => (int) env(
            'MEDIA_ARCHIVE_MAX_UNCOMPRESSED_BYTES',
            1073741824,
        ),
        'max_compression_ratio' => (float) env('MEDIA_ARCHIVE_MAX_COMPRESSION_RATIO', 100),
        'allow_encrypted' => false,
        'allow_symlinks' => false,
        'blocked_entry_extensions' => [
            'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'so', 'dylib',
        ],
    ],
];
