<?php

namespace App\Http\Resources;

use App\Services\Ai\Errors\AiPublicErrorMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize metadata tracker an toàn cho popup/detail.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): trả task summary/detail bounded gồm lifecycle, link và lỗi public tiếng Việt.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiTaskRun đã được controller scope owner.
 * - OUTPUT: task_type/source/model/status/progress/timestamps/error public và link parent/media bounded.
 * - SIDE EFFECT: serialize thuần; không đọc payload hoặc secret.
 * =====================================================================
 */
final class AiTaskRunResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize metadata cần cho task center, không lộ payload.
     * =====================================================================
     * INPUT: Request và tracker đã được controller scope owner/quyền.
     * OUTPUT: DTO bounded gồm lifecycle, link kết quả và lỗi public tiếng Việt.
     * SIDE EFFECT: chỉ đọc metadata tracker; không load taskable model.
     * EXCEPTION/TRANSACTION: không mở transaction hoặc gọi provider.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        $metadata = (array) $this->metadata_json;

        return [
            'id' => $this->id,
            'task_type' => $this->task_type,
            'source' => $this->source,
            'model' => $this->model,
            'provider' => $this->provider,
            'name' => $metadata['name'] ?? match ($this->task_type) {
                'article_generation' => 'Tạo nội dung AI',
                'image_generation' => 'Tạo ảnh AI',
                default => 'Tác vụ AI',
            },
            'status' => $this->status,
            'progress' => $this->progress,
            'error_code' => $this->error_code,
            'error_message' => $this->status === 'failed'
                ? AiPublicErrorMessage::message($this->error_code, $this->error_message, $this->task_type)
                : null,
            'taskable_id' => $this->taskable_id,
            'draft_profile_id' => $metadata['draft_profile_id'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'parent_id' => $metadata['parent_id'] ?? null,
            'media_asset_id' => $metadata['media_asset_id'] ?? null,
        ];
    }
}
