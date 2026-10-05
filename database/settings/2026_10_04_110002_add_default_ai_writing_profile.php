<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm lựa chọn mẫu văn phong mặc định vào typed AI Settings.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : repository Spatie Settings hiện tại.
 * - OUTPUT: ai.default_writing_profile_id nullable, giữ cấu hình đang có.
 * - SIDE EFFECT: thêm/xóa duy nhất property mới khi migrate/rollback.
 * =====================================================================
 */
return new class extends SettingsMigration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo lựa chọn mặc định nếu chưa tồn tại.
     * =====================================================================
     * Input: settings repository. Output: property nullable được thêm.
     * Side effect: ghi settings; không thay đổi profile.
     * =====================================================================
     */
    public function up(): void
    {
        if (! $this->migrator->exists('ai.default_writing_profile_id')) {
            $this->migrator->add('ai.default_writing_profile_id', null);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ property khi rollback migration.
     * =====================================================================
     * Input: settings repository. Output: property mới bị gỡ nếu có.
     * Side effect: không xóa bảng profile.
     * =====================================================================
     */
    public function down(): void
    {
        if ($this->migrator->exists('ai.default_writing_profile_id')) {
            $this->migrator->delete('ai.default_writing_profile_id');
        }
    }
};
