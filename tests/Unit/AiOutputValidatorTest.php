<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiResponseDiagnostics;
use Tests\TestCase;

final class AiOutputValidatorTest extends TestCase
{
    public function test_selected_groups_require_their_own_fields_without_requiring_unselected_title_or_content(): void
    {
        $validator = new AiOutputValidator;
        $this->assertSame(['excerpt' => 'Mô tả'], $validator->validate(['excerpt' => 'Mô tả', 'title' => ['ignored']], ['excerpt']));
        $this->assertSame([], $validator->validate(['title' => 'Ignored'], []));
        $this->assertSame(['robots_index' => false], $validator->validate(['robots_index' => false], ['seo']));
        $this->assertSame(['suggested_tag_ids' => []], $validator->validate(['suggested_tag_ids' => []], ['taxonomy']));
    }

    public function test_missing_null_and_blank_required_fields_have_distinct_safe_diagnostics(): void
    {
        foreach ([
            [[], 'AI_PROVIDER_MISSING_FIELDS', 'missing'],
            [['title' => null], 'AI_PROVIDER_MISSING_FIELDS', 'null'],
            [['title' => " \t\u{00A0}\u{200B}"], 'AI_PROVIDER_EMPTY_CONTENT', 'empty'],
        ] as [$payload, $code, $reason]) {
            $exception = $this->failure($payload, ['title']);
            $this->assertSame($code, $exception->errorCode);
            $this->assertFalse($exception->retryable);
            $this->assertSame([['group' => 'title', 'field' => 'title', 'reason' => $reason]], $exception->diagnostics['validation_errors']);
        }
    }

    public function test_partial_groups_require_one_valid_field_and_null_optional_fields_do_not_count(): void
    {
        $this->assertSame('AI_PROVIDER_MISSING_FIELDS', $this->failure(['seo_title' => null], ['seo'])->errorCode);
        $this->assertSame('AI_PROVIDER_EMPTY_CONTENT', $this->failure(['seo_title' => ' &nbsp; '], ['seo'])->errorCode);
        $this->assertSame('AI_PROVIDER_MISSING_FIELDS', $this->failure([], ['taxonomy'])->errorCode);
        $this->assertSame(['seo_title' => 'SEO'], (new AiOutputValidator)->validate(['seo_title' => 'SEO', 'seo_description' => null], ['seo']));
    }

    public function test_invalid_types_lengths_urls_and_taxonomy_values_are_not_coerced(): void
    {
        foreach ([
            [['title' => ['value' => 'bad']], ['title'], 'invalid_type'],
            [['title' => str_repeat('Á', 256)], ['title'], 'too_long'],
            [['robots_follow' => 'false'], ['seo'], 'invalid_type'],
            [['canonical_url' => 'javascript:alert(1)'], ['seo'], 'invalid_value'],
            [['suggested_tag_ids' => ['1']], ['taxonomy'], 'invalid_value'],
            [['suggested_tag_ids' => [0]], ['taxonomy'], 'invalid_value'],
            [['suggested_tag_ids' => ['value' => 1]], ['taxonomy'], 'invalid_type'],
        ] as [$payload, $groups, $reason]) {
            $exception = $this->failure($payload, $groups);
            $this->assertSame('AI_PROVIDER_SCHEMA', $exception->errorCode);
            $this->assertSame($reason, $exception->diagnostics['validation_errors'][0]['reason']);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_sanitized_empty_content_fails_but_safe_code_and_tables_remain_readable(): void
    {
        foreach (['<script>bad()</script>', '<p>&nbsp; </p>', '<p>\u{200B}</p>'] as $html) {
            if (str_contains($html, '\\u{200B}')) {
                $html = "<p>\u{200B}</p>";
            }
            $this->assertSame('AI_PROVIDER_EMPTY_CONTENT', $this->failure(['content_html' => $html], ['content'], true)->errorCode);
        }
        $result = (new AiOutputValidator)->validate([
            'content' => '<pre><code>&lt;?php echo 1;</code></pre><table><tr><td>Data</td></tr></table><script>bad()</script>',
        ], ['content'], true);
        $this->assertStringContainsString('<code>', $result['content_html']);
        $this->assertStringContainsString('<table>', $result['content_html']);
        $this->assertStringNotContainsString('<script', $result['content_html']);
        $this->assertSame($result['content_html'], $result['content']);
    }

    public function test_canonical_alias_precedence_legacy_required_fields_and_source_thumbnail_boundary(): void
    {
        $validator = new AiOutputValidator;
        $result = $validator->validate(['title' => 'Title', 'content_html' => '<p>Canonical</p>', 'content' => ['ignored']]);
        $this->assertSame('<p>Canonical</p>', $result['content']);
        $this->assertSame('AI_PROVIDER_MISSING_FIELDS', $this->failure(['title' => 'Title'], null)->errorCode);
        $this->assertSame([], $validator->validate(['thumbnail' => ['source_url' => 'https://fake.test', 'media_asset_id' => 999]], ['thumbnail']));
        $this->assertSame(['suggested_category_ids' => [1]], $validator->validate(['category_ids' => [1, 1]], ['taxonomy']));
    }

    public function test_diagnostics_only_keep_bounded_schema_names_numbers_and_safe_error_reasons(): void
    {
        $safe = AiResponseDiagnostics::sanitize([
            'api_key' => 'secret', 'raw_response' => 'secret', 'prompt' => 'secret',
            'response_id' => "secret\nAuthorization: Bearer", 'reported_model' => 'sk-sensitive',
            'schema_version' => 'post.content.v1', 'stage' => 'sanitize',
            'requested_groups' => ['title', 'unknown-secret'],
            'returned_fields' => ['title', 'unknown-secret'],
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 'secret', 'secret' => 999, 'total_tokens' => -1],
            'validation_errors' => [
                ['group' => 'title', 'field' => 'title', 'reason' => 'missing', 'value' => 'secret'],
                ['group' => 'title', 'field' => 'secret-field', 'reason' => 'missing'],
                ['group' => 'secret-group', 'field' => 'title', 'reason' => 'missing'],
                ['group' => 'title', 'field' => 'title', 'reason' => 'secret-reason'],
            ],
        ]);
        $this->assertSame([
            'stage' => 'sanitize', 'schema_version' => 'post.content.v1',
            'requested_groups' => ['title'], 'returned_fields' => ['title'],
            'usage' => ['prompt_tokens' => 2],
            'validation_errors' => [['group' => 'title', 'field' => 'title', 'reason' => 'missing']],
        ], $safe);
        $this->assertSame($safe, (new AiImportException('Safe', diagnostics: $safe + ['raw_response' => 'secret']))->diagnostics);
    }

    private function failure(array $payload, ?array $groups, bool $sanitize = false): AiImportException
    {
        try {
            (new AiOutputValidator)->validate($payload, $groups, $sanitize);
            $this->fail('Invalid AI output must fail.');
        } catch (AiImportException $exception) {
            return $exception;
        }
    }
}
