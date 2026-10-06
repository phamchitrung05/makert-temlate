<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Settings\LanguageSettings;
use App\Settings\MailSettings;
use App\Settings\MediaSettings;
use App\Settings\SecuritySettings;
use App\Settings\SeoSettings;
use App\Settings\SiteSettings;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Boundary đọc/lưu Settings ngoài AI. Null ưu tiên config, giá trị DB ưu tiên khi có.
 * Methods: defaults/effective/section/update, mediaPolicy/imageProfile/locales.
 * Input: group/payload/actor. Output: DTO đã che secret hoặc policy runtime.
 * Side effect: update khóa DB và audit; đọc refresh để worker nhận giá trị mới.
 */
final class ProjectSettingsService
{
    public const CLASSES = [
        'site' => SiteSettings::class,
        'media' => MediaSettings::class,
        'seo' => SeoSettings::class,
        'mail' => MailSettings::class,
        'security' => SecuritySettings::class,
        'languages' => LanguageSettings::class,
    ];

    /** Output: locale đã có file dịch; không query DB hoặc tạo bản dịch. */
    public function locales(): array
    {
        $options = [];
        foreach (config('project-settings.locales', []) as $code => $locale) {
            if (is_file(resource_path('js/plugins/i18n/locales/'.$code.'.json'))) {
                $options[] = ['value' => $code, 'title' => $locale['label'], 'rtl' => $locale['rtl']];
            }
        }

        return $options;
    }

    /** Input: group. Output: defaults thật từ config/env, không tiết lộ cho HTTP trực tiếp. */
    public function defaults(string $group): array
    {
        $smtp = (new ConfigurationUrlParser)->parseConfiguration(config('mail.mailers.smtp'));

        return match ($group) {
            'site' => [
                'site_name' => config('app.name'), 'site_url' => config('app.url'),
                'site_description' => config('project-settings.site_description'),
                'contact_email' => config('project-settings.contact_email'),
                'timezone' => config('app.timezone', 'UTC'),
            ],
            'media' => [
                'image_max_size_kb' => config('media-assets.kinds.image.max_size_kb'),
                'document_max_size_kb' => config('media-assets.kinds.document.max_size_kb'),
                'video_max_size_kb' => config('media-assets.kinds.video.max_size_kb'),
                'archive_max_size_kb' => config('media-assets.kinds.archive.max_size_kb'),
                'allowed_extensions' => array_values(array_unique(array_merge(...array_column(config('media-assets.kinds'), 'extensions')))),
                'conversion_format' => config('media-assets.image_conversions.featured.format', 'webp'),
                'conversion_quality' => config('media-assets.image_conversions.featured.quality', 85),
            ],
            'seo' => [
                'title_format' => config('project-settings.title_format'),
                'default_description' => config('project-settings.site_description'),
                'robots_txt' => config('project-settings.robots_txt'),
            ],
            'mail' => [
                'mailer' => config('mail.default'), 'host' => $smtp['host'],
                'port' => $smtp['port'], 'scheme' => $smtp['scheme'] ?? (($smtp['driver'] ?? null) === 'smtps' ? 'smtps' : 'smtp'),
                'username' => $smtp['username'] ?? '',
                'password' => $smtp['password'] ?? null,
                'from_name' => config('mail.from.name'), 'from_address' => config('mail.from.address'),
            ],
            'security' => [
                'token_expiration_days' => config('sanctum.token_expiration_days', 30),
                'login_max_attempts' => config('project-settings.login_max_attempts'),
                'login_decay_minutes' => config('project-settings.login_decay_minutes'),
            ],
            'languages' => [
                'default_locale' => config('app.locale', 'en'),
                'enabled_locales' => array_column($this->locales(), 'value'),
            ],
        };
    }

    /** Input: group. Output: typed payload; chỉ fallback khi schema chưa migrate. */
    private function stored(string $group): array
    {
        if (! Schema::hasTable('settings')) {
            return ['version' => 0];
        }
        try {
            return app(self::CLASSES[$group])->refresh()->toArray();
        } catch (MissingSettings) {
            return ['version' => 0];
        }
    }

    /** Input: group. Output: cấu hình hiệu lực; mail password chỉ dùng nội bộ. */
    public function effective(string $group): array
    {
        return array_replace($this->defaults($group), array_filter($this->stored($group), fn ($value) => $value !== null));
    }

    /** Input: group. Output: envelope frontend với cờ secret, version và thời gian thật. */
    public function section(string $group): array
    {
        $values = $this->effective($group);
        $version = $values['version'] ?? 0;
        unset($values['version']);
        if ($group === 'mail') {
            $values['password_configured'] = filled($values['password']);
            unset($values['password']);
        }

        return [
            'values' => $values, 'version' => $version,
            'updated_at' => Schema::hasTable('settings')
                ? DB::table('settings')->where('group', $group)->max('updated_at') : null,
        ];
    }

    /** Input: validated partial payload + version. Output: saved DTO; transaction khóa và audit keys. */
    public function update(string $group, array $payload, User $actor): array
    {
        $version = (int) $payload['version'];
        $values = array_intersect_key($payload, $this->defaults($group));
        if ($group === 'mail' && array_key_exists('password', $values) && blank($values['password'])) {
            unset($values['password']); // Để trống giữ mật khẩu; không echo secret vào form.
        }
        foreach ($values as $key => $value) {
            if ($value === null && in_array($key, ['username', 'contact_email', 'site_description', 'default_description', 'robots_txt'], true)) {
                $values[$key] = '';
            }
            if (str_ends_with($key, '_kb') || in_array($key, ['port', 'conversion_quality', 'token_expiration_days', 'login_max_attempts', 'login_decay_minutes'], true)) {
                $values[$key] = (int) $value;
            }
        }
        DB::transaction(function () use ($group, $values, $version, $actor): void {
            $rows = DB::table('settings')->where('group', $group)->lockForUpdate()->get();
            abort_if($rows->isEmpty(), 503, 'Cần cập nhật cấu trúc dữ liệu Settings trước khi lưu.');
            $settings = app(self::CLASSES[$group])->refresh();
            abort_if($settings->version !== $version, 409, 'Cấu hình đã thay đổi ở cửa sổ khác. Tải lại trước khi lưu.');
            if ($values !== []) {
                $settings->fill($values + ['version' => $version + 1])->save();
                activity('settings')->causedBy($actor)->withProperties(['group' => $group, 'keys' => array_keys($values)])
                    ->log('settings.updated');
            }
        });

        return $this->section($group);
    }

    /** Input: media kind. Output: policy vẫn giữ MIME/extension security config và giới hạn DB. */
    public function mediaPolicy(string $kind): array
    {
        $policy = config('media-assets.kinds.'.$kind, []);
        $settings = $this->effective('media');
        $policy['max_size_kb'] = min((int) $settings[$kind.'_max_size_kb'], (int) config('media-library.max_file_size') / 1024);
        $policy['extensions'] = array_values(array_intersect($policy['extensions'], $settings['allowed_extensions']));

        return $policy;
    }

    /** Input: featured/og. Output: profile runtime cho asset mới/retry, giữ kích thước config. */
    public function imageProfile(string $name): array
    {
        $settings = $this->effective('media');

        return array_replace(config('media-assets.image_conversions.'.$name, []), [
            'format' => $settings['conversion_format'], 'quality' => $settings['conversion_quality'],
        ]);
    }
}
