<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình trạng thái và outputs của một run sinh ảnh
 * Visual Style (AiImport operation visual_style_generate).
 * =====================================================================
 * Resource chỉ đọc model đã nạp; gộp result_json['outputs'] per-shot về một
 * danh sách phẳng {recipe_code, shot_id, status, images[{slot_no,status,
 * media_asset_id,prompt_used,error_* }]} để admin render/retry từng phần.
 * Không trả connection snapshot, input_json thô hay file path nội bộ.
 * CÁC HÀM/METHOD TRONG FILE: toArray(), outputs().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport của run generate.
 * - OUTPUT: id/status/progress/error + outputs đã flatten.
 * - SIDE EFFECT: không ghi DB/gọi provider.
 * =====================================================================
 */
final class VisualStyleGenerationResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize run theo allowlist.
     * =====================================================================
     * Input: Request và AiImport. Output: array an toàn cho admin.
     * Side effect: chỉ đọc thuộc tính/result_json; không query thêm.
     * =====================================================================
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'progress' => (int) $this->progress,
            'operation' => $this->operation,
            'provider' => $this->provider,
            'visual_style_id' => (int) data_get($this->input_json, 'visual_style_id'),
            'style_version' => (int) data_get($this->input_json, 'style_version'),
            'aspect_ratio' => (string) data_get($this->input_json, 'aspect_ratio', ''),
            'image_count' => (int) data_get($this->input_json, 'image_count', 0),
            'shot_recipe_ids' => (array) data_get($this->input_json, 'shot_recipe_ids', []),
            'outputs' => $this->outputs(),
            'summary' => (array) data_get($this->result_json, 'summary', ['succeeded' => 0, 'failed' => 0]),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Flatten outputs per-shot thành danh sách tuyến tính.
     * =====================================================================
     * Input: result_json['outputs'] theo recipe_code. Output: mảng phẳng
     * mỗi phần tử đại diện một shot (status riêng) hoặc một ảnh (khi caller
     * cần per-image). Cấu trúc giữ nguyên shape lưu trong DB để frontend
     * không cần đoán.
     * =====================================================================
     *
     * @return array<string, mixed>
     */
    private function outputs(): array
    {
        $outputs = (array) data_get($this->result_json, 'outputs', []);

        return $outputs;
    }
}
