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
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng Chat Completions được map sang canonical output
     * =====================================================================
     * INPUT: Fake choices.message.content chứa JSON và config test.
     * OUTPUT: Assertions title/content_html cùng header Authorization đúng.
     * SIDE EFFECT: Chỉ gọi HTTP fake; không ghi domain database.
     * EXCEPTION/TRANSACTION: Không gọi mạng thật hoặc mở transaction.
     * =====================================================================
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
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng generateContent được map sang canonical output
     * =====================================================================
     * INPUT: Fake candidates.content.parts.text và config test.
     * OUTPUT: Assertions SEO output và API key chỉ nằm trong header.
     * SIDE EFFECT: Chỉ gọi HTTP fake; không ghi domain database.
     * EXCEPTION/TRANSACTION: Không gọi mạng thật hoặc mở transaction.
     * =====================================================================
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng từ chối structured JSON sai định dạng
     * =====================================================================
     * INPUT: HTTP fake trả message content không phải JSON.
     * OUTPUT: AiImportException AI_PROVIDER_INVALID_JSON không retry.
     * SIDE EFFECT: Chỉ dùng HTTP fake và config test.
     * EXCEPTION/TRANSACTION: Test bắt domain exception; không gọi mạng thật.
     * =====================================================================
     */
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng timeout POST không tự lặp generation có phí
     * =====================================================================
     * INPUT: HTTP fake ném ConnectionException cho generation request.
     * OUTPUT: AiImportException AI_PROVIDER_TIMEOUT không retry.
     * SIDE EFFECT: Chỉ dùng HTTP fake và config test.
     * EXCEPTION/TRANSACTION: Test bắt lỗi domain an toàn; không gửi request thật.
     * =====================================================================
     */
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
            $this->assertFalse($exception->retryable);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng provider refusal được báo thành lỗi domain
     * =====================================================================
     * INPUT: HTTP fake trả refusal thay vì content.
     * OUTPUT: Exception thông báo từ chối; không trả candidate.
     * SIDE EFFECT: Chỉ dùng HTTP fake và config test.
     * EXCEPTION/TRANSACTION: Test mong đợi exception; không gọi mạng thật.
     * =====================================================================
     */
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng HTTP 429 được phân loại retryable
     * =====================================================================
     * INPUT: HTTP fake trả quota status 429.
     * OUTPUT: Exception AI_PROVIDER_HTTP_429 có retryable=true.
     * SIDE EFFECT: Chỉ dùng HTTP fake và config test.
     * EXCEPTION/TRANSACTION: Test bắt domain exception; không gọi mạng thật.
     * =====================================================================
     */
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
