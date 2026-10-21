<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer render mô tả sản phẩm lần này cho prompt ảnh.
 * =====================================================================
 *
 * Layer đầu tiên trong pipeline — đặt "subject" của ảnh trước khi các layer
 * style/recipe/constraint thêm bối cảnh xung quanh. Render từ `ProductContext`:
 * description (tên + mô tả), category và danh sách facts đã được phép AI.
 * Trả `null` khi không có product (chỉ style preview, không có subject cụ thể).
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'product_identity'.
 * - render(VisualPromptContext): render câu subject tiếng Anh hoặc null.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->product (ProductContext|null).
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null; không gọi provider/DB.
 * =====================================================================
 */
final class ProductIdentityLayer implements PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'product_identity'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'product_identity'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'product_identity';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render phần "the subject is ..." cho prompt.
     * =====================================================================
     * Ghép `description` + `category` + `facts` thành câu ngắn gọn. Facts được
     * render sau dấu chấm riêng để image model phân biệt rõ với mô tả chung.
     * Không render mediaIds (ID nội bộ không có nghĩa với image model).
     *
     * INPUT:
     * - $context: VisualPromptContext; đọc `$context->product`.
     * OUTPUT:
     * - ?string: câu mô tả sản phẩm; null khi `product === null` hoặc product
     *   không có description/facts/category nào.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        $product = $context->product;
        if ($product === null) {
            return null;
        }

        $parts = [];

        $description = $product->description !== null ? trim($product->description) : '';
        if ($description !== '') {
            $sentence = "The subject is {$description}";
            if ($product->category !== null && trim($product->category) !== '') {
                $sentence .= ' ('.trim($product->category).')';
            }
            $parts[] = $sentence.'.';
        } elseif ($product->category !== null && trim($product->category) !== '') {
            // Không có description nhưng có category — vẫn đặt subject tối thiểu.
            $parts[] = 'The subject is a product in the '.trim($product->category).' category.';
        }

        if ($product->facts !== []) {
            $facts = implode('; ', array_map(
                static fn (string $fact): string => rtrim(trim($fact), '.'),
                $product->facts
            ));
            if ($facts !== '') {
                $parts[] = "Key facts: {$facts}.";
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
