<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preset giao thức/endpoint và metadata model đã biết.
 * =====================================================================
 * INPUT: env egress/sync policy.
 * OUTPUT: preset public và giới hạn server-side.
 * SIDE EFFECT: Chỉ đọc config; không gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * GHI CHÚ: Catalog chỉ là gợi ý capability; quyền gọi model được provider kiểm tra thực tế.
 * =====================================================================
 */
return [
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_PROVIDER_ALLOWED_HOSTS', ''))))),
    'sync_enabled' => filter_var(env('AI_PROVIDER_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'max_models' => 10000,
    'max_response_bytes' => 32 * 1024 * 1024,
    'presets' => [
        'openai' => [
            'label' => 'OpenAI', 'kind' => 'official', 'base_url' => 'https://api.openai.com/v1',
            'discovery_mode' => 'models_endpoint', 'image_supported' => true,
            'models' => [
                'gpt-4o-mini' => ['text_generation', 'structured_output', 'vision'],
                'gpt-image-1' => ['image_generation'],
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini', 'kind' => 'official',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'discovery_mode' => 'models_endpoint', 'image_supported' => true,
            'models' => [
                'gemini-3.6-flash' => ['text_generation', 'structured_output', 'vision'],
                'gemini-2.5-flash-image' => ['image_generation', 'vision'],
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek', 'kind' => 'official', 'base_url' => 'https://api.deepseek.com/v1',
            'discovery_mode' => 'models_endpoint', 'image_supported' => false,
            'models' => [
                'deepseek-chat' => ['text_generation', 'structured_output'],
                'deepseek-reasoner' => ['text_generation', 'structured_output'],
            ],
        ],
        'openai-compatible' => [
            'label' => 'Gateway / OpenAI Compatible', 'kind' => 'gateway', 'base_url' => null,
            'discovery_mode' => 'models_endpoint', 'image_supported' => true, 'models' => [],
        ],
    ],
];
