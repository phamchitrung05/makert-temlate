<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giới hạn preview, download, share và retention template
 * CÁC HÀM/METHOD TRONG FILE: không có; trả cấu hình môi trường.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): env -> policy runtime, không I/O.
 * =====================================================================
 */
return [
    'preview_origin' => env('TEMPLATE_PREVIEW_ORIGIN'),
    'artifact_disk' => env('TEMPLATE_ARTIFACT_DISK', 'media_private'),
    'grant_minutes' => max(1, (int) env('TEMPLATE_PREVIEW_GRANT_MINUTES', 10)),
    'download_minutes' => max(1, (int) env('TEMPLATE_DOWNLOAD_MINUTES', 5)),
    'download_auth' => env('TEMPLATE_DOWNLOAD_AUTH', 'verified_customer'),
    'daily_download_limit' => max(1, (int) env('TEMPLATE_DAILY_DOWNLOAD_LIMIT', 20)),
    'share_max_days' => max(1, (int) env('TEMPLATE_SHARE_MAX_DAYS', 30)),
    'orphan_hours' => max(1, (int) env('TEMPLATE_ORPHAN_HOURS', 24)),
    'history_days' => max(1, (int) env('TEMPLATE_DOWNLOAD_HISTORY_DAYS', 90)),
    'mime_types' => [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ],
    // Host ứng dụng quyết định CDN; ZIP không thể cấp quyền cho CSP.
    'script_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEMPLATE_SCRIPT_HOSTS', 'https://cdn.tailwindcss.com'))))),
    'style_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEMPLATE_STYLE_HOSTS', 'https://fonts.googleapis.com'))))),
    'font_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEMPLATE_FONT_HOSTS', 'https://fonts.gstatic.com'))))),
    'image_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEMPLATE_IMAGE_HOSTS', 'https://lh3.googleusercontent.com'))))),
];
