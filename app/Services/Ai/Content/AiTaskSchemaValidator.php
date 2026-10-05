<?php

namespace App\Services\Ai\Content;

use App\Exceptions\AiImportException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm JSON Schema tối thiểu cho các output intermediate AI.
 * =====================================================================
 * Không phụ thuộc schema Post; chặn kiểu sai, field thiếu, ref sai cấu trúc.
 * CÁC HÀM/METHOD TRONG FILE: validate(), check().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : output parse từ JSON và schema object/array/scalar.
 * - OUTPUT: output hợp lệ hoặc AiImportException; không có side effect.
 * =====================================================================
 */
final class AiTaskSchemaValidator
{
    /**
     * =====================================================================
     * Input: output object và JSON Schema của nhiệm vụ.
     * Output: mảng hợp lệ; ném AI_PROVIDER_SCHEMA với paths an toàn nếu sai.
     * =====================================================================
     */
    public function validate(array $output, array $schema): array
    {
        $errors = [];
        $this->check($output, $schema, '$', $errors);
        if ($errors !== []) {
            $errors = array_map(fn (array $error): array => ['group' => 'task'] + $error, $errors);
            throw new AiImportException('AI trả dữ liệu không đúng schema của nhiệm vụ.', 'AI_PROVIDER_SCHEMA', diagnostics: [
                'stage' => 'validate', 'validation_errors' => array_slice($errors, 0, 20),
            ]);
        }

        return $output;
    }

    /**
     * =====================================================================
     * Input: giá trị, schema, path và danh sách lỗi truyền tham chiếu.
     * Output: thêm lỗi type/required/enum/length/extra; không lưu nội dung nguồn.
     * =====================================================================
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
        if (! $valid) {
            $errors[] = ['field' => $path, 'reason' => 'invalid_type'];

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = ['field' => $path, 'reason' => 'invalid_value'];
        }
        if ($type === 'string' && (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? 200000))) {
            $errors[] = ['field' => $path, 'reason' => 'invalid_length'];
        }
        if (in_array($type, ['number', 'integer'], true) && ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF))) {
            $errors[] = ['field' => $path, 'reason' => 'invalid_value'];
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $value)) {
                    $errors[] = ['field' => $path.'.'.$field, 'reason' => 'missing'];
                }
            }
            foreach ($value as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->check($child, $schema['properties'][$field], $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = ['field' => $path.'.'.$field, 'reason' => 'unexpected_field'];
                }
            }
        }
        if ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 1000)) {
                $errors[] = ['field' => $path, 'reason' => 'invalid_length'];
            }
            foreach ($value as $index => $child) {
                $this->check($child, $schema['items'] ?? [], $path.'['.$index.']', $errors);
            }
        }
    }
}
