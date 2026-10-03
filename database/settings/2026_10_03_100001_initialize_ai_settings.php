<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Seed các property AI còn thiếu bằng settings migration.
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : repository settings sau chuyển dữ liệu.
 * - OUTPUT: đủ property cho AiSettings; không ghi đè giá trị đã có.
 * =====================================================================
 */
return new class extends SettingsMigration
{
    /** Input: Không có. Output: property mặc định chỉ được thêm khi chưa tồn tại. */
    public function up(): void
    {
        foreach ([
            'default_text_model_id' => null, 'default_image_model_id' => null,
            'fallback_text_model_id' => null, 'fallback_image_model_id' => null,
            'default_temperature' => 0.2, 'request_timeout' => 30,
        ] as $name => $value) {
            if (! $this->migrator->exists('ai.'.$name)) {
                $this->migrator->add('ai.'.$name, $value);
            }
        }
    }

    /** Input: rollback. Output: giữ payload cho migration schema khôi phục; không xóa giá trị đã có. */
    public function down(): void {}
};
