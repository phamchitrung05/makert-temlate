<?php

namespace App\Models;

use App\Enums\AiCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Model thuộc một connection, capability và trạng thái khả dụng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: casts(), provider(), supports(), usableFor().
 * INPUT: catalog/manual metadata.
 * OUTPUT: model domain; không suy đoán tạo ảnh từ tên model hoặc capability vision.
 * SIDE EFFECT: Eloquent persistence thuộc caller.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
class AiModel extends Model
{
    protected $fillable = [
        'ai_provider_id', 'remote_model_id', 'label', 'capabilities', 'capability_source',
        'discovery_source', 'metadata', 'is_enabled', 'is_available', 'last_seen_at',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: JSON/boolean/date casts dùng cho catalog.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: JSON/boolean/date casts dùng cho catalog.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array', 'metadata' => 'array',
            'is_enabled' => 'boolean', 'is_available' => 'boolean', 'last_seen_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: connection owner của model.
     * =====================================================================
     * INPUT: model đã hydrate.
     * OUTPUT: connection owner của model.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: true khi model khai báo hỗ trợ rõ ràng.
     * =====================================================================
     * INPUT: capability cần gọi.
     * OUTPUT: true khi model khai báo hỗ trợ rõ ràng.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function supports(AiCapability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra model có thể chạy một capability hay không
     * =====================================================================
     * INPUT: AiCapability cần thực thi và provider relation đã hydrate.
     * OUTPUT: boolean; text_generation đủ cho content; structured_output là tùy chọn
     *   transport, JSON trả về vẫn được pipeline kiểm tra schema.
     * SIDE EFFECT: chỉ đọc model/config; không gọi provider hoặc ghi database.
     * EXCEPTION/TRANSACTION: false khi relation thiếu hoặc model không hợp lệ;
     *   không mở transaction.
     * =====================================================================
     */
    public function usableFor(AiCapability $capability): bool
    {
        $provider = $this->relationLoaded('provider') ? $this->provider : null;

        if (! $provider || ! $this->is_enabled || ! $this->is_available
            || ! $provider->is_active || ! filled($provider->api_key)
            || ! $this->supports($capability)) {
            return false;
        }

        return $capability !== AiCapability::Image
            || (bool) config('ai.providers.presets.'.$provider->driver.'.image_supported', false);
    }
}
