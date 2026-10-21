<?php

namespace App\Services\Ai\VisualStyle;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Contract schema + enum cho Style DNA của Visual Style Engine.
 * =====================================================================
 * Definition là nguồn chuẩn khi AI phân tích ảnh mẫu và khi admin lưu DNA đã
 * chỉnh: schema() sinh JSON Schema (enum chuẩn hóa) gửi cho provider, còn
 * validate() chuẩn hóa output về đúng hình dạng lưu trong ai_image_profiles.
 * Enum/defaults đọc từ config/ai/visual_style_dna.php — sửa enum ở config,
 * không sửa class.
 * CÁC HÀM/METHOD TRONG FILE:
 * - schema(): JSON Schema object cho provider (required + enum).
 * - validate(): chuẩn hóa DNA, throw ValidationException khi sai required/enum.
 * - validateSection(): validate từng nhóm field theo enum config.
 * - validateOverride(): validate phần override của shot recipe (composition/camera).
 * - enumFields(): liệt kê field có enum của một section.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : output Vision model hoặc DNA admin chỉnh tay.
 * - OUTPUT: mảng DNA đã chuẩn hóa, đủ section mặc định, enum hợp lệ.
 * - SIDE EFFECT: không lưu DB/gọi AI; ValidationException kèm message theo field.
 * =====================================================================
 */
final class VisualStyleDnaDefinition
{
    public const SCHEMA_VERSION = '1.0';

    public const PROMPT_VERSION = '1.0';

