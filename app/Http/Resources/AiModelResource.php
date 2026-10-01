<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Public catalog model không chứa secret/provider key.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT: AiModel đã hydrate.
 * OUTPUT: catalog model metadata/capability, không chứa API key.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
final class AiModelResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize model option ổn định cho frontend
     * =====================================================================
     * INPUT: AiModel.
     * OUTPUT: metadata capability/status; không chứa API key.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->ai_provider_id,
            'remote_model_id' => $this->remote_model_id,
            'label' => $this->label ?: $this->remote_model_id,
            'capabilities' => array_values($this->capabilities ?: []),
            'capability_source' => $this->capability_source,
            'discovery_source' => $this->discovery_source,
            'is_enabled' => (bool) $this->is_enabled,
            'is_available' => (bool) $this->is_available,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
        ];
    }
}
