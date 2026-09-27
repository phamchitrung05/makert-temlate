<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình usage của MediaAsset cho admin API
 * =====================================================================
 *
 * Resource chỉ trả alias/id/field cần cho picker và không expose class name
 * nội bộ hoặc đường dẫn file. Model linkable lồng bên trong chỉ được thêm khi
 * controller đã eager load quan hệ đó.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ usage sang payload JSON ổn định
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : MediaAssetUsage đã tạo hoặc đã eager load
 * - OUTPUT: array usage dùng cho attach/detach/reorder UI
 * =====================================================================
 */
class MediaAssetUsageResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Ánh xạ usage sang shape response của Media API
     * =====================================================================
     *
     * INPUT: $request HTTP hiện tại.
     * OUTPUT: array<string, mixed> gồm id, field, linkable và sort_order.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field->value,
            'linkable_type' => $this->linkable_type,
            'linkable_id' => $this->linkable_id,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'linkable' => $this->whenLoaded('linkable', function (): ?array {
                if ($this->linkable === null) {
                    return null;
                }

                return [
                    'id' => $this->linkable->getKey(),
                    'title' => $this->linkable->title ?? $this->linkable->name ?? null,
                ];
            }),
        ];
    }
}
