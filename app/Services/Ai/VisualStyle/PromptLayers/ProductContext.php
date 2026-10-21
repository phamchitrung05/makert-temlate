<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

use App\Models\Product;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO readonly mô tả sản phẩm cho một lần build prompt ảnh.
 * =====================================================================
 *
 * Sản phẩm có thể đến từ 2 nguồn: (1) `Product` entity đã lưu trong DB — lấy
 * name/short_description/category và fact được đánh dấu `is_allowed_for_ai`;
 * (2) upload ad-hoc — admin up ảnh sản phẩm kèm mô tả/category nhập tay, không
 * bắt buộc tạo entity Product. DTO này chuẩn hoá cả hai về cùng một shape để
 * các layer (đặc biệt `ProductIdentityLayer` và `ProductAdaptationLayer`) không
 * cần biết nguồn gốc.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): khởi tạo DTO readonly.
 * - fromUpload(): tạo từ media_ids + description + category nhập tay.
 * - fromProduct(): tạo từ `Product` model, lọc facts `is_allowed_for_ai`.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Product model hoặc (mediaIds, description, category) thô.
 * - OUTPUT: ProductContext readonly; không ghi DB.
 * =====================================================================
 */
final readonly class ProductContext
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo ProductContext readonly.
     * =====================================================================
     * INPUT:
     * - $mediaIds: mảng id media_assets của ảnh sản phẩm lần này (nullable khi
     *   caller chưa gắn ảnh hoặc chỉ cần context chữ).
     * - $description: mô tả ngắn sản phẩm (tên + mô tả gộp, hoặc text nhập tay).
     * - $category: category sản phẩm dùng để khớp `dna.product_adaptation`.
     * - $facts: mảng fact đã lọc `is_allowed_for_ai`, mỗi phần tử là chuỗi
     *   "key: value" hoặc value đơn giản để layer render.
     * OUTPUT: ProductContext readonly.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function __construct(
        public ?array $mediaIds,
        public ?string $description,
        public ?string $category,
        public array $facts = [],
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng ProductContext từ dữ liệu upload ad-hoc (không entity).
     * =====================================================================
     * INPUT:
     * - $mediaIds: id media_assets đã upload (có thể rỗng nếu chỉ có mô tả).
     * - $description: mô tả admin nhập cho sản phẩm lần này.
     * - $category: category text khớp product_adaptation rules.
     * OUTPUT: ProductContext với facts rỗng (upload ad-hoc không có fact store).
     * SIDE EFFECT: không có; không đọc/ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public static function fromUpload(array $mediaIds, ?string $description, ?string $category): self
    {
        return new self(
            mediaIds: $mediaIds === [] ? null : array_values($mediaIds),
            description: $description !== null ? trim($description) ?: null : null,
            category: $category !== null ? trim($category) ?: null : null,
            facts: [],
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng ProductContext từ `Product` model đã lưu.
     * =====================================================================
     * Đọc `name`/`short_description` gộp thành description, lấy `category` là
     * category của product nếu model có field đó (qua `category` attribute hoặc
     * để null khi product không có), và collect các `facts()` có
     * `is_allowed_for_ai = true` thành mảng "key: value" cho prompt.
     *
     * INPUT:
     * - $product: Product model đã load (sẽ lazy-load relation facts nếu chưa
     *   eager).
     * OUTPUT: ProductContext readonly; null fields khi product không có.
     * SIDE EFFECT: có thể trigger lazy-load `facts` (SELECT product_facts);
     * không ghi DB.
     * EXCEPTION/TRANSACTION: không mở transaction; caller chịu trách nhiệm nếu
     * cần eager load để tránh N+1.
     * =====================================================================
     */
    public static function fromProduct(Product $product): self
    {
        $description = trim(implode(' ', array_filter([
            $product->name,
            $product->short_description,
        ])));

        // Lọc fact được phép đưa vào AI; render "key: value" gọn cho prompt.
        $facts = $product->facts
            ->filter(static fn ($fact): bool => (bool) ($fact->is_allowed_for_ai ?? false))
            ->map(static function ($fact): string {
                $key = trim((string) ($fact->fact_key ?? ''));
                $value = trim((string) ($fact->fact_value ?? ''));

                return $key !== '' ? "{$key}: {$value}" : $value;
            })
            ->filter(static fn (string $line): bool => $line !== '')
            ->values()
            ->all();

        // Product model hiện chưa có cột category riêng — caller có thể truyền
        // sau qua `extras` hoặc bổ sung field; đọc attribute nếu có.
        $category = $product->getAttribute('category');
        $category = is_string($category) && trim($category) !== '' ? trim($category) : null;

        return new self(
            mediaIds: null,
            description: $description !== '' ? $description : null,
            category: $category,
            facts: $facts,
        );
    }
}
