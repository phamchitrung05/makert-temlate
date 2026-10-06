<?php

namespace App\Services\Ai\Settings;

use App\Enums\AiCapability;
use App\Models\AiModel;
use App\Models\AiWritingProfile;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đọc/lưu typed settings AI bằng Spatie, giữ validation và audit.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: defaults(), all(), update().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): allowlisted values/actor -> settings typed.
 * INPUT: allowlisted typed values/actor ID.
 * OUTPUT: settings hiện tại.
 * SIDE EFFECT: update() atomic DB write và audit; không cache singleton để queue worker thấy setting mới.
 * EXCEPTION/TRANSACTION: ValidationException; update() mở transaction atomic.
 * =====================================================================
 */
final class AiSettingsService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cung cấp giá trị bootstrap khi chưa có settings
     * =====================================================================
     * INPUT: Không có đối số.
     * OUTPUT: Default model IDs null, temperature và timeout ban đầu.
     * SIDE EFFECT: Hàm thuần; không query DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function defaults(): array
    {
        return [
            'default_text_model_id' => null, 'default_image_model_id' => null,
            'default_writing_profile_id' => null,
            'fallback_text_model_id' => null, 'fallback_image_model_id' => null,
            'default_temperature' => 0.2, 'request_timeout' => 30,
            'min_word_count' => 0, 'default_system_prompt' => '',
            'auto_thumbnail' => true, 'auto_seo' => true,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc typed settings và ghép với giá trị bootstrap
     * =====================================================================
     * INPUT: Không có đối số.
     * OUTPUT: Settings hiện hành; dùng defaults nếu schema chưa sẵn sàng.
     * SIDE EFFECT: Đọc schema/settings; không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction; lỗi kiểm tra schema được fallback an toàn.
     * =====================================================================
     */
    public function all(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return $this->defaults();
            }
        } catch (\Throwable) {
            return $this->defaults();
        }

        // Spatie bind singleton; refresh bắt buộc để worker không giữ giá trị cũ.
        $values = app(AiSettings::class)->refresh()->toArray();

        return $values + ['settings_version' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu defaults/fallback/tuning với validation capability
     * =====================================================================
     * INPUT: Values đã validate và actor ID.
     * OUTPUT: Settings typed sau khi lưu.
     * SIDE EFFECT: Query catalog, ghi settings whitelist và activity log.
     * EXCEPTION/TRANSACTION: ValidationException khi model không dùng được; writes/audit trong DB transaction.
     * =====================================================================
     */
    public function update(array $values, int $actorId): array
    {
        $expectedVersion = $values['settings_version'] ?? null;
        $values = array_intersect_key($values, $this->defaults());
        foreach (['default_text_model_id', 'fallback_text_model_id', 'default_image_model_id', 'fallback_image_model_id'] as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $model = AiModel::query()->with('provider')->find($values[$key]);
                $capability = str_contains($key, '_image_') ? AiCapability::Image : AiCapability::Text;
                if (! $model || ! $model->usableFor($capability)) {
                    throw ValidationException::withMessages([$key => 'Model phải đang bật, available và hỗ trợ đúng capability.']);
                }
                $values[$key] = (int) $values[$key];
            }
        }
        if (array_key_exists('default_writing_profile_id', $values) && $values['default_writing_profile_id'] !== null) {
            $profile = AiWritingProfile::query()->where('is_enabled', true)->find($values['default_writing_profile_id']);
            if (! $profile) {
                throw ValidationException::withMessages(['default_writing_profile_id' => 'Mẫu văn phong mặc định phải tồn tại và đang bật.']);
            }
            $values['default_writing_profile_id'] = (int) $profile->id;
        }
        /**
         * =====================================================================
         * GHI CHÚ: Ép kiểu tuning và giữ default khi partial request gửi null.
         * =====================================================================
         * Worker queue đọc được giá trị typed ổn định sau khi settings cập nhật.
         * =====================================================================
         */
        foreach (['default_temperature', 'request_timeout', 'min_word_count'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $key === 'default_temperature'
                    ? (float) ($values[$key] ?? $this->defaults()[$key])
                    : (int) ($values[$key] ?? $this->defaults()[$key]);
            }
        }
        foreach (['auto_thumbnail', 'auto_seo'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = (bool) $values[$key];
            }
        }
        if (array_key_exists('default_system_prompt', $values)) {
            $values['default_system_prompt'] = (string) ($values['default_system_prompt'] ?? '');
        }
        DB::transaction(function () use ($values, $actorId, $expectedVersion): void {
            DB::table('settings')->where('group', AiSettings::group())->lockForUpdate()->get();
            abort_if($expectedVersion !== null && $expectedVersion !== $this->all()['settings_version'], 409,
                'Cấu hình AI đã thay đổi ở cửa sổ khác. Tải lại trước khi lưu.');
            // =====================================================================
            // Khóa Settings trước profile theo cùng thứ tự CRUD để tránh default
            // trỏ tới mẫu vừa bị tắt/xóa ở request đồng thời.
            // =====================================================================
            if (isset($values['default_writing_profile_id'])
                && ! AiWritingProfile::query()->where('is_enabled', true)->lockForUpdate()->find($values['default_writing_profile_id'])) {
                throw ValidationException::withMessages(['default_writing_profile_id' => 'Mẫu văn phong mặc định phải tồn tại và đang bật.']);
            }
            app(AiSettings::class)->refresh()->fill($values)->save();
            $logger = activity('ai-settings')->withProperties(['keys' => array_keys($values)]);
            if ($actor = User::query()->find($actorId)) {
                $logger->causedBy($actor);
            }
            $logger->log('settings.updated');
        });

        return $this->all();
    }
}
