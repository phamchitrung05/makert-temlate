<?php

namespace App\Services\Ai\Content;

use App\Exceptions\AiImportException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate canonical field AI trước khi merge dữ liệu nguồn.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), validate(), instructions().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : output model và output groups đã được chọn.
 * - OUTPUT: field đúng loại/không rỗng hoặc lỗi rõ ràng; không gọi HTTP/DB.
 * =====================================================================
 */
final class AiOutputValidator
{
    /**
     * =====================================================================
     * Input: sanitizer tùy chọn.
     * Output: validator dùng chung allowlist; không có side effect.
     * =====================================================================
     */
    public function __construct(private readonly ?AiContentSanitizer $sanitizer = null) {}

    /**
     * =====================================================================
     * Input: payload, selected groups và tùy chọn sanitize HTML.
     * Output: canonical field; lỗi type/missing/empty không bị fallback che.
     * =====================================================================
     */
    public function validate(array $payload, ?array $groups = null, bool $sanitizeContent = false): array
    {
        $definitions = (array) config('ai-agent.output_definitions', []);
        $selected = $groups === null ? array_keys($definitions) : array_values(array_unique($groups));
        if (array_diff($selected, array_keys($definitions)) !== []) {
            throw new AiImportException('Nhóm đầu ra AI chưa được hỗ trợ.', 'AI_PROVIDER_SCHEMA');
        }
        foreach ((array) config('ai-agent.output_aliases', []) as $alias => $canonical) {
            if (! array_key_exists($canonical, $payload) && array_key_exists($alias, $payload)) {
                $payload[$canonical] = $payload[$alias];
            }
        }
        $requiredGroups = $groups === null ? (array) config('ai-agent.legacy_required_outputs', ['title', 'content']) : $selected;
        $result = [];
        $errors = [];
        foreach ($selected as $group) {
            $definition = $definitions[$group];
            $required = in_array($group, $requiredGroups, true) ? (array) ($definition['required'] ?? []) : [];
            $validCount = 0;
            $hasEmpty = false;
            $hasNull = false;
            foreach ((array) ($definition['rules'] ?? []) as $field => $rules) {
                if (! array_key_exists($field, $payload) || $payload[$field] === null) {
                    $hasNull = $hasNull || array_key_exists($field, $payload);
                    if (in_array($field, $required, true)) {
                        $errors[] = ['group' => $group, 'field' => $field, 'reason' => array_key_exists($field, $payload) ? 'null' : 'missing'];
                    }

                    continue;
                }
                $value = $payload[$field];
                $typeValid = match ($rules['type'] ?? '') {
                    'string' => is_string($value),
                    'boolean' => is_bool($value),
                    'integer_ids' => is_array($value) && array_is_list($value),
                    default => false,
                };
                if (! $typeValid) {
                    $errors[] = ['group' => $group, 'field' => $field, 'reason' => 'invalid_type'];

                    continue;
                }
                if (is_array($value)) {
                    if (array_filter($value, static fn (mixed $id): bool => ! is_int($id) || $id <= 0) !== []) {
                        $errors[] = ['group' => $group, 'field' => $field, 'reason' => 'invalid_value'];

                        continue;
                    }
                    $value = array_values(array_unique($value));
                }
                if (is_string($value)) {
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        $errors[] = ['group' => $group, 'field' => $field, 'reason' => 'invalid_value'];

                        continue;
                    }
                    if (isset($rules['max']) && mb_strlen($value) > (int) $rules['max']) {
                        $errors[] = ['group' => $group, 'field' => $field, 'reason' => 'too_long'];

                        continue;
                    }
                    if (($rules['html'] ?? false) && $sanitizeContent) {
                        $value = ($this->sanitizer ?? new AiContentSanitizer)->sanitize($value);
                    }
                    $visible = ($rules['html'] ?? false) && $sanitizeContent ? strip_tags($value) : $value;
                    $visible = html_entity_decode($visible, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $visible) === '') {
                        $hasEmpty = true;
                        if (in_array($field, $required, true)) {
                            $errors[] = ['group' => $group, 'field' => $field, 'reason' => 'empty'];
                        }

                        continue;
                    }
                    if (($rules['url'] ?? false)
                        && (! filter_var($value, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                        $errors[] = ['group' => $group, 'field' => $field, 'reason' => 'invalid_value'];

                        continue;
                    }
                }
                $result[$field] = $value;
                $validCount++;
            }
            if ($groups !== null && $validCount < (int) ($definition['minimum'] ?? 0)
                && ! array_filter($errors, static fn (array $error): bool => $error['group'] === $group)) {
                $errors[] = ['group' => $group, 'field' => null, 'reason' => $hasEmpty ? 'empty' : ($hasNull ? 'null' : 'missing')];
            }
        }
        if ($errors !== []) {
            $reasons = array_column($errors, 'reason');
            $code = array_intersect($reasons, ['invalid_type', 'invalid_value', 'too_long']) !== [] ? 'AI_PROVIDER_SCHEMA'
                : (array_intersect($reasons, ['missing', 'null']) !== [] ? 'AI_PROVIDER_MISSING_FIELDS' : 'AI_PROVIDER_EMPTY_CONTENT');
            $schemaErrors = array_values(array_filter($errors, static fn (array $error): bool => in_array($error['reason'], ['invalid_type', 'invalid_value', 'too_long'], true)));
            $first = $schemaErrors[0] ?? $errors[0];
            $message = match ($code) {
                'AI_PROVIDER_SCHEMA' => ($first['reason'] === 'invalid_type' ? 'AI provider trả sai kiểu dữ liệu cho ' : 'AI provider trả giá trị không hợp lệ cho ').($first['field'] ?? $first['group']).'.',
                'AI_PROVIDER_MISSING_FIELDS' => 'AI provider chưa trả đủ trường được yêu cầu.',
                default => 'AI provider trả nội dung rỗng cho hạng mục được yêu cầu.',
            };
            throw new AiImportException($message, $code, diagnostics: [
                'stage' => $sanitizeContent ? 'sanitize' : 'validate',
                'requested_groups' => $groups ?? $requiredGroups,
                'returned_fields' => array_keys($payload), 'validation_errors' => $errors,
            ]);
        }
        if (array_key_exists('content_html', $result)) {
            $result['content'] = $result['content_html'];
        }

        return $result;
    }

    /**
     * =====================================================================
     * Input: selected groups hoặc nhóm bắt buộc legacy.
     * Output: hướng dẫn field lấy cùng contract với validate(); không side effect.
     * =====================================================================
     */
    public function instructions(?array $groups = null): string
    {
        $definitions = (array) config('ai-agent.output_definitions', []);
        $requiredGroups = $groups ?? (array) config('ai-agent.legacy_required_outputs', ['title', 'content']);
        $instructions = [];
        foreach ($requiredGroups as $group) {
            $definition = $definitions[$group] ?? [];
            if (! empty($definition['required'])) {
                $instructions[] = 'Required non-empty fields: '.implode(', ', $definition['required']).'.';
            }
            if (($definition['minimum'] ?? 0) > 0) {
                $instructions[] = 'Return at least '.$definition['minimum'].' valid field from '.implode(', ', array_keys($definition['rules'] ?? [])).'. Empty ID arrays and boolean false are valid values.';
            }
        }

        return implode(' ', $instructions);
    }
}
