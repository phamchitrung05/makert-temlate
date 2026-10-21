<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Models\VisualStyleShot;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO bất biến chứa toàn bộ dữ liệu đầu vào cho các
 * prompt layer của Visual Style Engine.
 * =====================================================================
 *
 * `VisualPromptBuilder` dựng context này một lần cho mỗi lần render prompt và
 * truyền nguyên vẹn qua từng layer. Readonly đảm bảo layer không thể sửa dữ
 * liệu của layer khác; layer đọc cần gì lấy đó, không cần biết layer khác.
 *
 * Trường `extras` là "túi dự phòng" có chủ đích: khi thêm layer mới cần dữ
 * liệu chưa có trên context (ví dụ brand kit, mood board, locale hints) mà
 * không muốn phá signature readonly, caller đặt payload vào `extras['ten_khoa']`
 * và layer mới đọc `$context->extras['ten_khoa'] ?? null`. Khi field trở nên
 * phổ biến có thể nâng lên property riêng trong refactor sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): khởi tạo DTO readonly.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImageProfile (bắt buộc), VisualStyleShot, ProductContext,
 *   instructions/aspectRatio/extras tuỳ chọn.
 * - OUTPUT: DTO readonly truyền cho layer/builder; không có side effect.
 * =====================================================================
 */
final readonly class VisualPromptContext
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo context bất biến cho một lần build prompt.
     * =====================================================================
     * INPUT:
     * - $style: AiImageProfile chứa Style DNA (dna_json) và negative_prompt.
     * - $recipe: VisualStyleShot tuỳ chọn chứa override composition/camera +
     *   prompt_suffix cho góc chụp cụ thể; null khi không dùng recipe.
     * - $product: ProductContext tuỳ chọn chứa mediaIds/description/category/facts.
     * - $instructions: mảng ghi chú tự do của admin (mỗi phần tử một dòng ý).
     * - $aspectRatio: gợi ý tỉ lệ khung hình (ví dụ '1:1', '4:5'); layer recipe
     *   hoặc layer aspect trong tương lai có thể dùng để bổ sung gợi ý.
     * - $extras: mảng dự phòng cho layer tương lai (xem docblock class).
     * OUTPUT: VisualPromptContext readonly.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function __construct(
        public AiImageProfile $style,
        public ?VisualStyleShot $recipe = null,
        public ?ProductContext $product = null,
        public array $instructions = [],
        public ?string $aspectRatio = null,
        public array $extras = [],
    ) {}
}
