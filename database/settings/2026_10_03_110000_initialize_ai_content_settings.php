<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Add content defaults without replacing existing AI settings or changing old run behavior. */
return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach ([
            'min_word_count' => 0,
            'default_system_prompt' => '',
            'auto_thumbnail' => true,
            'auto_seo' => true,
        ] as $name => $value) {
            if (! $this->migrator->exists('ai.'.$name)) {
                $this->migrator->add('ai.'.$name, $value);
            }
        }
    }

    /** Preserve saved content defaults for a later forward migration. */
    public function down(): void {}
};
