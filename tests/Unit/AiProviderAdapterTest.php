<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Services\Ai\Providers\Adapters\GeminiProvider;
use App\Services\Ai\Providers\Adapters\OpenAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression HTTP adapter OpenAI/Gemini với provider responses giả lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_null_optional_fields_are_omitted_but_incorrect_types_are_rejected().
 * - test_openai_provider_maps_chat_json_output().
 * - test_gemini_provider_maps_generate_content_output().
 * - test_provider_rejects_malformed_json().
 * - test_provider_normalizes_connection_timeout().
 * - test_provider_rejects_refusal_response().
 * - test_provider_marks_quota_error_as_retryable().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures source/JSON và cấu hình test đã cô lập.
 * - OUTPUT: assertions contract; không gọi AI thật hoặc ghi database development.
 * =====================================================================
 */
class AiProviderAdapterTest extends TestCase
{
    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_null_optional_fields_are_omitted_but_incorrect_types_are_rejected(): void
    {
        Config::set('ai-providers.connections.openai.key', 'test-openai-key');
        Config::set('ai-providers.connections.openai.endpoint', 'https://api.openai.test/v1/chat/completions');
        Http::fake(['https://api.openai.test/*' => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['title' => 'Title', 'content_html' => '<p>Content</p>', 'canonical_url' => null, 'thumbnail_prompt' => null, 'robots_index' => false, 'suggested_category_ids' => []])]]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['title' => ['value' => 'Title'], 'content_html' => '<p>Content</p>'])]]]]),
        ]);
        $this->assertSame(['title' => 'Title', 'content_html' => '<p>Content</p>', 'robots_index' => false, 'content' => '<p>Content</p>'], (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>'));
        Http::assertSent(fn ($request): bool => str_contains($request['messages'][0]['content'], 'flat JSON object'));
        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Không chấp nhận field text dạng object.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_PROVIDER_SCHEMA', $exception->errorCode);
            $this->assertStringContainsString('title', $exception->getMessage());
        }
    }

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
        Config::set('ai-providers.connections.openai.key', 'test-openai-key');
        Config::set('ai-providers.connections.openai.model', 'gpt-test');
        Http::fake(['https://api.openai.test/*' => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => json_encode(['title' => 'Tiêu đề', 'content_html' => '<p>Nội dung</p>'])],
            ]],
        ])]);
        Config::set('ai-providers.connections.openai.endpoint', 'https://api.openai.test/v1/chat/completions');

        $result = (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');

        $this->assertSame('Tiêu đề', $result['title']);
        $this->assertSame('<p>Nội dung</p>', $result['content_html']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $request['model'] === 'gpt-test' && $request['tool_choice'] === 'none' && ! isset($request['tools']));
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
        Config::set('ai-providers.connections.gemini.key', 'test-gemini-key');
        Config::set('ai-providers.connections.gemini.model', 'gemini-test');
        Config::set('ai-providers.connections.gemini.endpoint', 'https://generativelanguage.test/v1beta');
        Http::fake(['https://generativelanguage.test/*' => Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [
                        ['text' => json_encode(['seo_title' => 'SEO title'])],
                    ],
                ],
            ]],
        ])]);

        $result = (new GeminiProvider)->withOutputFields(['seo'])->generate('Nguồn', '<p>Gốc</p>');

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
        Config::set('ai-providers.connections.openai.key', 'test-openai-key');
        Config::set('ai-providers.connections.openai.endpoint', 'https://api.openai.test/v1/chat/completions');
        Http::fake(['https://api.openai.test/*' => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => 'not-json'],
            ]],
        ])]);

        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Provider phải từ chối JSON sai định dạng.');
        } catch (AiImportException $exception) {
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
        Config::set('ai-providers.connections.openai.key', 'test-openai-key');
        Config::set('ai-providers.connections.openai.endpoint', 'https://api.openai.test/v1/chat/completions');
        Http::fake(fn () => throw new ConnectionException('timeout'));

        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Provider phải chuẩn hóa lỗi kết nối.');
        } catch (AiImportException $exception) {
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
        Config::set('ai-providers.connections.openai.key', 'test-openai-key');
        Http::fake(['https://api.openai.test/*' => Http::response([
            'choices' => [[
                'message' => ['refusal' => 'safety'],
            ]],
        ])]);
        Config::set('ai-providers.connections.openai.endpoint', 'https://api.openai.test/v1/chat/completions');

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
        Config::set('ai-providers.connections.openai.key', 'test-openai-key');
        Http::fake(['https://api.openai.test/*' => Http::response([], 429)]);
        Config::set('ai-providers.connections.openai.endpoint', 'https://api.openai.test/v1/chat/completions');

        try {
            (new OpenAiProvider)->generate('Nguồn', '<p>Gốc</p>');
            $this->fail('Provider phải ném lỗi quota.');
        } catch (AiImportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('AI_PROVIDER_HTTP_429', $exception->errorCode);
        }
    }
}
