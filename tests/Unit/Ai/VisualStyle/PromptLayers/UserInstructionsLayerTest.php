<?php

namespace Tests\Unit\Ai\VisualStyle\PromptLayers;

use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\PromptLayers\UserInstructionsLayer;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test cho UserInstructionsLayer — join instructions,
 * bound 500 ký tự mỗi dòng, trả null khi trống.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - layer(), context(array $instructions).
 * - test_returns_null_when_instructions_empty().
 * - test_renders_instructions_as_additional_notes().
 * - test_bounds_each_instruction_to_500_chars().
 * - test_skips_non_string_and_empty_lines().
 *
 * INPUT/OUTPUT: instructions array in-memory; không provider/DB.
 * =====================================================================
 */
final class UserInstructionsLayerTest extends TestCase
{
    private function layer(): UserInstructionsLayer
    {
        return new UserInstructionsLayer;
    }

    private function context(array $instructions): VisualPromptContext
    {
        return new VisualPromptContext(
            style: new AiImageProfile,
            instructions: $instructions,
        );
    }

    public function test_returns_null_when_instructions_empty(): void
    {
        $this->assertNull($this->layer()->render($this->context([])));
        $this->assertNull($this->layer()->render($this->context(['   '])));
    }

    public function test_renders_instructions_as_additional_notes(): void
    {
        $out = $this->layer()->render($this->context(['emphasize cap', 'winter theme']));

        $this->assertStringContainsString('Additional notes:', $out);
        $this->assertStringContainsString('emphasize cap', $out);
        $this->assertStringContainsString('winter theme', $out);
    }

    public function test_bounds_each_instruction_to_500_chars(): void
    {
        $long = str_repeat('y', 600);
        $out = $this->layer()->render($this->context([$long]));

        $this->assertStringContainsString(str_repeat('y', 500), $out);
        $this->assertStringNotContainsString(str_repeat('y', 501), $out);
    }

    public function test_skips_non_string_and_empty_lines(): void
    {
        $out = $this->layer()->render($this->context(['first', '', '   ', 'second']));

        $this->assertStringContainsString('first', $out);
        $this->assertStringContainsString('second', $out);
        // Không đếm dòng rỗng: chỉ 2 line xuất hiện.
        $this->assertSame(1, substr_count($out, 'Additional notes:'));
    }
}
