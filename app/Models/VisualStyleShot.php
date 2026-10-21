<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Shot Recipe — góc chụp/bố cục cụ thể của một Visual Style.
 * =====================================================================
 * Mỗi shot chỉ lưu phần override so với Style DNA (composition/camera) cùng
 * hậu tố prompt và tỉ lệ gợi ý; field override trống nghĩa là kế thừa DNA.
 * Shot được sắp xếp bằng sort_order và có thể tắt bằng is_active mà không xóa.
 * CÁC HÀM/METHOD TRONG FILE: casts(), visualStyle().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : code/name duy nhất trong style, override JSON, sort_order, is_active.
 * - OUTPUT: recipe để PromptBuilder merge vào DNA khi render prompt cuối.
 * - SIDE EFFECT: Eloquent lưu database khi caller yêu cầu, không gọi AI.
 * =====================================================================
 */
final class VisualStyleShot extends Model
{
    protected $fillable = [
        'visual_style_id', 'code', 'name', 'description',
        'composition_override_json', 'camera_override_json',
        'prompt_suffix', 'suggested_aspect_ratio', 'sort_order', 'is_active',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép kiểu dữ liệu shot recipe.
     * =====================================================================
     * Input: thuộc tính Eloquent. Output: override JSON thành array,
     * is_active thành boolean, sort_order thành integer.
     * Side effect: không ghi database.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'composition_override_json' => 'array',
            'camera_override_json' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết Visual Style sở hữu shot recipe.
     * =====================================================================
     * Input: model hiện tại. Output: quan hệ BelongsTo AiImageProfile qua FK visual_style_id.
     * Side effect: chỉ query khi nạp quan hệ.
     * =====================================================================
     */
    public function visualStyle(): BelongsTo
    {
        return $this->belongsTo(AiImageProfile::class, 'visual_style_id');
    }
}
