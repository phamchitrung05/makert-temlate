<?php

use App\Services\Ai\DeterministicAiProvider;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\StructuredAiProvider;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo provider, adapter, preset và kết nối AI từ .env.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: không có; trả mảng config.
 * INPUT/OUTPUT CỦA FILE (tổng thể): env provider/timeout/egress -> kết nối và preset.
 * OUTPUT: metadata provider, adapter và thông số kết nối server-side.
 * SIDE EFFECT: Chỉ đọc config; không gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * GHI CHÚ: Settings lưu connection/model thực tế trong database, API key được mã hóa.
 * Connections ở đây giữ tương thích .env và tham chiếu metadata qua driver.
 * Không trả key/endpoint/adapter của connections ra API public.
 * =====================================================================
 */
return [
    'default_provider' => env('AI_IMPORT_PROVIDER', 'deterministic'),
    // Mặc định cho provider mới; giá trị đã lưu của từng provider được ưu tiên.
    'request_timeout' => max(5, min(600, (int) env('AI_PROVIDER_REQUEST_TIMEOUT', 200))),
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_PROVIDER_ALLOWED_HOSTS', ''))))),
    'sync_enabled' => filter_var(env('AI_PROVIDER_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'max_models' => 10000,
    'max_response_bytes' => 32 * 1024 * 1024,
    'presets' => [
        'openai' => [
            'label' => 'OpenAI', 'kind' => 'official', 'base_url' => 'https://api.openai.com/v1',
            'logo' => 'openai', 'adapter' => OpenAiProvider::class,
            'discovery_mode' => 'models_endpoint', 'image_supported' => true,
            'models' => [
                'gpt-4o-mini' => ['text_generation', 'structured_output', 'vision'],
                'gpt-image-1' => ['image_generation'],
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini', 'kind' => 'official',
            'logo' => 'gemini', 'adapter' => GeminiProvider::class,
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'discovery_mode' => 'models_endpoint', 'image_supported' => true,
            'models' => [
                'gemini-3.6-flash' => ['text_generation', 'structured_output', 'vision'],
                'gemini-2.5-flash-image' => ['image_generation', 'vision'],
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek', 'kind' => 'official', 'base_url' => 'https://api.deepseek.com/v1',
            'logo' => 'deepseek', 'adapter' => OpenAiProvider::class,
            'discovery_mode' => 'models_endpoint', 'image_supported' => false,
            'models' => [
                'deepseek-chat' => ['text_generation', 'structured_output'],
                'deepseek-reasoner' => ['text_generation', 'structured_output'],
            ],
        ],
        'openai-compatible' => [
            'label' => 'Gateway / OpenAI Compatible', 'kind' => 'gateway', 'base_url' => null,
            'logo' => 'openai-compatible', 'adapter' => OpenAiProvider::class,
            'discovery_mode' => 'models_endpoint', 'image_supported' => true, 'models' => [],
        ],
    ],

    // Bộ xử lý nội bộ không xuất hiện trong danh sách tạo connection ở Settings.
    'internal' => [
        'http-json' => [
            'label' => 'Configured AI endpoint', 'logo' => null,
            'adapter' => StructuredAiProvider::class,
        ],
        'deterministic' => [
            'label' => 'Deterministic extraction', 'logo' => null,
            'adapter' => DeterministicAiProvider::class,
        ],
    ],

    // Chỉ giữ giá trị kết nối; tên/logo/adapter lấy từ preset hoặc internal driver.
    'connections' => [
        'http-json' => [
            'driver' => 'http-json',
            'enabled' => filter_var(env('AI_IMPORT_ENABLED', true), FILTER_VALIDATE_BOOLEAN)
                && (string) env('AI_IMPORT_ENDPOINT', '') !== ''
                && (string) env('AI_IMPORT_KEY', '') !== '',
            'endpoint' => env('AI_IMPORT_ENDPOINT'),
            'key' => env('AI_IMPORT_KEY'),
            'model' => env('AI_IMPORT_MODEL', 'default'),
            'temperature' => 0.2,
            'timeout' => (int) env('AI_IMPORT_TIMEOUT', 12),
        ],
        'deterministic' => [
            'driver' => 'deterministic', 'enabled' => true, 'model' => 'deterministic',
        ],
        'openai' => [
            'driver' => 'openai',
            'enabled' => (string) env('AI_OPENAI_KEY', '') !== '',
            'endpoint' => env('AI_OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'key' => env('AI_OPENAI_KEY'),
            'model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
            'temperature' => (float) env('AI_OPENAI_TEMPERATURE', 0.2),
            'timeout' => (int) env('AI_IMPORT_TIMEOUT', 12),
        ],
        'gemini' => [
            'driver' => 'gemini',
            'enabled' => (string) env('AI_GEMINI_KEY', '') !== '',
            'endpoint' => env('AI_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
            'key' => env('AI_GEMINI_KEY'),
            'model' => env('AI_GEMINI_MODEL', 'gemini-3.6-flash'),
            'temperature' => (float) env('AI_GEMINI_TEMPERATURE', 0.2),
            'timeout' => (int) env('AI_IMPORT_TIMEOUT', 12),
        ],
    ],
];
