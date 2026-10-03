<?php

namespace App\Http\Resources;

use App\Services\Ai\AiResponseDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO tóm tắt tác vụ AI cho danh sách Ai Content.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport thuộc admin hiện tại, chưa hết hạn.
 * - OUTPUT: tiêu đề, thumbnail đã lưu, lifecycle và identity; không trả source text, body,
 *   input snapshot, credential hoặc query string của URL nguồn.
 * =====================================================================
 */
class AiSessionSummaryResource extends JsonResource
{
    /** Input: request và model đã authorize. Output: summary public; chỉ đọc model, không gọi provider. */
    public function toArray(Request $request): array
    {
        $diagnostics = AiResponseDiagnostics::sanitize((array) data_get($this->source_meta_json, 'ai_response', []));

        return [
            'id' => $this->id,
            'target_type' => data_get($this->input_json, 'target_type', 'post'),
            'parent_id' => $this->parent_id,
            'title' => mb_substr((string) data_get($this->result_json, 'draft.title', ''), 0, 255),
            'thumbnail' => $this->whenLoaded('thumbnail', fn () => MediaAssetResource::make($this->thumbnail), null),
            'status' => $this->status,
            'progress' => (int) $this->progress,
            'error_code' => $this->error_code,
            'error' => $this->error_message,
            'validation_errors' => $this->status === 'failed' ? ($diagnostics['validation_errors'] ?? []) : [],
            'provider' => $this->provider,
            'model' => data_get($this->input_json, 'model'),
            'source_type' => data_get($this->input_json, 'source_type', filled($this->source_url) ? 'url' : 'text'),
            'source_host' => parse_url($this->source_url ?? '', PHP_URL_HOST) ?: null,
            'applied_target_id' => $this->applied_target_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
