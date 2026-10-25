<?php

namespace Tests\Unit;

use App\Services\Ai\VisualStyle\VisualStyleDnaDefinition;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Unit test contract Style DNA (schema/enum/normalize).
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - validDna(): fixture DNA đủ section và enum hợp lệ.
 * - test_valid_dna_passes_and_is_normalized(): DNA hợp lệ đi qua và chuẩn hóa.
 * - test_missing_required_section_rejected(): thiếu section bắt buộc bị reject.
 * - test_enum_out_of_list_rejected(): giá trị ngoài enum bị reject.
 * - test_unknown_field_rejected(): field lạ trong section bị reject.
 * - test_shot_override_partial_and_enum_checked(): override shot chỉ cần subset.
 * - test_defaults_boolean_filled_when_missing(): subject_rules/style_constraints
 *   nhận defaults khi AI bỏ trống.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : mảng DNA giả lập output Vision/admin.
 * - OUTPUT: DNA chuẩn hóa hoặc ValidationException theo field.
 * =====================================================================
 */
final class VisualStyleDnaDefinitionTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Fixture DNA đầy đủ hợp lệ dùng lại trong nhiều test.
     * =====================================================================
     * Input: không có. Output: mảng DNA đủ section, enum đúng config.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function validDna(): array
    {
        return [
            'summary' => 'Studio sáng dịu, nền gradient xám.',
            'identity' => ['name' => 'Soft Studio', 'category' => 'product', 'keywords' => ['studio', 'soft']],
            'composition' => [
                'framing' => 'medium', 'subject_position' => 'center', 'subject_scale' => 0.6,
                'orientation' => 'square', 'visual_balance' => 'symmetric', 'negative_space' => 'moderate',
                'depth_layers' => ['subject', 'background'],
            ],
            'camera' => [
                'shot_type' => 'product_single', 'angle' => 'eye_level', 'lens_look' => 'standard',
                'depth_of_field' => 'moderate', 'focus' => 'sharp_all', 'perspective' => 'frontal',
            ],
            'lighting' => [
                'type' => 'studio_softbox', 'direction' => 'front_side', 'quality' => 'soft',
                'intensity' => 'medium', 'temperature' => 'neutral', 'shadows' => 'soft', 'highlights' => 'controlled',
            ],
            'environment' => [
                'type' => 'studio_gradient', 'background_style' => 'gradient',
                'background_blur' => 'none', 'environment_adaptation' => true,
            ],
            'color_mood' => [
                'palette' => ['#ffffff', '#d9d9d9'], 'saturation' => 'natural', 'contrast' => 'moderate',
                'mood' => ['clean', 'premium'],
            ],
        ];
    }

    public function test_valid_dna_passes_and_is_normalized(): void
    {
        $dna = app(VisualStyleDnaDefinition::class)->validate($this->validDna());
        $this->assertSame('1.0', $dna['schema_version']);
        $this->assertSame('medium', $dna['composition']['framing']);
        $this->assertSame(0.6, $dna['composition']['subject_scale']);
        $this->assertTrue($dna['subject_rules']['preserve_logo']);
        $this->assertFalse($dna['subject_rules']['allow_occlusion']);
        $this->assertTrue($dna['style_constraints']['photorealistic']);
        $this->assertSame(['clean', 'premium'], $dna['color_mood']['mood']);
    }

    public function test_missing_required_section_rejected(): void
    {
        $dna = $this->validDna();
        unset($dna['lighting']);
        $this->expectException(ValidationException::class);
        app(VisualStyleDnaDefinition::class)->validate($dna);
    }

    public function test_enum_out_of_list_rejected(): void
    {
        $dna = $this->validDna();
        $dna['camera']['angle'] = 'eye level'; // không phải enum chuẩn 'eye_level'
        try {
            app(VisualStyleDnaDefinition::class)->validate($dna);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('camera.angle', $exception->errors());
        }
    }

    public function test_unknown_field_rejected(): void
    {
        $dna = $this->validDna();
        $dna['composition']['mystery_field'] = 'x';
        try {
            app(VisualStyleDnaDefinition::class)->validate($dna);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('composition.mystery_field', $exception->errors());
        }
    }

    public function test_missing_enum_field_rejected(): void
    {
        $dna = $this->validDna();
        unset($dna['lighting']['type']);
        try {
            app(VisualStyleDnaDefinition::class)->validate($dna);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lighting.type', $exception->errors());
        }
    }

    public function test_shot_override_partial_and_enum_checked(): void
    {
        $definition = app(VisualStyleDnaDefinition::class);
        $override = $definition->validateOverride('camera', ['angle' => 'birds_eye']);
        $this->assertSame(['angle' => 'birds_eye'], $override);
        $this->expectException(ValidationException::class);
        $definition->validateOverride('camera', ['angle' => 'sideways']);
    }

    public function test_suggested_shots_normalized_and_bad_code_rejected(): void
    {
        $dna = $this->validDna();
        $dna['suggested_shots'] = [
            ['code' => 'hero_front', 'name' => 'Hero Front', 'camera_override' => ['angle' => 'eye_level']],
            ['code' => '', 'name' => 'Bad'],
        ];
        $this->expectException(ValidationException::class);
        app(VisualStyleDnaDefinition::class)->validate($dna);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: taxonomy_suggestion được chuẩn hóa và giữ lại trong DNA.
     * =====================================================================
     * Input: DNA hợp lệ kèm taxonomy_suggestion match_existing + propose_new.
     * Output: suggestion chuẩn hóa (action/tags bounded) hoặc reject key lạ.
     * =====================================================================
     */
    public function test_taxonomy_suggestion_normalized(): void
    {
        $dna = $this->validDna();
        $dna['taxonomy_suggestion'] = [
            'action' => 'match_existing',
            'category' => 'Lifestyle',
            'tags' => ['cà phê', 'flat lay'],
            'confidence' => 0.9,
            'reason' => 'Khớp nhóm lifestyle hiện có.',
        ];
        $normalized = app(VisualStyleDnaDefinition::class)->validate($dna);
        $this->assertSame('match_existing', $normalized['taxonomy_suggestion']['action']);
        $this->assertSame('Lifestyle', $normalized['taxonomy_suggestion']['category']);
        $this->assertSame(0.9, $normalized['taxonomy_suggestion']['confidence']);
        $this->assertContains('cà phê', $normalized['taxonomy_suggestion']['tags']);

        $dna['taxonomy_suggestion']['action'] = 'weird';
        $normalized = app(VisualStyleDnaDefinition::class)->validate($dna);
        $this->assertSame('propose_new', $normalized['taxonomy_suggestion']['action']);
    }
}
