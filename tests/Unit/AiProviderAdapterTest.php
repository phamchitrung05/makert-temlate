<?php

namespace Tests\Unit;

use App\Services\Ai\GeminiProvider;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử transport và structured output của provider AI.
 * =====================================================================
 *
 * Test dùng Http::fake để bảo đảm provider không gọi mạng trong suite mặc
 * định, đồng thời khóa contract model/provenance và schema canonical.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_openai_provider_maps_chat_json_output(): kiểm tra OpenAI response.
 * - test_gemini_provider_maps_generate_content_output(): kiểm tra Gemini response.
 * - test_provider_rejects_malformed_json(): khóa lỗi structured output.
 * - test_provider_normalizes_connection_timeout(): khóa lỗi retry timeout.
 * - test_provider_rejects_refusal_response(): khóa refusal không retry.
 * - test_provider_marks_quota_error_as_retryable(): khóa HTTP 429 retry.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fake HTTP response và config provider.
 * - OUTPUT: canonical field được validate; không ghi domain database.
 * =====================================================================
 */
class AiProviderAdapterTest extends TestCase
{
    /**
     * Kiểm tra OpenAI Chat Completions được map về canonical output.
     *
     * Input: fake choices.message.content JSON.
     * Output: title/content_html đã parse; không gọi mạng thật.
     */
    public function test_openai_provider_maps_chat_json_output(): void
    {
        Config::set('ai-import.openai.key', 'test-openai-key');
        Config::set('ai-import.openai.model', 'gpt-test');
        Http::fake(['https://api.openai.test/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode(['title' => 'Tiêu đề', 'content_html' => '<p>Nội dung</p>'])],
            ]],
        ])]);
        Config::set('ai-import.openai.endpoint', 'https://api.openai.test/v1/chat/completions');

        $result = (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');

        $this->assertSame('Tiêu đề', $result['title']);
        $this->assertSame('<p>Nội dung</p>', $result['content_html']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $request['model'] === 'gpt-test');
    }

    /**
     * Kiểm tra Gemini generateContent được map về canonical output.
     *
     * Input: fake candidates.content.parts.text JSON.
     * Output: field SEO đã parse; API key chỉ nằm ở header server-side.
     */
    public function test_gemini_provider_maps_generate_content_output(): void
    {
        Config::set('ai-import.gemini.key', 'test-gemini-key');
        Config::set('ai-import.gemini.model', 'gemini-test');
        Config::set('ai-import.gemini.endpoint', 'https://generativelanguage.test/v1beta');
        Http::fake(['https://generativelanguage.test/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => json_encode(['seo_title' => 'SEO title'])],
                    ],
                ],
            ]],
        ])]);

        $result = (new GeminiProvider)->generate('Nguồn', '<p>Gốc</p>');

        $this->assertSame('SEO title', $result['seo_title']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'test-gemini-key')
            && ! str_contains($request->url(), 'key='));
    }

    /** Input: content không phải JSON. Output: lỗi domain INVALID_JSON. */
    public function test_provider_rejects_malformed_json(): void
    {
        Config::set('ai-import.openai.key', 'test-openai-key');
        Config::set('ai-import.openai.endpoint', 'https://api.openai.test/v1/chat/completions');
        Http::fake(['https://api.openai.test/*' => Http::response([
            'choices' => [[
                'message' => ['content' => 'not-json'],
            ]],
        ])]);

        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Provider phải từ chối JSON sai định dạng.');
        } catch (\App\Exceptions\AiImportException $exception) {
            $this->assertSame('AI_PROVIDER_INVALID_JSON', $exception->errorCode);
            $this->assertFalse($exception->retryable);
        }
    }

    /** Input: ConnectionException. Output: lỗi timeout retryable thống nhất. */
    public function test_provider_normalizes_connection_timeout(): void
    {
        Config::set('ai-import.openai.key', 'test-openai-key');
        Config::set('ai-import.openai.endpoint', 'https://api.openai.test/v1/chat/completions');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Provider phải chuẩn hóa lỗi kết nối.');
        } catch (\App\Exceptions\AiImportException $exception) {
            $this->assertSame('AI_PROVIDER_TIMEOUT', $exception->errorCode);
            $this->assertTrue($exception->retryable);
        }
    }

    /** Input: provider response refusal. Output: lỗi domain không retry. */
    public function test_provider_rejects_refusal_response(): void
    {
        Config::set('ai-import.openai.key', 'test-openai-key');
        Http::fake(['https://api.openai.test/*' => Http::response([
            'choices' => [[
                'message' => ['refusal' => 'safety'],
            ]],
        ])]);
        Config::set('ai-import.openai.endpoint', 'https://api.openai.test/v1/chat/completions');

        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('từ chối');
        (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
    }

    /** Input: HTTP quota. Output: lỗi retryable để queue phân loại. */
    public function test_provider_marks_quota_error_as_retryable(): void
    {
        Config::set('ai-import.openai.key', 'test-openai-key');
        Http::fake(['https://api.openai.test/*' => Http::response([], 429)]);
        Config::set('ai-import.openai.endpoint', 'https://api.openai.test/v1/chat/completions');

        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Provider phải ném lỗi quota.');
        } catch (\App\Exceptions\AiImportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('AI_PROVIDER_HTTP_429', $exception->errorCode);
        }
    }
}
