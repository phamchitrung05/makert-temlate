<?php

namespace App\Services\Ai;

use App\Enums\AiCapability;
use App\Models\AiModel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Read/write setting AI qua bảng key-value chung, không chứa secret.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: defaults(), all(), update().
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
            'fallback_text_model_id' => null, 'fallback_image_model_id' => null,
            'default_temperature' => 0.2, 'request_timeout' => 30,
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

        $values = Setting::query()->where('group', 'ai')
            ->whereIn('key', array_keys($this->defaults()))->get(['key', 'type', 'value'])
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => match ($setting->type) {
                'integer' => $setting->value === null ? null : (int) $setting->value,
                'float' => (float) $setting->value,
                'boolean' => (bool) $setting->value,
                default => $setting->value,
            }])->all();

        return array_replace($this->defaults(), $values);
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
        /**
         * =====================================================================
         * GHI CHÚ: Ép kiểu tuning và giữ default khi partial request gửi null.
         * =====================================================================
         * Worker queue đọc được giá trị typed ổn định sau khi settings cập nhật.
         * =====================================================================
         */
        foreach (['default_temperature', 'request_timeout'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $key === 'default_temperature'
                    ? (float) ($values[$key] ?? $this->defaults()[$key])
                    : (int) ($values[$key] ?? $this->defaults()[$key]);
            }
        }
        DB::transaction(function () use ($values, $actorId): void {
            foreach (array_intersect_key($values, $this->defaults()) as $key => $value) {
                Setting::query()->updateOrCreate(['group' => 'ai', 'key' => $key], [
                    'value' => $value, 'type' => $key === 'default_temperature' ? 'float' : 'integer',
                    'updated_by' => $actorId,
                ]);
            }
            $logger = activity('ai-settings')->withProperties(['keys' => array_keys($values)]);
            if ($actor = User::query()->find($actorId)) {
                $logger->causedBy($actor);
            }
            $logger->log('settings.updated');
        });

        return $this->all();
    }
}
