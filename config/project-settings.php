<?php

/**
 * Registry locale đã có bản dịch Vue; Settings không tự tạo locale mới.
 * Input: file cấu hình. Output: allowlist hiển thị và validate.
 */
return [
    'locales' => [
        'en' => ['label' => 'English', 'rtl' => false],
        'fr' => ['label' => 'Français', 'rtl' => false],
        'ar' => ['label' => 'العربية', 'rtl' => true],
    ],
    'site_description' => '',
    'contact_email' => '',
    'title_format' => '%title%',
    'robots_txt' => "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/",
    'login_max_attempts' => 10,
    'login_decay_minutes' => 1,
];
