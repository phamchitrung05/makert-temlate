<?php

namespace Tests\Unit\Ai\VisualStyle;

use App\Models\AiImageProfile;
use App\Models\VisualStyleShot;
use App\Services\Ai\VisualStyle\PromptLayers\ProductContext;
use App\Services\Ai\VisualStyle\VisualPromptBuilder;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho VisualPromptBuilder — ghép layer đúng thứ tự,
 * bỏ layer null, chuẩn hoá chấm câu, và đọc negative qua layer riêng.
 * =====================================================================
 * Test dựng model `new AiImageProfile()` / `new VisualStyleShot()` bằng cách
 * gán attributes trực tiếp (không save DB) vì migration/model của agent khác
 * có thể chưa tồn tại khi test này chạy — nếu class chưa có, test fail và sẽ
 * được ghi trong báo cáo thay vì bỏ test.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - builder(): helper resolve VisualPromptBuilder với container thật.
 * - makeStyle(array $dna, ?string $negative): dựng AiImageProfile với dna_json.
 * - makeRecipe(array $attrs): dựng VisualStyleShot gán attributes.
 * - test_build_returns_empty_when_no_data(): tất cả layer null → ''.
 * - test_build_renders_dna_groups_in_config_order(): DNA đầy đủ → prompt chứa
 *   các nhóm Composition/Camera/Lighting/Environment/Color theo thứ tự.
 * - test_recipe_override_beats_dna_on_same_field(): recipe override field trùng.
 * - test_negative_combines_avoid_flags_and_style_negative(): negative gộp đúng.
 * - test_instructions_bounded_and_appended(): instructions join + bound 500.
 * - test_preservation_lists_enabled_flags(): subject_rules render preserve.
 * - test_build_throws_on_invalid_layer_config(): config sai → exception.
 * - test_layer_order_matches_config(): thứ tự layer đúng registry.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures dna_json + recipe attributes in-memory.
 * - OUTPUT: assertions prompt/negative; không gọi provider, không DB.
 * =====================================================================
 */
