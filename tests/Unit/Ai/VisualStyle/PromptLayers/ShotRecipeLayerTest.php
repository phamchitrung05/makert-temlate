<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Models\VisualStyleShot;
use App\Services\Ai\VisualStyle\PromptLayers\ShotRecipeLayer;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho ShotRecipeLayer — render override + suffix,
 * trả null khi không recipe hoặc recipe trống.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(), makeRecipe(array $attrs).
 * - test_returns_null_without_recipe().
 * - test_returns_null_when_recipe_empty().
 * - test_renders_composition_override().
 * - test_renders_camera_override().
 * - test_renders_prompt_suffix().
 * - test_skips_empty_fields_in_override().
 * - test_renders_suggested_aspect_ratio_only_when_context_aspect_null().
 * - test_context_aspect_ratio_overrides_suggested().
 *
 * INPUT/OUTPUT: recipe + context in-memory; không provider/DB.
 * =====================================================================
 */
final class ShotRecipeLayerTest extends TestCase
{
    private function layer(): ShotRecipeLayer
    {
        return new ShotRecipeLayer;
    }

    private function makeRecipe(array $attrs): VisualStyleShot
    {
        $recipe = new VisualStyleShot;
        foreach ($attrs as $k => $v) {
            $recipe->{$k} = $v;
        }

        return $recipe;
    }

    private function style(): AiImageProfile
    {
        return new AiImageProfile;
    }

    public function test_returns_null_without_recipe(): void
    {
        $ctx = new VisualPromptContext(style: $this->style(), recipe: null);
        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_returns_null_when_recipe_empty(): void
    {
        $recipe = $this->makeRecipe([
            'composition_override_json' => null,
            'camera_override_json' => null,
            'prompt_suffix' => null,
        ]);
        $ctx = new VisualPromptContext(style: $this->style(), recipe: $recipe);
        $this->assertNull($this->layer()->render($ctx));
    }

    public function test_renders_composition_override(): void
    {
        $recipe = $this->makeRecipe([
            'composition_override_json' => ['framing' => 'low-angle', 'negative_space' => 'minimal'],
        ]);
        $ctx = new VisualPromptContext(style: $this->style(), recipe: $recipe);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Shot recipe:', $out);
        $this->assertStringContainsString('Composition override:', $out);
        $this->assertStringContainsString('framing: low-angle', $out);
        $this->assertStringContainsString('negative space: minimal', $out);
    }

    public function test_renders_camera_override(): void
    {
        $recipe = $this->makeRecipe([
            'camera_override_json' => ['shot_type' => 'macro', 'depth_of_field' => 'shallow'],
        ]);
        $ctx = new VisualPromptContext(style: $this->style(), recipe: $recipe);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Camera override:', $out);
        $this->assertStringContainsString('shot type: macro', $out);
        $this->assertStringContainsString('depth of field: shallow', $out);
    }

    public function test_renders_prompt_suffix(): void
    {
        $recipe = $this->makeRecipe(['prompt_suffix' => 'close-up on the cap']);
        $ctx = new VisualPromptContext(style: $this->style(), recipe: $recipe);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('close-up on the cap', $out);
    }

    public function test_skips_empty_fields_in_override(): void
    {
        $recipe = $this->makeRecipe([
            'composition_override_json' => [
                'framing' => 'tight',
                'subject_position' => '',
                'negative_space' => null,
            ],
        ]);
        $ctx = new VisualPromptContext(style: $this->style(), recipe: $recipe);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('framing: tight', $out);
        $this->assertStringNotContainsString('subject position:', $out);
        $this->assertStringNotContainsString('negative space:', $out);
    }

    public function test_renders_suggested_aspect_ratio_only_when_context_aspect_null(): void
    {
        $recipe = $this->makeRecipe(['suggested_aspect_ratio' => '4:5']);
        $ctx = new VisualPromptContext(style: $this->style(), recipe: $recipe, aspectRatio: null);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Suggested aspect ratio: 4:5', $out);
    }

    public function test_context_aspect_ratio_overrides_suggested(): void
    {
        $recipe = $this->makeRecipe(['suggested_aspect_ratio' => '4:5']);
        $ctx = new VisualPromptContext(
            style: $this->style(),
            recipe: $recipe,
            aspectRatio: '1:1'
        );
        $out = $this->layer()->render($ctx);

        // recipe có suggested nhưng context đã có aspectRatio tường minh → không
        // render suggested, và prompt_suffix rỗng → layer trả null.
        $this->assertNull($out);
    }
}
