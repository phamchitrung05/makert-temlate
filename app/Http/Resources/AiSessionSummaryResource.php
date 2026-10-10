<?php

namespace App\Http\Resources;

use App\Services\Ai\Content\AiContentReviewService;
use App\Services\Ai\Content\Quality\ArticleQualityEvaluationService;
use App\Services\Ai\Errors\AiPublicErrorMessage;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO tóm tắt tác vụ AI cho danh sách Ai Content.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): trả summary owner-scoped, identity session/run và metadata lịch sử phiên bản.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport thuộc admin hiện tại, chưa hết hạn.
 * - OUTPUT: tiêu đề, thumbnail đã lưu, lifecycle và identity; không trả source text, body,
 *   input snapshot, credential hoặc query string của URL nguồn.
 * =====================================================================
 */
class AiSessionSummaryResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả tiến độ và identity tối thiểu cho danh sách tác vụ
     * =====================================================================
     * INPUT: request và model đã authorize.
     * OUTPUT: summary public, lỗi an toàn và current_step; không trả source/profile rules.
     * SIDE EFFECT: chỉ đọc model; không gọi provider hoặc ghi database.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        $diagnostics = AiResponseDiagnostics::sanitize((array) data_get($this->source_meta_json, 'ai_response', []));

        return [
            'id' => $this->id,
            'session_id' => $this->session_id ?: $this->id,
            'generation_no' => (int) $this->generation_no,
            'operation' => $this->operation,
            'target_type' => data_get($this->input_json, 'target_type', 'post'),
            'parent_id' => $this->parent_id,
            'title' => mb_substr((string) data_get($this->result_json, 'draft.title', ''), 0, 255),
            'thumbnail' => $this->whenLoaded('thumbnail', fn () => MediaAssetResource::make($this->thumbnail), null),
            'thumbnail_generation' => AiThumbnailService::state($this->resource),
            'status' => $this->status,
            'current_step' => $this->current_step,
            'progress' => (int) $this->progress,
            'error_code' => $this->error_code,
            'error' => $this->status === 'failed'
                ? AiPublicErrorMessage::message($this->error_code, null,
                    data_get($this->input_json, 'target_type', 'post'), (array) ($diagnostics['validation_errors'] ?? []))
                : null,
            'validation_errors' => $this->status === 'failed' ? ($diagnostics['validation_errors'] ?? []) : [],
            'provider' => $this->provider,
            'model' => data_get($this->input_json, 'model'),
            'prompt_key' => data_get($this->input_json, 'prompt_key'),
            'prompt_version' => $this->prompt_version,
            'source_type' => data_get($this->input_json, 'source_type', filled($this->source_url) ? 'url' : 'text'),
            'source_host' => parse_url($this->source_url ?? '', PHP_URL_HOST) ?: null,
            'applied_target_id' => $this->applied_target_id,
            'review' => AiContentReviewService::state($this->resource),
            'quality_evaluation' => app(ArticleQualityEvaluationService::class)->summary($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
