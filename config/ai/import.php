<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình runtime cho URL import và queue AI.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: Không có function; file chỉ trả mảng config.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : biến môi trường `AI_IMPORT_*`, không chứa secret mặc định.
 * - OUTPUT: timeout đọc nguồn/job, giới hạn payload, quota và retention.
 * - Provider, model và kết nối từ .env được khai báo trong config/ai/providers.php.
 * - SIDE EFFECT: không gọi network; application code đọc qua `config()`.
 * =====================================================================
 */
return [
    'timeout' => (int) env('AI_IMPORT_TIMEOUT', 12),
    'connect_timeout' => (int) env('AI_IMPORT_CONNECT_TIMEOUT', 5),
    'job_timeout' => (int) env('AI_IMPORT_JOB_TIMEOUT', 180),
    'max_redirects' => (int) env('AI_IMPORT_MAX_REDIRECTS', 3),
    'max_html_bytes' => (int) env('AI_IMPORT_MAX_HTML_BYTES', 5242880),
    'max_image_bytes' => (int) env('AI_IMPORT_MAX_IMAGE_BYTES', 10485760),
    'prompt_version' => env('AI_IMPORT_PROMPT_VERSION', 'v1'),
    'user_agent' => env('AI_IMPORT_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36'),
    'retention_days' => (int) env('AI_IMPORT_RETENTION_DAYS', 2),
    'quota_per_hour' => (int) env('AI_IMPORT_QUOTA_PER_HOUR', 20),
    'idempotency_window_minutes' => (int) env('AI_IMPORT_IDEMPOTENCY_WINDOW_MINUTES', 30),
    'enabled' => filter_var(env('AI_IMPORT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
];
