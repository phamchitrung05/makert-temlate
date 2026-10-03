<?php

namespace App\Services\Ai;

/** Bound and allowlist diagnostics before storing them or exposing validation errors. */
final class AiResponseDiagnostics
{
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
            foreach (array_slice($metadata['validation_errors'], 0, 64) as $error) {
                if (! is_array($error) || ! in_array($error['group'] ?? null, $groups, true)
                    || ! in_array($error['reason'] ?? null, ['missing', 'null', 'empty', 'invalid_type', 'invalid_value', 'too_long'], true)) {
                    continue;
                }
                $field = $error['field'] ?? null;
                if ($field !== null && ! in_array($field, $fields, true)) {
                    continue;
                }
                $errors[] = ['group' => $error['group'], 'field' => $field, 'reason' => $error['reason']];
            }
            $result['validation_errors'] = $errors;
        }

        return $result;
    }
}