final class VisualPromptBuilderTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve builder bằng container Laravel thật của test app.
     * =====================================================================
     * INPUT: không có. OUTPUT: VisualPromptBuilder. SIDE EFFECT: resolve.
     * =====================================================================
     */
    private function builder(): VisualPromptBuilder
    {
        return new VisualPromptBuilder(app(Container::class));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng AiImageProfile in-memory với dna_json + negative_prompt.
     * =====================================================================
     * INPUT: $dna array (nullable) và $negative string|null.
     * OUTPUT: AiImageProfile đã gán attributes (không save).
     * =====================================================================
     */
    private function makeStyle(?array $dna, ?string $negative = null): AiImageProfile
    {
        $style = new AiImageProfile;
        $style->dna_json = $dna;
        $style->negative_prompt = $negative;

        return $style;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng VisualStyleShot in-memory với attributes gán trực tiếp.
     * =====================================================================
     * INPUT: $attrs — attributes cần gán (composition_override_json...).
     * OUTPUT: VisualStyleShot (không save).
     * =====================================================================
     */
    private function makeRecipe(array $attrs): VisualStyleShot
    {
        $recipe = new VisualStyleShot;
        foreach ($attrs as $key => $value) {
            $recipe->{$key} = $value;
        }

        return $recipe;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: khi không có product, DNA rỗng, không recipe → build = ''.
     * =====================================================================
     * INPUT: context chỉ có style rỗng. OUTPUT: assertSame('', prompt).
     * =====================================================================
     */
    public function test_build_returns_empty_when_no_data(): void
    {
        $context = new VisualPromptContext(style: $this->makeStyle(null));

        $this->assertSame('', $this->builder()->build($context));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: DNA đầy đủ render đủ các nhóm đúng thứ tự.
     * =====================================================================
     * INPUT: DNA có cả 5 nhóm composition/camera/lighting/environment/color_mood.
     * OUTPUT: prompt chứa "Composition:", "Camera:", "Lighting:", "Environment:",
     * "Color and mood:" xuất hiện đúng thứ tự tương đối.
     * =====================================================================
     */
    public function test_build_renders_dna_groups_in_config_order(): void
    {
        $dna = [
            'composition' => ['framing' => 'centered', 'orientation' => 'portrait'],
            'camera' => ['shot_type' => 'close-up', 'angle' => '45-degree'],
            'lighting' => ['type' => 'softbox', 'direction' => 'top-left'],
            'environment' => ['type' => 'studio', 'background_style' => 'seamless'],
            'color_mood' => ['palette' => ['#FFAA00', 'white'], 'mood' => ['premium']],
        ];
        $context = new VisualPromptContext(style: $this->makeStyle($dna));

        $prompt = $this->builder()->build($context);

        $this->assertStringContainsString('Composition:', $prompt);
        $this->assertStringContainsString('centered framing', $prompt);
        $this->assertStringContainsString('portrait orientation', $prompt);

        $this->assertStringContainsString('Camera:', $prompt);
        $this->assertStringContainsString('close-up shot', $prompt);
        $this->assertStringContainsString('45-degree angle', $prompt);

        $this->assertStringContainsString('Lighting:', $prompt);
        $this->assertStringContainsString('softbox lighting', $prompt);

        $this->assertStringContainsString('Environment:', $prompt);
        $this->assertStringContainsString('studio environment', $prompt);

        $this->assertStringContainsString('Color and mood:', $prompt);
        $this->assertStringContainsString('color palette: #FFAA00, white', $prompt);
        $this->assertStringContainsString('mood: premium', $prompt);

        // Đảm bảo Composition đứng trước Camera trong output (thứ tự config).
        $this->assertLessThan(
            strpos($prompt, 'Camera:'),
            strpos($prompt, 'Composition:')
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: recipe composition_override thắng field trùng của DNA.
     * =====================================================================
     * INPUT: DNA có framing=centered; recipe override framing=low-angle.
     * OUTPUT: prompt chứa "Composition override" với framing low-angle và không
     * chứa lại "centered framing" từ DNA (override chỉ render field recipe).
     * =====================================================================
     */
    public function test_recipe_override_beats_dna_on_same_field(): void
    {
        $dna = [
            'composition' => ['framing' => 'centered', 'orientation' => 'portrait'],
        ];
        $recipe = $this->makeRecipe([
            'composition_override_json' => ['framing' => 'low-angle'],
            'camera_override_json' => null,
            'prompt_suffix' => null,
        ]);
        $context = new VisualPromptContext(
            style: $this->makeStyle($dna),
            recipe: $recipe,
        );

        $prompt = $this->builder()->build($context);

        $this->assertStringContainsString('Shot recipe:', $prompt);
        $this->assertStringContainsString('Composition override:', $prompt);
        $this->assertStringContainsString('framing: low-angle', $prompt);
        // DNA vẫn render orientation từ StyleDnaLayer, không lặp lại framing.
        $this->assertStringContainsString('portrait orientation', $prompt);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: negative() gộp avoid_* + style.negative_prompt.
     * =====================================================================
     * INPUT: DNA có avoid_3d_render + avoid_illustration bật, style->negative_prompt
     * = "blurry, watermark". OUTPUT: negative chứa "3D render, CGI",
     * "illustration, drawing, painting" và "blurry, watermark".
     * =====================================================================
     */
    public function test_negative_combines_avoid_flags_and_style_negative(): void
    {
        $dna = [
            'style_constraints' => [
                'avoid_3d_render' => true,
                'avoid_illustration' => true,
                'avoid_unrealistic_hands' => false,
            ],
        ];
        $context = new VisualPromptContext(
            style: $this->makeStyle($dna, 'blurry, watermark')
        );

        $negative = $this->builder()->negative($context);

        $this->assertStringContainsString('3D render, CGI', $negative);
        $this->assertStringContainsString('illustration, drawing, painting', $negative);
        $this->assertStringContainsString('blurry, watermark', $negative);
        $this->assertStringNotContainsString('deformed or unrealistic hands', $negative);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: instructions join thành "Additional notes" và bound 500/dòng.
     * =====================================================================
     * INPUT: instructions = ["emphasize cap", "winter theme", chuỗi > 500 ký tự].
     * OUTPUT: prompt chứa "Additional notes: emphasize cap; winter theme; ..."
     * với dòng dài bị cắt đúng 500 ký tự.
     * =====================================================================
     */
    public function test_instructions_bounded_and_appended(): void
    {
        $longLine = str_repeat('x', 600);
        $context = new VisualPromptContext(
            style: $this->makeStyle(null),
            instructions: ['emphasize cap', 'winter theme', $longLine],
        );

        $prompt = $this->builder()->build($context);

        $this->assertStringContainsString('Additional notes:', $prompt);
        $this->assertStringContainsString('emphasize cap', $prompt);
        $this->assertStringContainsString('winter theme', $prompt);
        // Dòng dài bị cắt đúng 500 ký tự (trim dấu '.' cuối từ builder).
        $this->assertStringContainsString(str_repeat('x', 500), $prompt);
        $this->assertStringNotContainsString(str_repeat('x', 501), $prompt);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: subject_rules render danh sách preserve đúng flag bật.
     * =====================================================================
     * INPUT: subject_rules bật preserve_shape + preserve_logo, tắt preserve_text;
     * allow_perspective_change = true.
     * OUTPUT: prompt chứa "Preserve the subject's shape and proportions, logo
     * placement and design; perspective and camera angle may change."
     * =====================================================================
     */
    public function test_preservation_lists_enabled_flags(): void
    {
        $dna = [
            'subject_rules' => [
                'preserve_shape' => true,
                'preserve_logo' => true,
                'preserve_text' => false,
                'allow_perspective_change' => true,
            ],
        ];
        $context = new VisualPromptContext(style: $this->makeStyle($dna));

        $prompt = $this->builder()->build($context);

        $this->assertStringContainsString("Preserve the subject's", $prompt);
        $this->assertStringContainsString('shape and proportions', $prompt);
        $this->assertStringContainsString('logo placement and design', $prompt);
        $this->assertStringContainsString('perspective and camera angle may change', $prompt);
        $this->assertStringNotContainsString('printed text legibility', $prompt);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: config layer sai structure → InvalidArgumentException.
     * =====================================================================
     * INPUT: config set một entry không phải mảng.
     * OUTPUT: build() throw InvalidArgumentException.
     * =====================================================================
     */
    public function test_build_throws_on_invalid_layer_config(): void
    {
        config()->set('ai.visual_style_prompt_layers.layers', ['not-an-array']);

        $this->expectException(InvalidArgumentException::class);
        $this->builder()->build(new VisualPromptContext(style: $this->makeStyle(null)));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: thứ tự render đúng thứ tự layers trong config.
     * =====================================================================
     * INPUT: style DNA + product + recipe + instructions — tất cả layer render
     * non-null. OUTPUT: prompt chứa các section theo đúng thứ tự relative:
     * subject → Composition → Camera → ... → Shot recipe → Additional notes →
     * Preserve.
     * =====================================================================
     */
    public function test_layer_order_matches_config(): void
    {
        $dna = [
            'composition' => ['framing' => 'centered'],
            'camera' => ['shot_type' => 'close-up'],
            'subject_rules' => ['preserve_shape' => true],
        ];
        $product = new ProductContext(
            mediaIds: null,
            description: 'Sample product',
            category: 'beverage',
            facts: [],
        );
        $recipe = $this->makeRecipe([
            'composition_override_json' => null,
            'camera_override_json' => null,
            'prompt_suffix' => 'focus on label',
        ]);
        $context = new VisualPromptContext(
            style: $this->makeStyle($dna),
            recipe: $recipe,
            product: $product,
            instructions: ['keep it simple'],
        );

        $prompt = $this->builder()->build($context);

        $subjectPos = strpos($prompt, 'The subject is Sample product');
        $compositionPos = strpos($prompt, 'Composition:');
        $cameraPos = strpos($prompt, 'Camera:');
        $recipePos = strpos($prompt, 'Shot recipe:');
        $notesPos = strpos($prompt, 'Additional notes:');
        $preservePos = strpos($prompt, "Preserve the subject's");

        $this->assertNotFalse($subjectPos);
        $this->assertNotFalse($compositionPos);
        $this->assertNotFalse($cameraPos);
        $this->assertNotFalse($recipePos);
        $this->assertNotFalse($notesPos);
        $this->assertNotFalse($preservePos);

        // Thứ tự tương đối đúng config registry.
        $this->assertLessThan($compositionPos, $subjectPos);
        $this->assertLessThan($cameraPos, $compositionPos);
        $this->assertLessThan($recipePos, $cameraPos);
        $this->assertLessThan($notesPos, $recipePos);
        $this->assertLessThan($preservePos, $notesPos);
    }
}
