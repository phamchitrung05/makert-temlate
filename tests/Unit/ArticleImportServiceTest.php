<?php

namespace Tests\Unit;

use App\Models\AiImport;
use App\Services\Ai\ArticleImportService;
use App\Services\Ai\StructuredAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Kiểm thử extractor deterministic và chặn URL nội bộ của AI import. */
class ArticleImportServiceTest extends TestCase
{
    public function test_extracts_deterministic_draft_from_article_fixture(): void
    {
        config()->set('ai-import.endpoint', null);
        config()->set('ai-import.key', null);
        Http::fake(['https://example.test/article' => Http::response('<html><head><title>Tiêu đề thử nghiệm</title><meta name="description" content="Mô tả"></head><body><article><h2>Mục một</h2><p>Nội dung bài viết an toàn.</p></article></body></html>')]);

        $result = (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'https://example.test/article']));

        $this->assertSame('Tiêu đề thử nghiệm', $result['draft']['title']);
        $this->assertStringContainsString('<p>Nội dung', $result['draft']['content']);
        $this->assertSame('deterministic', $result['provider']);
    }

    public function test_rejects_localhost_before_fetching(): void
    {
        Http::fake();
        $this->expectExceptionMessage('URL nguồn không được phép.');
        (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'http://localhost/private']));
        Http::assertNothingSent();
    }

    public function test_rejects_private_ip_before_fetching(): void
    {
        Http::fake();
        $this->expectExceptionMessage('URL nguồn không được phép.');
        (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'http://10.0.0.1/article']));
        Http::assertNothingSent();
    }
}
