<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\PromptLayers\ProductContext;
use App\Services\Ai\VisualStyle\PromptLayers\ProductIdentityLayer;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho ProductIdentityLayer — render "The subject
 * is ..." từ ProductContext, trả null khi không có product.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(): helper.
 * - context(?ProductContext $product): dựng VisualPromptContext.
 * - test_returns_null_without_product().
 * - test_renders_description_and_category().
 * - test_renders_facts_as_key_facts().
 * - test_renders_category_only_when_no_description().
 * - test_returns_null_when_product_empty().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ProductContext in-memory.
 * - OUTPUT: assertions chuỗi; không provider/DB.
 * =====================================================================
 */
final class ProductIdentityLayerTest extends TestCase
{
    private function layer(): ProductIdentityLayer
    {
        return new ProductIdentityLayer;
    }

    private function context(?ProductContext $product): VisualPromptContext
    {
        $style = new AiImageProfile;

        return new VisualPromptContext(style: $style, product: $product);
    }

    public function test_returns_null_without_product(): void
    {
        $this->assertNull($this->layer()->render($this->context(null)));
    }

    public function test_renders_description_and_category(): void
    {
        $product = new ProductContext(
            mediaIds: null,
            description: 'Handmade ceramic mug',
            category: 'drinkware',
            facts: [],
        );
        $out = $this->layer()->render($this->context($product));

        $this->assertStringContainsString('The subject is Handmade ceramic mug', $out);
        $this->assertStringContainsString('(drinkware)', $out);
    }

    public function test_renders_facts_as_key_facts(): void
    {
        $product = new ProductContext(
            mediaIds: null,
            description: 'Ceramic mug',
            category: null,
            facts: ['material: stoneware', 'capacity: 350ml'],
        );
        $out = $this->layer()->render($this->context($product));

        $this->assertStringContainsString('Key facts:', $out);
        $this->assertStringContainsString('material: stoneware', $out);
        $this->assertStringContainsString('capacity: 350ml', $out);
    }

    public function test_renders_category_only_when_no_description(): void
    {
        $product = new ProductContext(
            mediaIds: null,
            description: null,
            category: 'beverage',
            facts: [],
        );
        $out = $this->layer()->render($this->context($product));

        $this->assertStringContainsString('product in the beverage category', $out);
    }

    public function test_returns_null_when_product_empty(): void
    {
        $product = new ProductContext(
            mediaIds: null,
            description: null,
            category: null,
            facts: [],
        );
        $this->assertNull($this->layer()->render($this->context($product)));
    }
}
