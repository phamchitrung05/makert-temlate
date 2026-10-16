<?php

namespace App\Http\Resources\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize campaign/product selection.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Campaign/request -> bounded JSON.
 * =====================================================================
 */
final class CampaignResource extends JsonResource
{
    /**
     * Serialize campaign và products đã eager load.
     *
     * Input: Request/Campaign.
     * Output: array JSON; không ghi DB.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id, 'name' => $this->name, 'objective' => $this->objective,
            'message' => $this->message, 'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(), 'timezone' => $this->timezone,
            'status' => $this->status?->value,
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($product): array => ['id' => (int) $product->id, 'name' => $product->name])->values()),
        ];
    }
}
