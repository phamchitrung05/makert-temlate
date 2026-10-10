<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO dòng bảng cho kho bài AI đã duyệt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : archive đã qua query approved và eager load Post tối thiểu.
 * - OUTPUT: metadata an toàn cho bảng; không trả body/source JSON.
 * =====================================================================
 */
final class AiArticleArchiveSummaryResource extends JsonResource
{
    /**
     * Input: archive approved. Output: dòng bảng allowlist, chỉ đọc payload nhỏ.
     * Side effect: không gọi provider/DB ngoài relation đã eager load.
     */
    public function toArray(Request $request): array
    {
        $draft = (array) $this->draft_snapshot_json;
        $context = (array) $this->context_snapshot_json;
        $lifecycle = (array) $this->lifecycle_json;
        $post = $this->relationLoaded('post') ? $this->post : null;
        $isPost = $this->target_type === 'post';

        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'generation_no' => (int) $this->generation_no,
            'target_type' => $this->target_type,
            'target_id' => $this->applied_target_id,
            'title' => mb_substr((string) ($draft['title'] ?? $post?->title ?? 'Bản AI #'.$this->id), 0, 255),
            'provider' => $context['provider'] ?? null,
            'model' => $context['model'] ?? null,
            'writing_profile' => data_get($context, 'writing_profile.name'),
            'content_origin' => $this->content_origin,
            'has_generated_content' => (bool) $this->has_generated_content,
            'approved_at' => $lifecycle['reviewed_at'] ?? $this->created_at?->toIso8601String(),
            'archived_at' => $this->created_at?->toIso8601String(),
            'applied_target_id' => $this->applied_target_id,
            'quality_evaluation' => [
                'status' => data_get($lifecycle, 'quality_evaluation.score_total') !== null ? 'ready' : 'legacy_or_unavailable',
                'score_total' => data_get($lifecycle, 'quality_evaluation.score_total'),
                'rubric_version' => data_get($lifecycle, 'quality_evaluation.rubric_version'),
            ],
            'target' => [
                'type' => $this->target_type,
                'id' => $this->applied_target_id,
                'label' => $isPost ? ($post?->title ?? 'Post đã xóa') : null,
            ],
            'post' => $isPost && $post ? [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status?->value ?? $post->status,
            ] : null,
        ];
    }
}