    /**
     * Các section bắt buộc trong DNA v1; product_adaptation/confidence/shot_variations
     * là tùy chọn nhưng vẫn được chuẩn hóa khi có.
     */
    private const REQUIRED_SECTIONS = ['composition', 'camera', 'lighting', 'environment', 'color_mood'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh JSON Schema cho provider trả DNA + suggested_shots.
     * =====================================================================
     * Input: không có. Output: schema object với properties theo enum config;
     * suggested_shots nằm cùng response để analyzer tách sang bảng shots.
     * Side effect: hàm thuần; không ghi DB.
     * =====================================================================
     */
    public function schema(): array
    {
        $section = fn (string $name): array => $this->sectionSchema($name);
        $shotSchema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['code', 'name'],
            'properties' => [
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'description' => ['type' => 'string', 'maxLength' => 2000],
                'composition_override' => $this->overrideSchema('composition'),
                'camera_override' => $this->overrideSchema('camera'),
                'prompt_suffix' => ['type' => 'string', 'maxLength' => 2000],
                'suggested_aspect_ratio' => ['type' => 'string', 'maxLength' => 16],
            ],
        ];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['summary', 'composition', 'camera', 'lighting', 'environment', 'color_mood'],
            'properties' => [
                'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'identity' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'name' => ['type' => 'string', 'maxLength' => 160],
                        'slug' => ['type' => 'string', 'maxLength' => 160],
                        'category' => ['type' => 'string', 'maxLength' => 80],
                        'description' => ['type' => 'string', 'maxLength' => 2000],
                        'keywords' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 60], 'maxItems' => 12],
                    ],
                ],
                'composition' => $section('composition'),
                'camera' => $section('camera'),
                'lighting' => $section('lighting'),
                'environment' => $section('environment'),
                'color_mood' => $section('color_mood'),
                'subject_rules' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(array_keys((array) config('ai.visual_style_dna.defaults.subject_rules')), ['type' => 'boolean'])],
                'style_constraints' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(array_keys((array) config('ai.visual_style_dna.defaults.style_constraints')), ['type' => 'boolean'])],
                'product_adaptation' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'supported_categories' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 80], 'maxItems' => 20],
                        'adaptation_rules' => ['type' => 'object'],
                    ],
                ],
                'confidence' => ['type' => 'object', 'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]],
                'shot_variations' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 200], 'maxItems' => 12],
                'suggested_shots' => ['type' => 'array', 'items' => $shotSchema, 'maxItems' => 8],
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa và kiểm tra DNA trước khi lưu vào ai_image_profiles.
     * =====================================================================
     * Input: output AI hoặc payload admin (đã parse thành array).
     * Output: DNA đủ section, enum đúng, defaults boolean được bù khi thiếu;
     * suggested_shots được giữ nguyên để analyzer tách sang bảng shots.
     * Side effect: ValidationException khi thiếu section required hoặc enum sai;
     * không gọi provider, không ghi DB.
     * =====================================================================
     */
    public function validate(array $dna): array
    {
        $validator = Validator::make($dna, [
            'summary' => ['sometimes', 'string', 'max:2000'],
            'identity' => ['sometimes', 'array'],
            'composition' => ['required', 'array'],
            'camera' => ['required', 'array'],
            'lighting' => ['required', 'array'],
            'environment' => ['required', 'array'],
            'color_mood' => ['required', 'array'],
            'subject_rules' => ['sometimes', 'array'],
            'style_constraints' => ['sometimes', 'array'],
            'product_adaptation' => ['sometimes', 'array'],
            'confidence' => ['sometimes', 'array'],
            'shot_variations' => ['sometimes', 'array', 'max:12'],
            'suggested_shots' => ['sometimes', 'array', 'max:8'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'identity' => $this->normalizeIdentity((array) ($dna['identity'] ?? [])),
            'composition' => $this->validateSection('composition', (array) $dna['composition']),
            'camera' => $this->validateSection('camera', (array) $dna['camera']),
            'lighting' => $this->validateSection('lighting', (array) $dna['lighting']),
            'environment' => $this->validateSection('environment', (array) $dna['environment']),
            'color_mood' => $this->validateSection('color_mood', (array) $dna['color_mood']),
            'subject_rules' => $this->normalizeBooleans('subject_rules', (array) ($dna['subject_rules'] ?? [])),
            'style_constraints' => $this->normalizeBooleans('style_constraints', (array) ($dna['style_constraints'] ?? [])),
            'product_adaptation' => $this->normalizeProductAdaptation((array) ($dna['product_adaptation'] ?? [])),
            'confidence' => $this->normalizeConfidence((array) ($dna['confidence'] ?? [])),
            'shot_variations' => $this->normalizeStringList($dna['shot_variations'] ?? [], 12, 200),
        ];
        if (isset($dna['suggested_shots'])) {
            $normalized['suggested_shots'] = $this->normalizeSuggestedShots((array) $dna['suggested_shots']);
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate phần override của shot recipe theo enum section.
     * =====================================================================
     * Input: tên section (composition|camera) và mảng override từ request/admin.
     * Output: override chỉ giữ field hợp lệ và enum đúng; key lạ bị reject.
     * Side effect: ValidationException khi field không thuộc schema hoặc enum sai.
     * =====================================================================
     */
    public function validateOverride(string $section, ?array $override): ?array
    {
        if ($override === null) {
            return null;
        }
        if (! in_array($section, ['composition', 'camera'], true)) {
            throw ValidationException::withMessages(['override' => 'Shot recipe chỉ được override composition hoặc camera.']);
        }

        return $this->validateSection($section, $override, partial: true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh schema cho một section DNA dựa trên enum config.
     * =====================================================================
     * Input: tên section. Output: object schema với required toàn bộ enum field
     * và property text cho field không có enum.
     * Side effect: hàm thuần; đọc config mỗi lần gọi.
     * =====================================================================
     */
    private function sectionSchema(string $section): array
    {
        $enums = (array) config("ai.visual_style_dna.enums.{$section}", []);
        $properties = [];
        foreach ($enums as $field => $values) {
            $properties[$field] = ['type' => 'string', 'enum' => $values];
        }
        foreach ($this->extraFields($section) as $field => $definition) {
            $properties[$field] = $definition;
        }

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Schema cho override recipe — cùng field section nhưng tất cả tùy chọn.
     * =====================================================================
     * Input: tên section. Output: object schema không required.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function overrideSchema(string $section): array
    {
        return $this->sectionSchema($section);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate một section DNA; chế độ partial cho phép subset field.
     * =====================================================================
     * Input: tên section, mảng giá trị, partial=true cho shot override.
     * Output: mảng chỉ giữ field hợp lệ; khi không partial thiếu field enum thì lỗi.
     * Side effect: ValidationException theo field khi enum sai hoặc key lạ.
     * =====================================================================
     */
    private function validateSection(string $section, array $values, bool $partial = false): array
    {
        $enums = (array) config("ai.visual_style_dna.enums.{$section}", []);
        $extra = array_keys($this->extraFields($section));
        $allowed = array_merge(array_keys($enums), $extra);
        $errors = [];
        $normalized = [];

        foreach ($values as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                $errors["{$section}.{$field}"] = "Field {$section}.{$field} không thuộc schema DNA.";
                continue;
            }
            if (isset($enums[$field])) {
                if (! is_string($value) || ! in_array($value, $enums[$field], true)) {
                    $errors["{$section}.{$field}"] = "Giá trị {$section}.{$field} phải thuộc enum chuẩn.";
                    continue;
                }
                $normalized[$field] = $value;
            } else {
                $normalized[$field] = $this->normalizeScalarField($section, $field, $value, $errors);
            }
        }
        if (! $partial) {
            foreach ($enums as $field => $enumValues) {
                if (! array_key_exists($field, $normalized) && in_array($field, $this->requiredEnumFields($section), true)) {
                    $errors["{$section}.{$field}"] = "Thiếu field bắt buộc {$section}.{$field}.";
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê field enum bắt buộc của section (enum field luôn bắt buộc).
     * =====================================================================
     * Input: tên section. Output: danh sách field enum phải có khi validate DNA đầy đủ.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function requiredEnumFields(string $section): array
    {
        return array_keys((array) config("ai.visual_style_dna.enums.{$section}", []));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Field không phải enum của từng section (số, boolean, danh sách, text).
     * =====================================================================
     * Input: tên section. Output: map field → JSON schema property.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function extraFields(string $section): array
    {
        return match ($section) {
            'composition' => [
                'subject_scale' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'depth_layers' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 60], 'maxItems' => 6],
            ],
            'environment' => [
                'background_detail' => ['type' => 'string', 'maxLength' => 500],
                'props' => ['type' => 'string', 'maxLength' => 500],
                'environment_adaptation' => ['type' => 'boolean'],
            ],
            'color_mood' => [
                'palette' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 40], 'maxItems' => 8],
                'mood' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 60], 'maxItems' => 8],
            ],
            default => [],
        };
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa field không enum (number/boolean/list/text) trong section.
     * =====================================================================
     * Input: section, field, giá trị thô và mảng errors gom theo field.
     * Output: giá trị đã ép kiểu hợp lệ; giá trị sai kiểu bị ghi lỗi và bỏ qua.
     * Side effect: thêm entry vào $errors by-reference.
     * =====================================================================
     */
    private function normalizeScalarField(string $section, string $field, mixed $value, array &$errors): mixed
    {
        $key = "{$section}.{$field}";
        if ($field === 'subject_scale') {
            if (! is_numeric($value) || $value < 0 || $value > 1) {
                $errors[$key] = 'subject_scale phải là số trong khoảng 0..1.';

                return null;
            }

            return round((float) $value, 3);
        }
        if ($field === 'environment_adaptation') {
            return (bool) $value;
        }
        if (in_array($field, ['depth_layers', 'palette', 'mood'], true)) {
            $max = (int) (data_get(config('ai.visual_style_dna.lists'), "{$section}.{$field}.max", 8));
            $maxLength = (int) (data_get(config('ai.visual_style_dna.lists'), "{$section}.{$field}.max_length", 60));

            return $this->normalizeStringList(is_array($value) ? $value : [], $max ?: 8, $maxLength ?: 60);
        }
        if (! is_string($value) || mb_strlen($value) > 500) {
            $errors[$key] = "Field {$key} phải là chuỗi tối đa 500 ký tự.";

            return null;
        }

        return trim($value);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa section identity (thông tin mô tả, không enum).
     * =====================================================================
     * Input: identity thô. Output: identity giới hạn độ dài + danh sách keywords.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function normalizeIdentity(array $identity): array
    {
        return [
            'name' => isset($identity['name']) ? mb_substr((string) $identity['name'], 0, 160) : '',
            'slug' => isset($identity['slug']) ? mb_substr((string) $identity['slug'], 0, 160) : '',
            'category' => isset($identity['category']) ? mb_substr((string) $identity['category'], 0, 80) : '',
            'description' => isset($identity['description']) ? mb_substr((string) $identity['description'], 0, 2000) : '',
            'keywords' => $this->normalizeStringList($identity['keywords'] ?? [], 12, 60),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bù defaults boolean cho subject_rules/style_constraints.
     * =====================================================================
     * Input: tên group defaults và giá trị thô. Output: đủ key boolean theo config;
     * key lạ bị loại, giá trị thiếu lấy default.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function normalizeBooleans(string $group, array $values): array
    {
        $defaults = (array) config("ai.visual_style_dna.defaults.{$group}", []);
        $normalized = [];
        foreach ($defaults as $key => $default) {
            $normalized[$key] = array_key_exists($key, $values) ? (bool) $values[$key] : (bool) $default;
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa product_adaptation (categories + rules tự do có giới hạn).
     * =====================================================================
     * Input: mảng thô. Output: supported_categories bounded, adaptation_rules
     * chỉ giữ map string→string tối đa 12 entry.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function normalizeProductAdaptation(array $values): array
    {
        $rules = [];
        foreach ((array) ($values['adaptation_rules'] ?? []) as $key => $rule) {
            if (count($rules) >= 12) {
                break;
            }
            if (is_string($key) && is_string($rule) && mb_strlen($key) <= 80 && mb_strlen($rule) <= 500) {
                $rules[$key] = $rule;
            }
        }

        return [
            'supported_categories' => $this->normalizeStringList($values['supported_categories'] ?? [], 20, 80),
            'adaptation_rules' => $rules,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa confidence map field-path → điểm 0..1.
     * =====================================================================
     * Input: mảng thô. Output: map string → float 0..1 tối đa 30 entry; giá trị
     * sai kiểu bị bỏ.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function normalizeConfidence(array $values): array
    {
        $normalized = [];
        foreach ($values as $path => $score) {
            if (count($normalized) >= 30) {
                break;
            }
            if (is_string($path) && mb_strlen($path) <= 120 && is_numeric($score)) {
                $normalized[$path] = max(0, min(1, round((float) $score, 3)));
            }
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa suggested_shots trả về từ Vision model.
     * =====================================================================
     * Input: danh sách thô. Output: tối đa 8 shot có code/name hợp lệ, override
     * đã qua validateOverride (partial) — shot sai override enum bị reject cùng lỗi.
     * Side effect: ValidationException khi code/name sai hoặc override enum lạ.
     * =====================================================================
     */
    private function normalizeSuggestedShots(array $shots): array
    {
        $normalized = [];
        $errors = [];
        foreach (array_slice($shots, 0, 8) as $index => $shot) {
            if (! is_array($shot)) {
                continue;
            }
            $code = isset($shot['code']) ? trim((string) $shot['code']) : '';
            $name = isset($shot['name']) ? trim((string) $shot['name']) : '';
            if ($code === '' || mb_strlen($code) > 60 || $name === '' || mb_strlen($name) > 160) {
                $errors["suggested_shots.{$index}"] = 'Mỗi suggested shot cần code (≤60) và name (≤160).';
                continue;
            }
            $normalized[] = [
                'code' => $code,
                'name' => $name,
                'description' => isset($shot['description']) ? mb_substr((string) $shot['description'], 0, 2000) : null,
                'composition_override' => $this->validateOverride('composition', isset($shot['composition_override']) && is_array($shot['composition_override']) ? $shot['composition_override'] : null),
                'camera_override' => $this->validateOverride('camera', isset($shot['camera_override']) && is_array($shot['camera_override']) ? $shot['camera_override'] : null),
                'prompt_suffix' => isset($shot['prompt_suffix']) ? mb_substr((string) $shot['prompt_suffix'], 0, 2000) : null,
                'suggested_aspect_ratio' => isset($shot['suggested_aspect_ratio']) ? mb_substr((string) $shot['suggested_aspect_ratio'], 0, 16) : null,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép danh sách về mảng chuỗi bounded, bỏ phần tử không phải string.
     * =====================================================================
     * Input: giá trị thô, số phần tử tối đa và độ dài tối đa mỗi chuỗi.
     * Output: list string đã trim, không rỗng, không vượt giới hạn.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function normalizeStringList(mixed $values, int $max, int $maxLength): array
    {
        if (! is_array($values)) {
            return [];
        }
        $normalized = [];
        foreach ($values as $value) {
            if (count($normalized) >= $max) {
                break;
            }
            if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= $maxLength) {
                $normalized[] = trim($value);
            }
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê field có enum của section (dùng cho FormRequest/shot service).
     * =====================================================================
     * Input: tên section. Output: map field → danh sách enum.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    public function enumFields(string $section): array
    {
        return (array) config("ai.visual_style_dna.enums.{$section}", []);
    }
}
