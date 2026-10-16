<?php

namespace App\Http\Resources\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize product/fact context cho Marketing UI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Product/request -> bounded JSON.
 * =====================================================================
 */
final class ProductResource extends JsonResource
{
    /**
     * Serialize product, verified facts và reference asset IDs.
     *
     * Input: Request/Product đã eager load.
     * Output: array JSON; không trả token/provider.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id, 'name' => $this->name,
            'short_description' => $this->short_description, 'default_cta' => $this->default_cta,
            'prohibited_claims' => $this->prohibited_claims_json ?? [], 'status' => $this->status?->value,
            'facts' => $this->whenLoaded('facts', fn () => $this->facts->map(fn ($fact): array => [
                'id' => (int) $fact->id, 'key' => $fact->fact_key, 'value' => $fact->fact_value,
                'source' => $fact->source, 'verified' => (bool) $fact->verified,
                'is_allowed_for_ai' => (bool) $fact->is_allowed_for_ai,
            ])->values()),
            'reference_asset_ids' => [],
        ];
    }
}
