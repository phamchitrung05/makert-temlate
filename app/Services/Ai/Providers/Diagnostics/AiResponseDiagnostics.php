<?php

namespace App\Services\Ai\Providers\Diagnostics;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giới hạn/redact diagnostics trước khi lưu hoặc trả qua API.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: sanitize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata provider/schema/quality không tin cậy.
 * - OUTPUT: allowlist bounded, không giữ key/header/prompt/raw/source excerpts.
 * =====================================================================
 */
final class AiResponseDiagnostics
{
    /**
     * =====================================================================
     * Input: diagnostics có thể chứa dữ liệu nhạy cảm hoặc key lạ.
     * Output: chỉ metadata/task errors an toàn; không ghi DB hoặc gọi HTTP.
     * =====================================================================
     */
    public static function sanitize(array $metadata): array
    {
        $result = [];
        foreach (['response_id' => 128, 'reported_model' => 191] as $key => $limit) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && preg_match('/\A[A-Za-z0-9._:\/+-]+\z/D', $value)
                && ! preg_match('/\A(?:sk[-_]|AIza|eyJ)/', $value)) {
                $result[$key] = mb_substr($value, 0, $limit);
            }
        }
        if (in_array($metadata['task'] ?? null, ['article.analysis-plan', 'article.writer', 'article.editor', 'writing-profile.analysis'], true)) {
            $result['task'] = $metadata['task'];
        }
        if (is_string($metadata['prompt_version'] ?? null) && preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,3}){0,2}\z/D', $metadata['prompt_version'])) {
            $result['prompt_version'] = $metadata['prompt_version'];
        }
        foreach (['provider', 'model'] as $key) {
            if (is_string($metadata[$key] ?? null) && preg_match('/\A[A-Za-z0-9._:\/+-]{1,191}\z/D', $metadata[$key])
                && ! preg_match('/\A(?:sk[-_]|AIza|eyJ)/', $metadata[$key])) {
                $result[$key] = $metadata[$key];
            }
        }
        if (is_int($metadata['latency_ms'] ?? null) && $metadata['latency_ms'] >= 0 && $metadata['latency_ms'] <= 3600000) {
            $result['latency_ms'] = $metadata['latency_ms'];
        }
        $errorCodes = ['AI_PROVIDER_NOT_CONFIGURED', 'AI_PROVIDER_TIMEOUT', 'AI_PROVIDER_REFUSAL', 'AI_PROVIDER_TOOL_OUTPUT',
            'AI_PROVIDER_INCOMPLETE', 'AI_PROVIDER_EMPTY_CONTENT', 'AI_PROVIDER_INVALID_JSON', 'AI_PROVIDER_SCHEMA', 'AI_PROVIDER_MISSING_FIELDS',
            'AI_SOURCE_REFERENCE', 'AI_QUALITY_EXACT_COPY', 'AI_QUALITY_LANGUAGE', 'AI_QUALITY_GROUNDING', 'AI_QUALITY_EDITOR',
            'CANCELLED', 'EXPIRED', 'RUN_NOT_ACTIVE', 'SOURCE_TOO_LARGE', 'SOURCE_EMPTY', 'AI_LEGACY_TAXONOMY', 'AI_IMPORT_FAILED', 'AI_ACTOR_NOT_FOUND', 'AI_MEDIA_REFERENCE', 'AI_INPUT_BUDGET'];
        if (in_array($metadata['error_code'] ?? null, $errorCodes, true)) {
            $result['error_code'] = $metadata['error_code'];
        }
        $finishReason = $metadata['finish_reason'] ?? null;
        if (is_string($finishReason) && in_array($finishReason, [
            'stop', 'length', 'tool_calls', 'function_call', 'content_filter',
            'STOP', 'MAX_TOKENS', 'SAFETY', 'RECITATION', 'LANGUAGE', 'OTHER',
            'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL',
            'UNEXPECTED_TOOL_CALL', 'FINISH_REASON_UNSPECIFIED',
        ], true)) {
            $result['finish_reason'] = $finishReason;
        }
        $stage = $metadata['stage'] ?? null;
        if (is_string($stage) && in_array($stage, [
            'request', 'transport', 'envelope', 'parse', 'validate', 'sanitize',
            'ready', 'source', 'skipped', 'deterministic',
        ], true)) {
            $result['stage'] = $stage;
        }
        if (is_string($metadata['schema_version'] ?? null)
            && array_key_exists($metadata['schema_version'], (array) config('ai-agent.schemas', []))) {
            $result['schema_version'] = $metadata['schema_version'];
        }
        $groups = array_keys((array) config('ai-agent.output_definitions', []));
        $fields = [];
        foreach ((array) config('ai-agent.schemas', []) as $schema) {
            $fields = array_merge($fields, (array) ($schema['fields'] ?? []));
        }
        foreach (['requested_groups' => $groups, 'returned_fields' => $fields] as $key => $allowed) {
            if (is_array($metadata[$key] ?? null)) {
                $values = array_filter($metadata[$key], static fn (mixed $value): bool => is_string($value));
                $result[$key] = array_slice(array_values(array_unique(array_intersect($values, $allowed))), 0, 64);
            }
        }
        if (is_array($metadata['usage'] ?? null)) {
            $usage = [];
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens'] as $key) {
                $value = $metadata['usage'][$key] ?? null;
                if (is_int($value) && $value >= 0 && $value <= 1000000000000) {
                    $usage[$key] = $value;
                }
            }
            if ($usage !== []) {
                $result['usage'] = $usage;
            }
        }
        if (is_array($metadata['validation_errors'] ?? null)) {
            $errors = [];
            $reasons = ['missing', 'null', 'empty', 'invalid_type', 'invalid_value', 'too_long', 'invalid_length', 'unexpected_field',
                'duplicate_fact_id', 'unknown_source_block', 'evidence_not_in_source', 'unknown_fact_reference', 'missing_important_fact_reference',
                'exact_copy', 'dominant_language_mismatch', 'source_code_changed', 'source_link_missing', 'source_link_unknown', 'important_number_missing'];
            foreach (array_slice($metadata['validation_errors'], 0, 64) as $error) {
                if (! is_array($error) || ! in_array($error['group'] ?? null, array_merge($groups, ['task']), true)
                    || ! in_array($error['reason'] ?? null, $reasons, true)) {
                    continue;
                }
                $field = $error['field'] ?? null;
                if ($error['group'] === 'task') {
                    // Dynamic JSON keys may contain secrets: expose a fixed path label only.
                    $field = 'task_output';
                }
                if ($field !== null && $field !== 'task_output' && ! in_array($field, $fields, true)) {
                    continue;
                }
                $errors[] = ['group' => $error['group'], 'field' => $field, 'reason' => $error['reason']];
            }
            $result['validation_errors'] = $errors;
        }

        return $result;
    }
}
