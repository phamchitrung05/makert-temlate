<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình repository và class của Spatie Laravel Settings.
 * CÁC HÀM/METHOD TRONG FILE: Không có; trả mảng cấu hình.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : đường dẫn application/database.
 * - OUTPUT: settings lưu theo group/name/payload/locked, cache tắt để worker đọc mới.
 * =====================================================================
 */
return [
    'settings' => [App\Settings\AiSettings::class],
    'setting_class_path' => app_path('Settings'),
    'migrations_paths' => [database_path('settings')],
    'default_repository' => 'database',
    'repositories' => [
        'database' => [
            'type' => Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository::class,
            'model' => null,
            'table' => 'settings',
            'connection' => null,
        ],
    ],
    'encoder' => null,
    'decoder' => null,
    'cache' => ['enabled' => false, 'store' => null, 'prefix' => null, 'ttl' => null, 'memo' => false],
    'global_casts' => [
        DateTimeInterface::class => Spatie\LaravelSettings\SettingsCasts\DateTimeInterfaceCast::class,
        DateTimeZone::class => Spatie\LaravelSettings\SettingsCasts\DateTimeZoneCast::class,
    ],
    'auto_discover_settings' => [],
    'discovered_settings_cache_path' => base_path('bootstrap/cache'),
];
