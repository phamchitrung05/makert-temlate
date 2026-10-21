<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer render các ràng buộc "giữ nguyên" chủ thể từ
 * `dna.subject_rules` thành câu preservation cho image model.
 * =====================================================================
 *
 * `subject_rules` trong DNA đánh dấu thuộc tính sản phẩm phải giữ nguyên giữa
 * các lần generate — ví dụ logo/chữ/màu/hình dạng/chất liệu. Layer render các
 * flag `preserve_* = true` thành một câu liệt kê, kèm hai flag allow tương ứng
 * (`allow_perspective_change`, `allow_occlusion`) để model biết rõ phạm vi được
 * phép thay đổi. Trả `null` khi không có flag preserve nào được bật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'preservation_constraint'.
 * - render(VisualPromptContext): render câu preserve hoặc null.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->style->dna_json.subject_rules.
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null; không gọi provider/DB.
 * =====================================================================
 */
final class PreservationConstraintLayer implements PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'preservation_constraint'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'preservation_constraint'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'preservation_constraint';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render các flag preserve_* vào câu constraint.
     * =====================================================================
     * Map flag → cụm từ mô tả thuộc tính cần giữ; thêm "allow_perspective_change"
     * hoặc "allow_occlusion" vào cuối câu khi được bật để model biết giới hạn
     * cho phép. Không liệt kê flag `false` (giữ prompt gọn).
     *
     * INPUT:
     * - $context: VisualPromptContext; đọc `$context->style->dna_json['subject_rules']`.
     * OUTPUT:
     * - ?string: "Preserve the subject's ... ." hoặc null khi subject_rules
     *   rỗng/không có preserve nào bật.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        $dna = $context->style->dna_json;
        if (! is_array($dna)) {
            return null;
        }
        $rules = $dna['subject_rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            return null;
        }

        // Map flag preserve_* sang cụm mô tả thuộc tính. Thứ tự cố định để
        // prompt ổn định giữa các lần build.
        $preserveMap = [
            'preserve_shape' => 'shape and proportions',
            'preserve_color' => 'original colors',
            'preserve_logo' => 'logo placement and design',
            'preserve_text' => 'printed text legibility and content',
            'preserve_material' => 'material appearance and texture',
        ];

        $preserved = [];
        foreach ($preserveMap as $flag => $label) {
            if (($rules[$flag] ?? false) === true) {
                $preserved[] = $label;
            }
        }

        if ($preserved === []) {
            return null;
        }

        $list = implode(', ', $preserved);
        $sentence = "Preserve the subject's {$list}";

        $allows = [];
        if (($rules['allow_perspective_change'] ?? false) === true) {
            $allows[] = 'perspective and camera angle may change';
        }
        if (($rules['allow_occlusion'] ?? false) === true) {
            $allows[] = 'partial occlusion is allowed';
        }
        if ($allows !== []) {
            $sentence .= '; '.implode(' and ', $allows);
        }

        return $sentence.'.';
    }
}
