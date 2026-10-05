<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Polling analysis qua payload an toàn, không expose secret/source.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : analysis của actor đã kiểm quyền.
 * - OUTPUT: status/result/error/timestamps và model public, không endpoint/key.
 * - SIDE EFFECT: serialize thuần, không tự tạo profile.
 * =====================================================================
 */
final class AiWritingProfileAnalysisResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả tiến trình/kết quả preview bằng allowlist.
     * =====================================================================
     * Input: Request và analysis. Output: JSON data dùng để người dùng duyệt.
     * Side effect: không đọc thêm DB hoặc trả raw provider response.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->connection_snapshot_json ?? [];

        return [
            'id' => $this->id, 'name' => $this->name, 'status' => $this->status,
            'result' => $this->status === 'ready' ? $this->result_json : null,
            'provider' => $snapshot['provider'] ?? null, 'model' => $snapshot['model'] ?? null,
            'prompt_version' => $this->prompt_version, 'schema_version' => $this->schema_version,
            'diagnostics' => $this->diagnostics_json ?? [], 'error_code' => $this->error_code, 'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(), 'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(), 'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
