<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\PromptLayers\NegativeLayer;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho NegativeLayer — negativePrompt() gộp avoid_*
 * + style->negative_prompt; render() trả null vì negative không nằm trong
 * prompt chính.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(), context(?array $constraints, ?string $negative).
 * - test_render_returns_null().
 * - test_negative_prompt_returns_empty_string_when_nothing().
 * - test_negative_prompt_includes_avoid_flags().
 * - test_negative_prompt_includes_style_negative().
 * - test_negative_prompt_combines_both().
 *
 * INPUT/OUTPUT: style_constraints + negative_prompt in-memory; không provider/DB.
 * =====================================================================
 */
final class NegativeLayerTest extends TestCase
{
    private function layer(): NegativeLayer
    {
        return new NegativeLayer;
    }

    private function context(?array $constraints, ?string $negative): VisualPromptContext
    {
        $style = new AiImageProfile;
        $style->dna_json = $constraints === null ? null : ['style_constraints' => $constraints];
        $style->negative_prompt = $negative;

        return new VisualPromptContext(style: $style);
    }

    public function test_render_returns_null(): void
    {
        $ctx = $this->context(['avoid_3d_render' => true], 'x');
        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_negative_prompt_returns_empty_string_when_nothing(): void
    {
        $ctx = $this->context(null, null);
        $this->assertSame('', NegativeLayer::negativePrompt($ctx));
    }

    public function test_negative_prompt_includes_avoid_flags(): void
    {
        $ctx = $this->context([
            'avoid_3d_render' => true,
            'avoid_illustration' => true,
            'avoid_unrealistic_hands' => true,
        ], null);
        $out = NegativeLayer::negativePrompt($ctx);

        $this->assertStringContainsString('3D render, CGI', $out);
        $this->assertStringContainsString('illustration, drawing, painting', $out);
        $this->assertStringContainsString('deformed or unrealistic hands', $out);
    }

    public function test_negative_prompt_includes_style_negative(): void
    {
        $ctx = $this->context(null, 'blurry, watermark');
        $out = NegativeLayer::negativePrompt($ctx);

        $this->assertStringContainsString('blurry, watermark', $out);
    }

    public function test_negative_prompt_combines_both(): void
    {
        $ctx = $this->context(
            ['avoid_3d_render' => true],
            'blurry'
        );
        $out = NegativeLayer::negativePrompt($ctx);

        $this->assertStringContainsString('3D render, CGI', $out);
        $this->assertStringContainsString('blurry', $out);
    }
}
