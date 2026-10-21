<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Contract cho mọi prompt layer của Visual Style Engine.
 * =====================================================================
 *
 * Mỗi layer render một phần prompt tiếng Anh từ `VisualPromptContext`.
 * `VisualPromptBuilder` resolve từng layer qua container theo thứ tự trong
 * `config/ai/visual_style_prompt_layers.php`, gọi `render()` và bỏ qua layer
 * trả `null` (dữ liệu không đủ hoặc không áp dụng).
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): định danh ngắn của layer, khớp `key` trong config registry.
 * - render(VisualPromptContext $context): render chuỗi prompt hoặc null.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext chứa style/recipe/product/instructions/extras.
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null khi layer không áp dụng.
 * - SIDE EFFECT: không được gọi provider, không ghi DB, không throw ngoại trừ
 *   lỗi cấu hình nghiêm trọng.
 * =====================================================================
 */
interface PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Định danh ngắn của layer dùng trong registry và debug.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: string key (ví dụ 'shot_recipe'), khớp entry trong config.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function key(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Render phần prompt của layer từ context đầu vào.
     * =====================================================================
     * INPUT:
     * - $context: VisualPromptContext chứa style/recipe/product/instructions/extras.
     * OUTPUT:
     * - ?string: đoạn prompt tiếng Anh đã trim; null khi layer không áp dụng
     *   (thiếu dữ liệu, điều kiện không khớp) — builder sẽ bỏ qua.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có; layer phải an toàn với dữ liệu trống.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string;
}
