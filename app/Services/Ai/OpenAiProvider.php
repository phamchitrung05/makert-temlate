<?php

namespace App\Services\Ai;

use App\Exceptions\AiImportException;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter OpenAI Chat Completions trả structured JSON.
 * =====================================================================
 *
 * Provider chỉ nhận nội dung đã sanitize và trả raw response cho lớp chuẩn
 * hóa chung; API key và endpoint luôn nằm server-side trong config.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): kiểm tra OpenAI key.
 * - providerName(): trả openai cho provenance.
 * - modelName(): trả model allowlist/config.
 * - requestPayload(): gửi request JSON tới OpenAI.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : OPENAI key/model và context prompt/schema.
 * - OUTPUT: raw Chat Completions JSON hoặc AiImportException.
 * =====================================================================
 */
final class OpenAiProvider extends AbstractStructuredAiProvider
{
    /** Input: Không có. Output: true khi có API key và provider bật. */
    public function configured(): bool
    {
        return (string) config('ai-import.openai.key') !== '';
    }

    /** Input: Không có. Output: provider key openai. */
    public function providerName(): string
    {
        return 'openai';
    }

    /** Input: Không có. Output: model request hoặc model mặc định. */
    public function modelName(): string
    {
        return $this->requestedModel() ?: (string) config('ai-import.openai.model', 'gpt-4o-mini');
    }

    /**
     * Gửi prompt tới OpenAI với response_format JSON object.
     *
     * Input: context canonical từ AbstractStructuredAiProvider.
     * Output: response JSON; lỗi 429/5xx có thể retry.
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
            ->timeout((int) config('ai-import.timeout', 12))
            ->withToken((string) config('ai-import.openai.key'))
            ->acceptJson()
            ->post((string) config('ai-import.openai.endpoint'), [
                'model' => $input['model'],
                'temperature' => (float) config('ai-import.openai.temperature', 0.2),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $input['instructions']],
                    ['role' => 'user', 'content' => json_encode([
                        'title' => $input['title'],
                        'content_html' => $input['content_html'],
                        'language' => $input['language'],
                        'rewrite_style' => $input['rewrite_style'],
                        'additional_instructions' => $input['user_instructions'],
                        'prompt_key' => $input['prompt_key'],
                        'prompt_version' => $input['prompt_version'],
                        'schema_version' => $input['schema_version'],
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                ],
            ]);

        if (! $response->successful()) {
            throw new AiImportException(
                'OpenAI trả HTTP '.$response->status().'.',
                'AI_PROVIDER_HTTP_'.$response->status(),
                $response->status() === 429 || $response->serverError(),
            );
        }

        return $response->json();
    }
}
