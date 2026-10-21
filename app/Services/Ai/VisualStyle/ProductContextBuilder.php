<?php

namespace App\Services\Ai\VisualStyle;

use App\Models\Product;
use App\Services\Ai\VisualStyle\PromptLayers\ProductContext;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Factory cho `ProductContext` từ request upload ad-hoc hoặc
 * từ `Product` entity đã lưu.
 * =====================================================================
 *
 * Boundary duy nhất biết cách chuyển dữ liệu đầu vào (request array hoặc
 * product id) thành `ProductContext` readonly cho `VisualPromptContext`.
 * Controller/service gọi vào đây thay vì tự dựng DTO, giữ normalize/lọc fact
 * ở một chỗ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - fromRequest(array $values): dựng từ payload upload (media_ids, description,
 *   category) — không touch DB.
 * - fromProductId(?int $id): dựng từ `Product` model khi caller có id; trả
 *   null khi id null hoặc product không tồn tại.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : array request values hoặc product id.
 * - OUTPUT: ProductContext hoặc null.
 * - SIDE EFFECT: fromProductId() đọc Product + lazy-load facts (SELECT);
 *   không ghi DB.
 * =====================================================================
 */
final class ProductContextBuilder
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng ProductContext từ payload request upload ad-hoc.
     * =====================================================================
     * Chuẩn hoá `media_ids` thành mảng int, `description`/`category` về string
     * trim; bỏ qua field không phải scalar/array hợp lệ. Không đọc DB, không
     * kiểm tra media tồn tại — việc đó thuộc MediaAsset boundary khi attach.
     *
     * INPUT:
     * - $values: mảng đã validate ở FormRequest (hoặc raw request data) với
     *   key `media_ids` (array<int>|null), `description` (string|null),
     *   `category` (string|null).
     * OUTPUT:
     * - ProductContext với facts rỗng.
     * SIDE EFFECT: không gọi DB; normalize input.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function fromRequest(array $values): ProductContext
    {
        $mediaIds = null;
        $rawIds = $values['media_ids'] ?? null;
        if (is_array($rawIds)) {
            $ids = array_values(array_filter(array_map(
                static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
                $rawIds
            )));
            $mediaIds = $ids === [] ? null : $ids;
        }

        $description = $values['description'] ?? null;
        $description = is_string($description) && trim($description) !== ''
            ? trim($description)
            : null;

        $category = $values['category'] ?? null;
        $category = is_string($category) && trim($category) !== ''
            ? trim($category)
            : null;

        return new ProductContext(
            mediaIds: $mediaIds,
            description: $description,
            category: $category,
            facts: [],
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng ProductContext từ product id khi có.
     * =====================================================================
     * `ProductContext::fromProduct()` đọc `name`/`short_description`/`category`
     * và collect facts `is_allowed_for_ai`; facts được lazy-load qua relation
     * `facts` nếu chưa eager. Trả `null` khi id null hoặc product không tồn
     * tại (không throw — caller phân biệt "không dùng product" với "product
     * không tìm thấy" ở tầng trên nếu cần).
     *
     * INPUT:
     * - $id: product id hoặc null.
     * OUTPUT:
     * - ?ProductContext: null khi id null hoặc không tìm thấy product.
     * SIDE EFFECT: SELECT product + product_facts khi id hợp lệ và tồn tại.
     * EXCEPTION/TRANSACTION: không mở transaction; đọc bình thường.
     * =====================================================================
     */
    public function fromProductId(?int $id): ?ProductContext
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        $product = Product::find($id);
        if ($product === null) {
            return null;
        }

        return ProductContext::fromProduct($product);
    }
}
