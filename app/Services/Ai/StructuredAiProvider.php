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

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Optional JSON provider adapter. It is disabled when no endpoint/key is configured. */
class StructuredAiProvider
{
    /** Input: không có. Output: true nếu endpoint và key đã cấu hình. */
    public function configured(): bool
    {
        return (string) config('ai-import.endpoint') !== '' && (string) config('ai-import.key') !== '';
    }

    /** @return array<string,mixed> */
    /** Input: title/HTML/ngôn ngữ. Output: JSON field allowlist; ném lỗi khi provider thất bại. */
    public function generate(string $title, string $content, string $language = 'vi'): array
    {
        if (! $this->configured()) {
            return [];
        }
        $response = Http::timeout((int) config('ai-import.timeout', 12))
            ->withToken((string) config('ai-import.key'))
            ->acceptJson()->post((string) config('ai-import.endpoint'), [
                'model' => config('ai-import.model', 'default'),
                'language' => $language,
                'response_format' => ['type' => 'json_object'],
                'input' => [
                    'title' => $title,
                    'content_html' => $content,
                    'instructions' => 'Return JSON only with title, content, excerpt, focus_keyword, seo_title, seo_description, og_title, og_description, tags, categories. Rewrite faithfully in the requested language; never include scripts.',
                ],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('AI provider trả HTTP '.$response->status().'.');
        }
        $payload = $response->json('data') ?? $response->json();
        if (isset($payload['output']) && is_string($payload['output'])) {
            $payload = json_decode($payload['output'], true) ?: [];
        }
        if (isset($payload['choices'][0]['message']['content'])) {
            $payload = json_decode((string) $payload['choices'][0]['message']['content'], true) ?: [];
        }

        return is_array($payload) ? array_intersect_key($payload, array_flip(['title', 'content', 'excerpt', 'focus_keyword', 'seo_title', 'seo_description', 'og_title', 'og_description', 'tags', 'categories'])) : [];
    }
}
