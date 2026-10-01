<?php

namespace App\Services\Ai;

use App\Exceptions\AiImportException;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter Google Gemini trả structured JSON.
 * =====================================================================
 *
 * Adapter chuyển context dùng chung sang generateContent API, giữ key ở
 * backend và để AbstractStructuredAiProvider kiểm tra schema/allowlist.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): kiểm tra Gemini key.
 * - providerName(): trả gemini cho provenance.
 * - modelName(): trả model request/config.
 * - requestPayload(): gọi generateContent với MIME JSON.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : GEMINI key/model và context prompt/schema.
 * - OUTPUT: raw Gemini JSON hoặc AiImportException.
 * =====================================================================
 */
final class GeminiProvider extends AbstractStructuredAiProvider
{
    /** Input: Không có. Output: true khi có API key. */
    public function configured(): bool
    {
        return (string) config('ai-import.gemini.key') !== '';
    }

    /** Input: Không có. Output: provider key gemini. */
    public function providerName(): string
    {
        return 'gemini';
    }

    /** Input: Không có. Output: model request hoặc model mặc định. */
    public function modelName(): string
    {
        return $this->requestedModel() ?: (string) config('ai-import.gemini.model', 'gemini-3.6-flash');
    }

    /**
     * Gửi context tới Gemini generateContent.
     *
     * Input: context canonical từ AbstractStructuredAiProvider.
     * Output: response JSON; lỗi quota/server được đánh dấu retryable.
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        $endpoint = rtrim((string) config('ai-import.gemini.endpoint'), '/').'/models/'.rawurlencode((string) $input['model']).':generateContent';
        $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
            ->timeout((int) config('ai-import.timeout', 12))
            ->withHeaders(['x-goog-api-key' => (string) config('ai-import.gemini.key')])
            ->acceptJson()
            ->post($endpoint, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $input['instructions']."\n".json_encode([
                            'title' => $input['title'],
                            'content_html' => $input['content_html'],
                            'language' => $input['language'],
                            'rewrite_style' => $input['rewrite_style'],
                            'additional_instructions' => $input['user_instructions'],
                            'prompt_key' => $input['prompt_key'],
                            'prompt_version' => $input['prompt_version'],
                            'schema_version' => $input['schema_version'],
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => (float) config('ai-import.gemini.temperature', 0.2),
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new AiImportException(
                'Gemini trả HTTP '.$response->status().'.',
                'AI_PROVIDER_HTTP_'.$response->status(),
                $response->status() === 429 || $response->serverError(),
            );
        }

        return $response->json();
    }
}
