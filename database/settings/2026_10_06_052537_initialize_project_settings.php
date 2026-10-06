<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Khởi tạo nhóm mới bằng null để dùng config; không thay dữ liệu đã lưu. */
return new class extends SettingsMigration
{
    /** Output: thuộc tính còn thiếu; ghi repository idempotent. */
    public function up(): void
    {
        $groups = [
            'site' => ['site_name', 'site_url', 'site_description', 'contact_email', 'timezone'],
            'media' => ['image_max_size_kb', 'document_max_size_kb', 'video_max_size_kb', 'archive_max_size_kb', 'allowed_extensions', 'conversion_format', 'conversion_quality'],
            'seo' => ['title_format', 'default_description', 'robots_txt'],
            'mail' => ['mailer', 'host', 'port', 'scheme', 'username', 'password', 'from_name', 'from_address'],
            'security' => ['token_expiration_days', 'login_max_attempts', 'login_decay_minutes'],
            'languages' => ['default_locale', 'enabled_locales'],
        ];
        foreach ($groups as $group => $fields) {
            foreach ([...$fields, 'version'] as $field) {
                if (! $this->migrator->exists($group.'.'.$field)) {
                    $value = $field === 'version' ? 0 : null;
                    if ($group === 'mail' && $field === 'password') {
                        $this->migrator->addEncrypted($group.'.'.$field, $value);
                    } else {
                        $this->migrator->add($group.'.'.$field, $value);
                    }
                }
            }
        }
    }

    /** Giữ cấu hình đã lưu; đổi schema bằng forward migration. */
    public function down(): void {}
};
