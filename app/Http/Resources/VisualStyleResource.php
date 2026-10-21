<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình Visual Style trả về API, không lộ đường dẫn/snapshot.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImageProfile đóng vai trò Visual Style.
 * - OUTPUT: metadata + DNA + số shot cho admin; reference_path, connection
 *   snapshot, analysis_instructions và reference_hash không bao giờ trả ra.
 * - SIDE EFFECT: không ghi DB/gọi AI; shots chỉ trả khi đã eager load.
 * =====================================================================
 */
final class VisualStyleResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize style bằng explicit allowlist.
     * =====================================================================
     * Input: Request và resource style. Output: array API.
     * Side effect: chỉ đọc thuộc tính/quan hệ đã nạp; không query thêm ngoài
     * shots khi đã eager load.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'status' => $this->status,
            'is_enabled' => $this->is_enabled,
            'summary' => $this->summary,
            'dna_json' => $this->dna_json,
            'schema_version' => $this->schema_version,
            'version' => $this->version,
            'prompt' => $this->prompt,
            'negative_prompt' => $this->negative_prompt,
            'error_message' => $this->error_message,
            'shots_count' => $this->whenCounted('shots'),
            'shots' => VisualStyleShotResource::collection($this->whenLoaded('shots')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
