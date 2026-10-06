<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm đường dẫn logo/favicon vào nhóm site đã có.
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : repository Settings hiện tại.
 * - OUTPUT: hai thuộc tính nullable; không đổi thông tin website đã lưu.
 * =====================================================================
 */
return new class extends SettingsMigration
{
    /** Input: không có. Output: thêm property còn thiếu; mặc định dùng branding của giao diện. */
    public function up(): void
    {
        foreach (['logo_path', 'favicon_path'] as $name) {
            if (! $this->migrator->exists('site.'.$name)) {
                $this->migrator->add('site.'.$name, null);
            }
        }
    }

    /** Input: không có. Output: bỏ hai property schema, giữ file vật lý để rollback không mất ảnh. */
    public function down(): void
    {
        foreach (['logo_path', 'favicon_path'] as $name) {
            if ($this->migrator->exists('site.'.$name)) {
                $this->migrator->delete('site.'.$name);
            }
        }
    }
};
