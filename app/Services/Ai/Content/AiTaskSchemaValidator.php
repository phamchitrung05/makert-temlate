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
     * Input: giá trị và tên kiểu dữ liệu.
     * Output: boolean kiểu hợp lệ; không có side effect.
     * =====================================================================
     */
    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * =====================================================================
     * Input: giá trị, schema, path và danh sách lỗi truyền tham chiếu.
     * Output: thêm lỗi type/required/enum/pattern/length/extra; không lưu nội dung nguồn.
     * =====================================================================
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        $rawType = $schema['type'] ?? null;
        $types = is_array($rawType) ? $rawType : (is_string($rawType) ? [$rawType] : []);
        if (! empty($schema['nullable']) && ! in_array('null', $types, true)) {
            $types[] = 'null';
        }

        $valid = false;
        foreach ($types as $expectedType) {
            if ($this->matchesType($value, $expectedType)) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            $errors[] = ['field' => $path, 'reason' => 'invalid_type'];

            return;
        }

        if ($value === null) {
            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = ['field' => $path, 'reason' => 'invalid_value'];
        }

        if (is_string($value)) {
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? 200000)) {
                $errors[] = ['field' => $path, 'reason' => 'invalid_length'];
            }
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~u', $value) !== 1) {
                $errors[] = ['field' => $path, 'reason' => 'invalid_value'];
            }
        }

        if (is_int($value) || is_float($value)) {
            if ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF)) {
                $errors[] = ['field' => $path, 'reason' => 'invalid_value'];
            }
        }

        if (in_array('object', $types, true) && is_array($value) && ($value === [] || ! array_is_list($value))) {
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

        if (in_array('array', $types, true) && is_array($value) && array_is_list($value)) {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 1000)) {
                $errors[] = ['field' => $path, 'reason' => 'invalid_length'];
            }
            foreach ($value as $index => $child) {
                $this->check($child, $schema['items'] ?? [], $path.'['.$index.']', $errors);
            }
        }
    }
}
