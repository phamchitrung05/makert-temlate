<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\PromptLayers\StyleDnaLayer;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho StyleDnaLayer — render 5 nhóm DNA thành
 * mệnh đề tự nhiên, bỏ field rỗng, trả null khi DNA trống.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(): helper new StyleDnaLayer.
 * - context(array $dna): dựng VisualPromptContext chỉ có style.
 * - test_returns_null_when_dna_empty().
 * - test_renders_composition_fields().
 * - test_renders_camera_fields().
 * - test_renders_lighting_fields().
 * - test_renders_environment_fields().
 * - test_renders_color_mood_fields().
 * - test_skips_empty_fields_within_group().
 * - test_skips_zero_numeric_and_empty_array().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dna_json in-memory qua AiImageProfile.
 * - OUTPUT: assertions chuỗi render; không provider/DB.
 * =====================================================================
 */
final class StyleDnaLayerTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả layer instance.
     * =====================================================================
     * INPUT: không có. OUTPUT: StyleDnaLayer.
     * =====================================================================
     */
    private function layer(): StyleDnaLayer
    {
        return new StyleDnaLayer;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng context chỉ có style với dna_json.
     * =====================================================================
     * INPUT: $dna array|null. OUTPUT: VisualPromptContext.
     * =====================================================================
     */
    private function context(?array $dna): VisualPromptContext
    {
        $style = new AiImageProfile;
        $style->dna_json = $dna;

        return new VisualPromptContext(style: $style);
    }

    public function test_returns_null_when_dna_empty(): void
    {
        $this->assertNull($this->layer()->render($this->context(null)));
        $this->assertNull($this->layer()->render($this->context([])));
        $this->assertNull($this->layer()->render($this->context(['composition' => []])));
    }

    public function test_renders_composition_fields(): void
    {
        $ctx = $this->context([
            'composition' => [
                'framing' => 'centered',
                'subject_position' => 'center',
                'subject_scale' => 0.6,
                'orientation' => 'portrait',
                'visual_balance' => 'symmetric',
                'negative_space' => 'generous',
                'depth_layers' => ['foreground', 'midground'],
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Composition:', $out);
        $this->assertStringContainsString('centered framing', $out);
        $this->assertStringContainsString('subject positioned center', $out);
        $this->assertStringContainsString('subject scale 0.6', $out);
        $this->assertStringContainsString('portrait orientation', $out);
        $this->assertStringContainsString('symmetric visual balance', $out);
        $this->assertStringContainsString('generous negative space', $out);
        $this->assertStringContainsString('depth layers: foreground, midground', $out);
    }

    public function test_renders_camera_fields(): void
    {
        $ctx = $this->context([
            'camera' => [
                'shot_type' => 'macro',
                'angle' => 'top-down',
                'lens_look' => '85mm portrait',
                'depth_of_field' => 'shallow',
                'focus' => 'product label',
                'perspective' => 'isometric',
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Camera:', $out);
        $this->assertStringContainsString('macro shot', $out);
        $this->assertStringContainsString('top-down angle', $out);
        $this->assertStringContainsString('85mm portrait lens look', $out);
        $this->assertStringContainsString('shallow depth of field', $out);
        $this->assertStringContainsString('focus on product label', $out);
        $this->assertStringContainsString('isometric perspective', $out);
    }

    public function test_renders_lighting_fields(): void
    {
        $ctx = $this->context([
            'lighting' => [
                'type' => 'softbox',
                'direction' => '45-degree top-left',
                'quality' => 'diffused',
                'intensity' => 'medium',
                'temperature' => 'neutral',
                'shadows' => 'soft',
                'highlights' => 'controlled',
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Lighting:', $out);
        $this->assertStringContainsString('softbox lighting', $out);
        $this->assertStringContainsString('light from 45-degree top-left', $out);
        $this->assertStringContainsString('diffused light quality', $out);
        $this->assertStringContainsString('medium intensity', $out);
        $this->assertStringContainsString('neutral color temperature', $out);
        $this->assertStringContainsString('soft shadows', $out);
        $this->assertStringContainsString('controlled highlights', $out);
    }

    public function test_renders_environment_fields(): void
    {
        $ctx = $this->context([
            'environment' => [
                'type' => 'studio',
                'background_style' => 'seamless paper',
                'background_detail' => 'gradient grey',
                'background_blur' => 'subtle',
                'props' => 'wooden pedestal',
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Environment:', $out);
        $this->assertStringContainsString('studio environment', $out);
        $this->assertStringContainsString('seamless paper background', $out);
        $this->assertStringContainsString('background detail: gradient grey', $out);
        $this->assertStringContainsString('subtle background blur', $out);
        $this->assertStringContainsString('props: wooden pedestal', $out);
    }

    public function test_renders_color_mood_fields(): void
    {
        $ctx = $this->context([
            'color_mood' => [
                'palette' => ['#FFAA00', 'white'],
                'saturation' => 'high',
                'contrast' => 'high',
                'mood' => ['premium', 'clean'],
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('Color and mood:', $out);
        $this->assertStringContainsString('color palette: #FFAA00, white', $out);
        $this->assertStringContainsString('high saturation', $out);
        $this->assertStringContainsString('high contrast', $out);
        $this->assertStringContainsString('mood: premium, clean', $out);
    }

    public function test_skips_empty_fields_within_group(): void
    {
        $ctx = $this->context([
            'composition' => [
                'framing' => 'centered',
                'subject_position' => '',
                'subject_scale' => 0,
                'orientation' => '  ',
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('centered framing', $out);
        $this->assertStringNotContainsString('subject positioned', $out);
        $this->assertStringNotContainsString('subject scale', $out);
        $this->assertStringNotContainsString('orientation', $out);
    }

    public function test_skips_zero_numeric_and_empty_array(): void
    {
        $ctx = $this->context([
            'composition' => [
                'subject_scale' => 0,
                'depth_layers' => [],
                'framing' => 'tight',
            ],
        ]);
        $out = $this->layer()->render($ctx);

        $this->assertStringContainsString('tight framing', $out);
        $this->assertStringNotContainsString('subject scale', $out);
        $this->assertStringNotContainsString('depth layers', $out);
    }
}
