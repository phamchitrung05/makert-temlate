<?php

namespace App\Services\Ai\WritingProfiles;

use App\Exceptions\AiImportException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Contract riêng cho phân tích văn phong và kiểm tra bằng chứng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: schema(), validate(), validateRules(), validateEvidence(), normalize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : output analysis hoặc rules/evidence người dùng duyệt.
 * - OUTPUT: dữ liệu đúng kiểu, bounded; excerpt phải xuất hiện trong bài tham khảo.
 * - SIDE EFFECT: không lưu DB/gọi AI; lỗi validation có message an toàn.
 * =====================================================================
 */
final class WritingProfileDefinition
{
    public const PROMPT_VERSION = '1.0';

    public const SCHEMA_VERSION = 'writing-profile.analysis.v1';

    public const RULE_KEYS = [
        'tone', 'pronouns', 'emotion', 'opening', 'sentence_rhythm', 'paragraph_rhythm',
        'transitions', 'vocabulary', 'technical_terms', 'structure_patterns', 'headings',
        'bullets', 'examples', 'ending', 'avoid', 'uncertainties',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo JSON Schema riêng cho nhiệm vụ phân tích bài mẫu.
     * =====================================================================
     * Input: không có. Output: object rules/evidence/hướng dẫn, không có field Post.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    public function schema(): array
    {
        $ruleProperties = [];
        foreach (self::RULE_KEYS as $key) {
            $ruleProperties[$key] = in_array($key, ['structure_patterns', 'avoid', 'uncertainties'], true)
                ? ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20]
                : ['type' => 'string', 'maxLength' => 2000];
        }

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['summary', 'rules', 'evidence', 'style_instructions'],
            'properties' => [
                'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'rules' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $ruleProperties, 'required' => ['tone', 'opening', 'sentence_rhythm']],
                'evidence' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 20,
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['feature', 'excerpt', 'explanation'], 'properties' => [
                        'feature' => ['type' => 'string', 'enum' => self::RULE_KEYS],
                        'excerpt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                        'explanation' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                    ]],
                ],
                'style_instructions' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10000],
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate output AI và chứng minh excerpt có trong nguồn.
     * =====================================================================
     * Input: JSON output đã parse và reference text. Output: result allowlisted.
     * Side effect: AiImportException khi schema/bằng chứng không đúng; không fallback.
     * =====================================================================
     */
    public function validate(array $output, string $reference): array
    {
        try {
            $validated = Validator::make($output, [
                'summary' => ['required', 'string', 'max:2000'],
                'rules' => ['required', 'array', 'min:1', 'max:16'],
                'rules.tone' => ['required', 'string', 'max:2000'],
                'rules.opening' => ['required', 'string', 'max:2000'],
                'rules.sentence_rhythm' => ['required', 'string', 'max:2000'],
                'evidence' => ['required', 'array', 'min:1', 'max:20'],
                'style_instructions' => ['required', 'string', 'max:10000'],
            ])->validate();
            $this->validateRules($validated['rules']);
            $this->validateEvidence($validated['evidence'], $reference);
        } catch (ValidationException) {
            throw new AiImportException('Phân tích văn phong sai cấu trúc hoặc trích đoạn không có trong bài mẫu.', 'AI_WRITING_PROFILE_INVALID_OUTPUT');
        }

        return array_intersect_key($validated, array_flip(['summary', 'rules', 'evidence', 'style_instructions']));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn key/kiểu/độ dài rules dùng cho prompt.
     * =====================================================================
     * Input: rules đã nhận. Output: void hoặc ValidationException theo field.
     * Side effect: không ghi DB; không chấp nhận JSON tùy ý lồng nhiều tầng.
     * =====================================================================
     */
    public function validateRules(array $rules): void
    {
        foreach ($rules as $key => $value) {
            if (! in_array($key, self::RULE_KEYS, true)
                || (! is_string($value) && ! is_array($value))
                || (is_string($value) && (trim($value) === '' || mb_strlen($value) > 2000))
                || (is_array($value) && (! array_is_list($value) || count($value) > 20))) {
                throw ValidationException::withMessages(['rules_json' => 'Rules phải dùng field văn phong được cho phép và giá trị chuỗi hoặc danh sách chuỗi.']);
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 2000) {
                        throw ValidationException::withMessages(['rules_json' => 'Mỗi quy tắc phải là chuỗi không rỗng, tối đa 2000 ký tự.']);
                    }
                }
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đối chiếu excerpt với nguồn, chỉ chuẩn hóa khoảng trắng/HTML entities.
     * =====================================================================
     * Input: evidence và reference. Output: void hoặc ValidationException.
     * Side effect: hàm thuần; không coi confidence AI là bằng chứng.
     * =====================================================================
     */
    public function validateEvidence(array $evidence, string $reference): void
    {
        Validator::make(['evidence_json' => $evidence], [
            'evidence_json' => ['array', 'max:20'],
            'evidence_json.*' => ['required', 'array:feature,excerpt,explanation'],
            'evidence_json.*.feature' => ['required', 'string', 'in:'.implode(',', self::RULE_KEYS)],
            'evidence_json.*.excerpt' => ['required', 'string', 'max:300'],
            'evidence_json.*.explanation' => ['required', 'string', 'max:1000'],
        ])->validate();
        $source = $this->normalize($reference);
        foreach ($evidence as $item) {
            $excerpt = $this->normalize($item['excerpt']);
            if ($excerpt === '' || ! str_contains($source, $excerpt)) {
                throw ValidationException::withMessages(['evidence_json' => 'Trích đoạn phải có thật trong bài tham khảo.']);
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa khoảng trắng khi so sánh bằng chứng.
     * =====================================================================
     * Input: chuỗi text/HTML. Output: văn bản không đổi từ/ngữ nghĩa/chữ hoa.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
