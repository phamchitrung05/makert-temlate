<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize connection an toàn, chỉ báo has_api_key.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT: AiProvider và quan hệ model đã eager-load.
 * OUTPUT: provider metadata + has_api_key; không có plaintext/ciphertext secret.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
final class AiProviderResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize provider metadata/model options không trả ciphertext
     * =====================================================================
     * INPUT: AiProvider.
     * OUTPUT: DTO public có has_api_key thay vì API key.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'kind' => $this->kind,
            'driver' => $this->driver,
            'base_url' => $this->base_url,
            'discovery_mode' => $this->discovery_mode,
            'is_active' => (bool) $this->is_active,
            'has_api_key' => filled($this->api_key),
            'test_status' => $this->test_status,
            'test_message' => $this->test_message,
            'last_tested_at' => $this->last_tested_at?->toISOString(),
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'models' => AiModelResource::collection($this->whenLoaded('models')),
        ];
    }
}
