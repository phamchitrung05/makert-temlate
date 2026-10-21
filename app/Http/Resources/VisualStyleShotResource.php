<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình Shot Recipe trả về API.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualStyleShot thuộc một Visual Style.
 * - OUTPUT: đủ trường recipe gồm override JSON và trạng thái cho admin.
 * - SIDE EFFECT: không ghi DB/gọi AI.
 * =====================================================================
 */
final class VisualStyleShotResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize shot recipe bằng explicit allowlist.
     * =====================================================================
     * Input: Request và resource shot. Output: array API.
     * Side effect: chỉ đọc thuộc tính đã nạp.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visual_style_id' => $this->visual_style_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'composition_override' => $this->composition_override_json,
            'camera_override' => $this->camera_override_json,
            'prompt_suffix' => $this->prompt_suffix,
            'suggested_aspect_ratio' => $this->suggested_aspect_ratio,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
