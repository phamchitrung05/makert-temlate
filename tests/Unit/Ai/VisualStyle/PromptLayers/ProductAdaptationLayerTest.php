<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\PromptLayers\ProductAdaptationLayer;
use App\Services\Ai\VisualStyle\PromptLayers\ProductContext;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho ProductAdaptationLayer — render rule theo
 * product.category khớp dna.product_adaptation.adaptation_rules.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(), context(?array $dna, ?string $category).
 * - test_returns_null_without_product().
 * - test_returns_null_without_category().
 * - test_returns_null_without_adaptation_rules().
 * - test_returns_null_when_category_not_matched().
 * - test_renders_rule_on_match_case_insensitive().
 * - test_renders_array_rule_as_joined_string().
 *
 * INPUT/OUTPUT: dna + ProductContext in-memory; không provider/DB.
 * =====================================================================
 */
final class ProductAdaptationLayerTest extends TestCase
{
    private function layer(): ProductAdaptationLayer
    {
        return new ProductAdaptationLayer;
    }

    private function context(?array $dna, ?string $category): VisualPromptContext
    {
        $style = new AiImageProfile;
        $style->dna_json = $dna;

        $product = $category === null
            ? null
            : new ProductContext(mediaIds: null, description: null, category: $category, facts: []);

        return new VisualPromptContext(style: $style, product: $product);
    }

    public function test_returns_null_without_product(): void
    {
        $style = new AiImageProfile;
        $style->dna_json = ['product_adaptation' => ['adaptation_rules' => ['beverage' => 'rule']]];
        $ctx = new VisualPromptContext(style: $style, product: null);

        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_returns_null_without_category(): void
    {
        $product = new ProductContext(mediaIds: null, description: null, category: null, facts: []);
        $style = new AiImageProfile;
        $style->dna_json = ['product_adaptation' => ['adaptation_rules' => ['beverage' => 'rule']]];
        $ctx = new VisualPromptContext(style: $style, product: $product);

        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_returns_null_without_adaptation_rules(): void
    {
        $ctx = $this->context(['composition' => ['framing' => 'x']], 'beverage');
        $this->assertNull($this->layer()->render($ctx));

        $ctx = $this->context(['product_adaptation' => []], 'beverage');
        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_returns_null_when_category_not_matched(): void
    {
        $ctx = $this->context(
            ['product_adaptation' => ['adaptation_rules' => ['food' => 'x']]],
            'beverage'
        );
        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_renders_rule_on_match_case_insensitive(): void
    {
        $ctx = $this->context(
            ['product_adaptation' => ['adaptation_rules' => ['Beverage' => 'show condensation']]],
            'beverage'
        );
        $out = $this->layer()->render($ctx);

        $this->assertNotNull($out);
        $this->assertStringContainsString('For beverage products:', $out);
        $this->assertStringContainsString('show condensation', $out);
    }

    public function test_renders_array_rule_as_joined_string(): void
    {
        $ctx = $this->context(
            ['product_adaptation' => ['adaptation_rules' => ['food' => ['keep steam visible', 'warm lighting']]]],
            'food'
        );
        $out = $this->layer()->render($ctx);

        $this->assertNotNull($out);
        $this->assertStringContainsString('keep steam visible', $out);
        $this->assertStringContainsString('warm lighting', $out);
    }
}
