<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\PromptLayers\PreservationConstraintLayer;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho PreservationConstraintLayer — render
 * "Preserve the subject's ..." từ subject_rules.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(), context(array $subjectRules).
 * - test_returns_null_when_subject_rules_empty().
 * - test_returns_null_when_no_preserve_flag_enabled().
 * - test_renders_preserved_attributes().
 * - test_renders_allow_perspective_and_occlusion().
 * - test_skips_disabled_flags().
 *
 * INPUT/OUTPUT: dna.subject_rules in-memory; không provider/DB.
 * =====================================================================
 */
final class PreservationConstraintLayerTest extends TestCase
{
    private function layer(): PreservationConstraintLayer
    {
        return new PreservationConstraintLayer;
    }

    private function context(array $subjectRules): VisualPromptContext
    {
        $style = new AiImageProfile;
        $style->dna_json = ['subject_rules' => $subjectRules];

        return new VisualPromptContext(style: $style);
    }

    public function test_returns_null_when_subject_rules_empty(): void
    {
        $this->assertNull($this->layer()->render($this->context([])));
    }

    public function test_returns_null_when_no_preserve_flag_enabled(): void
    {
        $out = $this->layer()->render($this->context([
            'preserve_shape' => false,
            'allow_perspective_change' => true,
        ]));

        $this->assertNull($out);
    }

    public function test_renders_preserved_attributes(): void
    {
        $out = $this->layer()->render($this->context([
            'preserve_shape' => true,
            'preserve_color' => true,
            'preserve_logo' => true,
            'preserve_text' => true,
            'preserve_material' => true,
        ]));

        $this->assertStringContainsString("Preserve the subject's", $out);
        $this->assertStringContainsString('shape and proportions', $out);
        $this->assertStringContainsString('original colors', $out);
        $this->assertStringContainsString('logo placement and design', $out);
        $this->assertStringContainsString('printed text legibility and content', $out);
        $this->assertStringContainsString('material appearance and texture', $out);
    }

    public function test_renders_allow_perspective_and_occlusion(): void
    {
        $out = $this->layer()->render($this->context([
            'preserve_shape' => true,
            'allow_perspective_change' => true,
            'allow_occlusion' => true,
        ]));

        $this->assertStringContainsString('perspective and camera angle may change', $out);
        $this->assertStringContainsString('partial occlusion is allowed', $out);
    }

    public function test_skips_disabled_flags(): void
    {
        $out = $this->layer()->render($this->context([
            'preserve_shape' => true,
            'preserve_text' => false,
            'allow_occlusion' => false,
        ]));

        $this->assertStringContainsString('shape and proportions', $out);
        $this->assertStringNotContainsString('printed text', $out);
        $this->assertStringNotContainsString('occlusion', $out);
    }
}
