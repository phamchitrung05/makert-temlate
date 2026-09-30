<?php

return [
    'provider' => env('AI_IMPORT_PROVIDER', 'deterministic'),
    'endpoint' => env('AI_IMPORT_ENDPOINT'),
    'key' => env('AI_IMPORT_KEY'),
    'model' => env('AI_IMPORT_MODEL', 'default'),
    'timeout' => (int) env('AI_IMPORT_TIMEOUT', 12),
    'enabled' => (bool) env('AI_IMPORT_ENABLED', true),
];
