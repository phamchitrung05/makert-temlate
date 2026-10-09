<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO tối thiểu cho popup hàng đợi analysis.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): trả summary popup bounded gồm task_run_id, lifecycle và lỗi an toàn.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : analysis đã được controller scope theo actor.
 * - OUTPUT: id/type/source/name/status/profile link/timestamps/error bounded; không có source/result/secret.
 * - SIDE EFFECT: serialize thuần, không đọc thêm quan hệ.
 * =====================================================================
 */
final class AiWritingProfileAnalysisSummaryResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize metadata an toàn cho task center.
     * =====================================================================
     * Input: Request và analysis. Output: DTO không chứa reference_text/snapshot/result.
     * Side effect: không ghi database hoặc gọi provider.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_run_id' => $this->task_run_id,
            'task_type' => 'writing_profile_analysis',
            'source' => 'ai_writing_profile',
            'name' => $this->name,
            'status' => $this->status,
            'source_type' => $this->source_type ?? 'paste',
            'draft_profile_id' => $this->draft_profile_id,
            'error_code' => $this->status === 'failed' ? $this->error_code : null,
            'error_message' => $this->status === 'failed' ? mb_substr((string) $this->error_message, 0, 500) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
