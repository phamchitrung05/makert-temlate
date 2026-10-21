<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer render rule điều chỉnh phong cách theo category sản
 * phẩm, đọc từ `dna.product_adaptation.adaptation_rules`.
 * =====================================================================
 *
 * Style DNA có thể khai `product_adaptation.adaptation_rules` dạng map
 * `{category => rule-string}` — ví dụ `"beverage": "show condensation on glass,
 * keep label readable"`. Khi `context->product->category` khớp một key (không
 * phân biệt hoa thường, trim), layer render rule tương ứng. Trả `null` khi:
 * không có product, product không có category, DNA không khai rules, hoặc
 * không có rule khớp.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'product_adaptation'.
 * - render(VisualPromptContext): render câu rule hoặc null.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->product->category và
 *   VisualPromptContext->style->dna_json.product_adaptation.
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null; không gọi provider/DB.
 * =====================================================================
 */
final class ProductAdaptationLayer implements PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'product_adaptation'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'product_adaptation'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'product_adaptation';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render rule adaptation khi product.category khớp rule.
     * =====================================================================
     * So khớp không phân biệt hoa thường, trim cả hai phía. Rule value có thể
     * là string đơn hoặc mảng string (nhiều rule cho 1 category) — cả hai được
     * chuẩn hoá thành một câu ghép bằng '; '.
     *
     * INPUT:
     * - $context: VisualPromptContext; đọc `$context->product->category` và
     *   `$context->style->dna_json['product_adaptation']`.
     * OUTPUT:
     * - ?string: câu "For {category} products: {rules}."; null khi không khớp.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        $category = $context->product?->category;
        if (! is_string($category) || trim($category) === '') {
            return null;
        }
        $category = trim($category);

        $dna = $context->style->dna_json;
        if (! is_array($dna)) {
            return null;
        }

        $rules = $dna['product_adaptation']['adaptation_rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            return null;
        }

        // So khớp case-insensitive; normalize key rule để so với category input.
        $matched = null;
        foreach ($rules as $ruleCategory => $ruleValue) {
            if (is_string($ruleCategory) && mb_strtolower(trim($ruleCategory)) === mb_strtolower($category)) {
                $matched = $ruleValue;
                break;
            }
        }

        if ($matched === null) {
            return null;
        }

        // Chuẩn hoá rule value: string đơn hoặc array string đều → chuỗi.
        $ruleText = null;
        if (is_string($matched) && trim($matched) !== '') {
            $ruleText = trim($matched);
        } elseif (is_array($matched)) {
            $items = array_values(array_filter(array_map(
                static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                $matched
            )));
            $ruleText = $items === [] ? null : implode('; ', $items);
        }

        if ($ruleText === null) {
            return null;
        }

        return "For {$category} products: {$ruleText}.";
    }
}
