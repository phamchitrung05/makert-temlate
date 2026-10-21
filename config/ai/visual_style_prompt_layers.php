<?php

use App\Services\Ai\VisualStyle\PromptLayers\NegativeLayer;
use App\Services\Ai\VisualStyle\PromptLayers\PreservationConstraintLayer;
use App\Services\Ai\VisualStyle\PromptLayers\ProductAdaptationLayer;
use App\Services\Ai\VisualStyle\PromptLayers\ProductIdentityLayer;
use App\Services\Ai\VisualStyle\PromptLayers\ShotRecipeLayer;
use App\Services\Ai\VisualStyle\PromptLayers\StyleDnaLayer;
use App\Services\Ai\VisualStyle\PromptLayers\UserInstructionsLayer;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry các prompt layer của Visual Style Engine theo
 * thứ tự render của VisualPromptBuilder.
 * =====================================================================
 *
 * Mỗi entry là một layer render một phần prompt; `VisualPromptBuilder` đọc
 * mảng `layers` bên dưới theo đúng thứ tự, resolve class qua container và
 * gọi render(VisualPromptContext). Layer trả `null` nghĩa là không áp dụng
 * và bị bỏ qua khỏi prompt cuối.
 *
 * CÁCH THÊM LAYER MỚI (ví dụ: brand kit, mood board, locale adapter):
 * 1. Tạo class mới trong `app/Services/Ai/VisualStyle/PromptLayers/`
 *    implement `PromptLayerContract` (key() + render()).
 * 2. Thêm entry ['key' => 'ten_layer', 'class' => TenLayer::class] vào
 *    mảng `layers` dưới đây ở đúng vị trí muốn render.
 * 3. Nếu layer cần dữ liệu mới ngoài style/recipe/product/instructions,
 *    đặt vào `VisualPromptContext::extras` (túi dự phòng đã khai báo sẵn).
 *
 * THỨ TỰ HIỆN TẠI (khớp plan mục 5):
 * - product_identity: mô tả sản phẩm lần này (từ ProductContext).
 * - style_dna: render DNA (composition/camera/lighting/environment/color_mood).
 * - product_adaptation: rule điều chỉnh theo category sản phẩm.
 * - shot_recipe: override góc/bố cục của recipe + prompt_suffix.
 * - user_instructions: ghi chú tự do của admin.
 * - preservation_constraint: giữ logo/chữ/màu/shape/material.
 * - negative: avoid_* + negative_prompt — được VisualPromptBuilder::negative()
 *   gọi riêng, không nằm trong prompt chính trừ khi layer khác cần.
 *
 * INPUT/OUTPUT: key layer -> class implement PromptLayerContract; không gọi
 * provider/DB.
 * =====================================================================
 */
return [
    'layers' => [
        ['key' => 'product_identity', 'class' => ProductIdentityLayer::class],
        ['key' => 'style_dna', 'class' => StyleDnaLayer::class],
        ['key' => 'product_adaptation', 'class' => ProductAdaptationLayer::class],
        ['key' => 'shot_recipe', 'class' => ShotRecipeLayer::class],
        ['key' => 'user_instructions', 'class' => UserInstructionsLayer::class],
        ['key' => 'preservation_constraint', 'class' => PreservationConstraintLayer::class],
        ['key' => 'negative', 'class' => NegativeLayer::class],
    ],
];
