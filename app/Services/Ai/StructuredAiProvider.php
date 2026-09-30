<?php

namespace App\Services\Ai;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter gọi nhà cung cấp AI trả về JSON có cấu trúc.
 * =====================================================================
 * Endpoint và khóa lấy từ config; khi thiếu cấu hình adapter trả fallback rỗng.
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): kiểm tra provider đã cấu hình.
 * - generate(): gửi nội dung và chuẩn hóa JSON kết quả.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tiêu đề, HTML và ngôn ngữ bài viết.
 * - OUTPUT: các field draft được allowlist hoặc mảng rỗng.
 * =====================================================================
 */

use App\Exceptions\AiImportException;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use Illuminate\Support\Facades\Http;

/** Optional JSON provider adapter. It is disabled when no endpoint/key is configured. */
class StructuredAiProvider implements AiProviderContract
{
    /** Input: không có. Output: true nếu endpoint và key đã cấu hình. */
    public function configured(): bool
    {
        return (string) config('ai-import.endpoint') !== '' && (string) config('ai-import.key') !== '';
    }

    /** Provider identity used for provenance; secrets never leave config. */
    public function providerName(): string
    {
        return $this->configured() ? 'http-json' : 'deterministic';
    }

    /** Model identity used for provenance and audit records. */
    public function modelName(): string
    {
        return $this->configured() ? (string) config('ai-import.model', 'default') : 'deterministic';
    }

    /** @return array<string,mixed> */
    /** Input: title/HTML/ngôn ngữ. Output: JSON field allowlist; ném lỗi khi provider thất bại. */
    public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
    {
        if (! $this->configured()) {
            return [];
        }
        $prompt = (new PromptRegistry)->get($promptKey, 'post', 'create');
        $schema = (new SchemaRegistry)->get($prompt['schema']);
        $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
            ->timeout((int) config('ai-import.timeout', 12))
            ->withToken((string) config('ai-import.key'))
            ->acceptJson()->post((string) config('ai-import.endpoint'), [
                'model' => config('ai-import.model', 'default'),
                'language' => $language,
                'response_format' => ['type' => 'json_object'],
                'input' => [
                    'title' => $title,
                    'content_html' => $content,
                    'rewrite_style' => $rewriteStyle,
                    'instructions' => $prompt['instructions'].' Allowed fields: '.implode(', ', $schema['fields']).'.',
                    'user_instructions' => $instructions,
                    'prompt_key' => $promptKey,
                    'prompt_version' => $prompt['version'],
                    'schema_version' => $prompt['schema'],
                ],
            ]);
        if (! $response->successful()) {
            throw new AiImportException('AI provider trả HTTP '.$response->status().'.', 'AI_PROVIDER_HTTP_'.$response->status(), $response->status() === 429 || $response->serverError());
        }
        $payload = $response->json('data') ?? $response->json();
        if (isset($payload['output']) && is_string($payload['output'])) {
            $payload = json_decode($payload['output'], true) ?: [];
        }
        if (isset($payload['choices'][0]['message']['content'])) {
            $payload = json_decode((string) $payload['choices'][0]['message']['content'], true) ?: [];
        }

        if (! is_array($payload)) {
            throw new AiImportException('AI provider trả JSON không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }
        $allowed = ['title', 'content_html', 'content', 'excerpt', 'focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description', 'suggested_category_ids', 'suggested_tag_ids', 'thumbnail_prompt', 'thumbnail_alt_text'];
        $result = array_intersect_key($payload, array_flip($allowed));
        foreach (['title', 'content_html', 'content', 'excerpt', 'focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'thumbnail_prompt', 'thumbnail_alt_text'] as $field) {
            if (array_key_exists($field, $result) && ! is_string($result[$field])) {
                throw new AiImportException('AI provider trả sai kiểu dữ liệu.', 'AI_PROVIDER_SCHEMA');
            }
        }
        foreach (['robots_index', 'robots_follow'] as $field) {
            if (array_key_exists($field, $result) && ! is_bool($result[$field])) {
                throw new AiImportException('AI provider trả sai kiểu dữ liệu.', 'AI_PROVIDER_SCHEMA');
            }
        }
        foreach (['suggested_category_ids', 'suggested_tag_ids'] as $field) {
            if (array_key_exists($field, $result) && ! is_array($result[$field])) {
                throw new AiImportException('AI provider trả sai taxonomy.', 'AI_PROVIDER_SCHEMA');
            }
        }

        return $result;
    }
}
