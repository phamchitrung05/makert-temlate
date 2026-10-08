<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình profile API, không trả source bài tham khảo hoặc key.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiWritingProfile đã duyệt.
 * - OUTPUT: metadata và hướng dẫn/rules/bằng chứng để quản trị viên chỉnh sửa.
 * - SIDE EFFECT: không đọc thêm quan hệ/gọi AI.
 * =====================================================================
 */
final class AiWritingProfileResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize profile bằng explicit allowlist.
     * =====================================================================
     * Input: Request và profile resource. Output: array API.
     * Side effect: không ghi database hoặc trả connection snapshot.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description,
            'rules_json' => $this->rules_json, 'evidence_json' => $this->evidence_json ?? [],
            'style_instructions' => $this->style_instructions, 'version' => $this->version,
            'origin' => $this->origin, 'status' => $this->status ?? 'active', 'is_enabled' => $this->is_enabled, 'created_by' => $this->created_by,
            'analysis_metadata' => $this->analysis_metadata_json,
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
