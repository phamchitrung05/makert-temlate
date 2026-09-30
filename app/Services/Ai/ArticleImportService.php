<?php

namespace App\Services\Ai;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đọc URL, trích xuất và tạo draft bài viết an toàn.
 * =====================================================================
 * Service kiểm tra SSRF, lấy HTML, loại nội dung nguy hiểm và gọi provider tùy chọn.
 * CÁC HÀM/METHOD TRONG FILE:
 * - run(): xử lý toàn bộ một AiImport.
 * - validateUrl(), title(), meta(), extract(), rewrite(): các bước pipeline.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bản ghi AiImport chứa URL nguồn.
 * - OUTPUT: source metadata và draft JSON; ném lỗi nếu URL/HTML không hợp lệ.
 * =====================================================================
 */

use App\Models\AiImport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Fetches an article safely and produces a provider-independent draft shape. */
class ArticleImportService
{
    public function __construct(private readonly StructuredAiProvider $provider) {}

    /** Input: AiImport đang processing. Output: source và draft JSON. */
    public function run(AiImport $import): array
    {
        $url = $this->validateUrl($import->source_url);
        $response = Http::timeout((int) config('ai-import.timeout', 12))
            ->connectTimeout(5)->withHeaders(['User-Agent' => 'MakerAdminArticleImporter/1.0'])
            ->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Không thể đọc URL nguồn (HTTP '.$response->status().').');
        }

        $html = (string) $response->body();
        $title = $this->meta($html, 'og:title') ?: $this->title($html) ?: 'Bài viết mới';
        $description = $this->meta($html, 'description');
        $content = $this->extract($html);
        if ($content === '') {
            throw new RuntimeException('Không tìm thấy nội dung bài viết trong URL.');
        }
        $keyword = Str::of($title)->lower()->explode(' ')->filter(fn ($word) => mb_strlen($word) > 4)->take(3)->implode(' ');
        $seoTitle = Str::limit($title, 60, '');
        $seoDescription = Str::limit($description ?: strip_tags($content), 155);

        $draft = [
            'title' => $title,
            'content' => $this->rewrite($content),
            'excerpt' => Str::limit(strip_tags($content), 240),
            'focus_keyword' => (string) $keyword,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'canonical_url' => $url,
            'robots_index' => true,
            'robots_follow' => true,
            'og_title' => $seoTitle,
            'og_description' => $seoDescription,
            'category_ids' => [], 'tag_ids' => [],
            'thumbnail' => ['media_asset_id' => null, 'source_url' => $this->meta($html, 'og:image'), 'alt_text' => $title],
        ];
        if ($this->provider->configured()) {
            $draft = array_merge($draft, $this->provider->generate($title, $content));
            $draft['content'] = strip_tags((string) $draft['content'], '<p><h1><h2><h3><h4><ul><ol><li><blockquote><strong><em><a>');
        }

        return [
            'source' => ['url' => $url, 'title' => $title, 'canonical_url' => $this->meta($html, 'canonical') ?: $url],
            'draft' => [
                ...$draft,
            ],
            'provider' => $this->provider->configured() ? 'http-json' : config('ai-import.provider', 'deterministic'),
            'prompt_version' => 'v1',
        ];
    }

    /** Input: URL người dùng gửi. Output: URL HTTP(S) hợp lệ, chặn host nội bộ. */
    private function validateUrl(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('URL không hợp lệ.');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('URL nguồn không được phép.');
        }

        return $url;
    }

    /** Input: HTML nguồn. Output: title đã giải mã hoặc chuỗi rỗng. */
    private function title(string $html): string
    {
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match);

        return isset($match[1]) ? trim(html_entity_decode(strip_tags($match[1]))) : '';
    }

    /** Input: HTML và tên meta. Output: giá trị meta hoặc chuỗi rỗng. */
    private function meta(string $html, string $name): string
    {
        $pattern = $name === 'canonical' ? '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i' : '/<meta[^>]+(?:property|name)=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)/i';
        preg_match($pattern, $html, $match);

        return isset($match[1]) ? html_entity_decode(trim($match[1])) : '';
    }

    /** Input: HTML nguồn. Output: HTML nội dung đã sanitize. */
    private function extract(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript|nav|footer|header)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        preg_match('/<article[^>]*>(.*?)<\/article>/is', $html, $match);
        $body = $match[1] ?? (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $bodyMatch) ? $bodyMatch[1] : $html);
        $body = strip_tags($body, '<p><h1><h2><h3><h4><ul><ol><li><blockquote><strong><em><a>');

        return trim(preg_replace('/\s{2,}/', ' ', $body) ?? $body);
    }

    /** Input: HTML đã trích xuất. Output: nội dung fallback khi chưa có LLM. */
    private function rewrite(string $content): string
    {
        // Provider hook: configure an LLM later; deterministic mode preserves structure and sanitizes HTML.
        return $content;
    }
}
