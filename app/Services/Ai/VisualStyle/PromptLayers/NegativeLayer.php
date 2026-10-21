<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer tập hợp negative prompt — các hạn chế "tránh" từ
 * `dna.style_constraints.avoid_*` cộng `style->negative_prompt` do admin nhập.
 * =====================================================================
 *
 * Khác với các layer khác, phần negative không nằm trong prompt chính —
 * `VisualPromptBuilder::negative()` gọi `NegativeLayer::negativePrompt()` riêng
 * để lấy chuỗi negative gửi cho image provider ở field riêng. `render()` của
 * layer này trả `null` (không đóng góp vào prompt chính) để giữ contract
 * registry nhất quán; builder dùng `key()` để nhận diện và gọi hàm riêng.
 *
 * avoid_* flag được render thành cụm mô tả (ví dụ avoid_3d_render → "3D render,
 * CGI"). `style->negative_prompt` nối tiếp như một cụm phụ thêm.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'negative'.
 * - render(VisualPromptContext): luôn null (không nằm trong prompt chính).
 * - negativePrompt(VisualPromptContext): static — render chuỗi negative cho
 *   builder gọi riêng.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->style->dna_json.style_constraints và
 *   ->negative_prompt.
 * - OUTPUT: chuỗi negative prompt hoặc '' khi không có hạn chế nào.
 * =====================================================================
 */
final class NegativeLayer implements PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'negative'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'negative'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'negative';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: render() trả null — negative không nằm trong prompt chính.
     * =====================================================================
     * `VisualPromptBuilder` nhận diện layer này bằng `key()` và gọi
     * `negativePrompt()` riêng; render() chỉ tồn tại để giữ contract.
     *
     * INPUT: $context — bỏ qua.
     * OUTPUT: luôn null.
     * SIDE EFFECT: không có. EXCEPTION: không có.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        return null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render toàn bộ negative prompt (avoid_* + negative_prompt).
     * =====================================================================
     * Static vì builder gọi riêng theo key mà không cần resolve trong pipeline
     * chính. Trả chuỗi rỗng '' khi không có avoid_* nào bật và không có
     * negative_prompt — caller kiểm tra `=== ''` để biết "không có negative".
     *
     * INPUT:
     * - $context: VisualPromptContext; đọc `style->dna_json['style_constraints']`
     *   và `style->negative_prompt`.
     * OUTPUT:
     * - string: negative prompt tiếng Anh, các cụm ngăn cách ', '; có thể ''.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public static function negativePrompt(VisualPromptContext $context): string
    {
        $parts = [];

        $dna = $context->style->dna_json;
        if (is_array($dna)) {
            $constraints = $dna['style_constraints'] ?? null;
            if (is_array($constraints)) {
                // Map flag avoid_* sang cụm mô tả cho image model. Thứ tự cố
                // định để prompt ổn định.
                $avoidMap = [
                    'avoid_3d_render' => '3D render, CGI',
                    'avoid_illustration' => 'illustration, drawing, painting',
                    'avoid_unrealistic_hands' => 'deformed or unrealistic hands',
                ];
                foreach ($avoidMap as $flag => $label) {
                    if (($constraints[$flag] ?? false) === true) {
                        $parts[] = $label;
                    }
                }
                // Flag photorealistic/commercial_quality là hạn chế POSITIVE —
                // không nằm trong avoidMap, được layer khác hoặc provider xử lý.
            }
        }

        $negative = $context->style->negative_prompt;
        if (is_string($negative) && trim($negative) !== '') {
            $parts[] = trim($negative);
        }

        return implode(', ', $parts);
    }
}
