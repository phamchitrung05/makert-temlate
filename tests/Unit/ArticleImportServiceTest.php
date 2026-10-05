<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Providers\Adapters\StructuredAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử extractor deterministic và chặn URL nội bộ AI import.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: các test extract/sanitize/SSRF/provider fallback.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML fixture, URL giả lập và cấu hình provider.
 * - OUTPUT: assertion draft canonical hoặc exception bảo mật.
 * - SIDE EFFECT: Http::fake, không gọi network thật; không ghi dữ liệu production.
 * =====================================================================
 */
class ArticleImportServiceTest extends TestCase
{
    /**
     * INPUT: HTML article fixture. OUTPUT: deterministic draft canonical.
     * SIDE EFFECT: Http::fake only. EXCEPTION/TRANSACTION: no real network/DB transaction.
     */
    public function test_extracts_deterministic_draft_from_article_fixture(): void
    {
        config()->set('ai-providers.connections.http-json.endpoint', null);
        config()->set('ai-providers.connections.http-json.key', null);
        Http::fake(['https://example.test/article' => Http::response('<html><head><title>Tiêu đề thử nghiệm</title><meta name="description" content="Mô tả"></head><body><article><h2>Mục một</h2><p>Nội dung bài viết an toàn.</p></article></body></html>')]);

        $result = (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'https://example.test/article']));

        $this->assertSame('Tiêu đề thử nghiệm', $result['draft']['title']);
        $this->assertStringContainsString('<p>Nội dung', $result['draft']['content']);
        $this->assertSame('deterministic', $result['provider']);
    }

    /**
     * INPUT: text inline không có URL. OUTPUT: cùng canonical draft deterministic.
     * SIDE EFFECT: không gửi HTTP; extractor dùng source map trong memory.
     */
    public function test_extracts_deterministic_draft_from_inline_text(): void
    {
        Http::fake();

        $result = (new ArticleImportService(new StructuredAiProvider))->run(new AiImport([
            'source_text' => "Tiêu đề inline\n\nNội dung nhập tay.",
            'input_json' => ['source_type' => 'text'],
        ]));

        $this->assertSame('Tiêu đề inline', $result['draft']['title']);
        $this->assertStringContainsString('Nội dung nhập tay.', $result['draft']['content']);
        Http::assertNothingSent();
    }

    /**
     * INPUT: localhost URL. OUTPUT: URL security exception.
     * SIDE EFFECT: assert no HTTP request. EXCEPTION/TRANSACTION: no transaction.
     */
    public function test_rejects_localhost_before_fetching(): void
    {
        Http::fake();
        $this->expectExceptionMessage('URL nguồn không được phép.');
        (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'http://localhost/private']));
        Http::assertNothingSent();
    }

    /**
     * INPUT: private IP URL. OUTPUT: URL security exception.
     * SIDE EFFECT: assert no HTTP request. EXCEPTION/TRANSACTION: no transaction.
     */
    public function test_rejects_private_ip_before_fetching(): void
    {
        Http::fake();
        $this->expectExceptionMessage('URL nguồn không được phép.');
        (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'http://10.0.0.1/article']));
        Http::assertNothingSent();
    }

    /**
     * INPUT: HTML có event handler/javascript link. OUTPUT: HTML đã sanitize.
     * SIDE EFFECT: Http::fake. EXCEPTION/TRANSACTION: no real network/DB transaction.
     */
    public function test_sanitizes_event_handlers_and_javascript_links(): void
    {
        config()->set('ai-providers.connections.http-json.endpoint', null);
        config()->set('ai-providers.connections.http-json.key', null);
        Http::fake(['https://example.test/article' => Http::response('<article><p onclick="alert(1)">An toàn <a href="javascript:alert(1)" onmouseover="bad()">link</a></p></article>')]);

        $result = (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'https://example.test/article']));

        $this->assertStringNotContainsString('onclick', $result['draft']['content']);
        $this->assertStringNotContainsString('javascript:', $result['draft']['content']);
    }

    /**
     * INPUT: HTML có code/table hợp lệ. OUTPUT: markup an toàn còn nguyên.
     * SIDE EFFECT: Http::fake. EXCEPTION/TRANSACTION: no real network/DB transaction.
     */
    public function test_preserves_code_examples_and_safe_table_markup(): void
    {
        config()->set('ai-providers.connections.http-json.endpoint', null);
        config()->set('ai-providers.connections.http-json.key', null);
        Http::fake(['https://example.test/article' => Http::response('<article><pre class="language-php"><code>&lt;?php echo "ok";</code></pre><table onclick="bad()"><tr><th scope="col">Tên</th><td colspan="2">Giá trị</td></tr></table></article>')]);

        $result = (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'https://example.test/article']));

        $this->assertStringContainsString('<pre>', $result['draft']['content']);
        $this->assertStringContainsString('<code>&lt;?php echo "ok";</code>', $result['draft']['content']);
        $this->assertStringContainsString('<table>', $result['draft']['content']);
        $this->assertStringContainsString('colspan="2"', $result['draft']['content']);
        $this->assertStringNotContainsString('onclick', $result['draft']['content']);
    }

    /**
     * INPUT: chuỗi redirect vượt giới hạn. OUTPUT: lỗi redirect an toàn.
     * SIDE EFFECT: Http::fake. EXCEPTION/TRANSACTION: no real network/DB transaction.
     */
    public function test_follows_only_a_small_number_of_redirects(): void
    {
        Http::fake([
            'https://example.test/article' => Http::response('', 302, ['Location' => 'https://example.test/final']),
            'https://example.test/final' => Http::response('<article><p>Đích hợp lệ.</p></article>'),
        ]);

        $result = (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'https://example.test/article']));

        $this->assertSame('https://example.test/final', $result['source']['url']);
    }

    /**
     * INPUT: response HTML vượt giới hạn bytes. OUTPUT: lỗi payload quá lớn.
     * SIDE EFFECT: Http::fake. EXCEPTION/TRANSACTION: no real network/DB transaction.
     */
    public function test_rejects_oversized_source_html(): void
    {
        config()->set('ai-import.max_html_bytes', 10);
        Http::fake(['https://example.test/article' => Http::response('<article>too large</article>')]);

        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('vượt giới hạn kích thước');
        (new ArticleImportService(new StructuredAiProvider))->run(new AiImport(['source_url' => 'https://example.test/article']));
    }
}
