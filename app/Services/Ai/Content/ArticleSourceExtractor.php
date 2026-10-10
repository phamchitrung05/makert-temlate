<?php

namespace App\Services\Ai\Content;

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Trích nguồn semantic và lập snapshot/source anchors ổn định.
 * =====================================================================
 * Ưu tiên vùng articleBody/article-content; fallback chấm điểm prose/link.
 * Loại chrome trang kể cả khi dùng div; giữ code và nội dung nằm trong form.
 * CÁC HÀM/METHOD TRONG FILE: extract(), selectContainer(), hasContentSignal(),
 * insideArticleContent(), snapshot(), imageReferences(), normalizeUrls().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML không tin cậy cùng metadata nguồn.
 * - OUTPUT: content, blocks, hash và metadata ảnh; chỉ xử lý trong memory.
 * =====================================================================
 */
final class ArticleSourceExtractor
{
    /**
     * =====================================================================
     * Input: HTML nguồn; chọn container có nhiều prose/code hữu ích.
     * Output: HTML đã sanitize; không gọi HTTP/DB, giữ form content và code.
     * =====================================================================
     */
    public function extract(string $html, string $sourceUrl = ''): string
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->normalizeUrls($document, $sourceUrl);
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//nav|//header[not(ancestor::article or ancestor::main)]|//footer[not(ancestor::article)]|//aside|//iframe|//input|//button|//select|//textarea') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//*[@class or @id or @role]') ?: []) as $node) {
            $labels = $node->getAttribute('class').' '.$node->getAttribute('id');
            if (preg_match('/(?:^|[\s_-])(?:advertisement|advert|ad-banner|cookie-banner|social-share|related-posts|newsletter|tracking)(?:$|[\s_-])/i', $labels)) {
                $node->parentNode?->removeChild($node);
            } elseif ((preg_match('/(?:^|[\s_-])(?:footer|copyright|sidebar|site-header|site-nav|navigation)(?:$|[\s_-])/i', $labels)
                || in_array(strtolower($node->getAttribute('role')), ['contentinfo', 'navigation', 'complementary'], true))
                && ! $this->insideArticleContent($node)) {
                // INPUT: nhãn chrome của trang, không phải văn bản nói về copyright.
                // OUTPUT: bỏ cả wrapper để section bản quyền không thành ứng viên bài.
                $node->parentNode?->removeChild($node);
            }
        }
        $container = $this->selectContainer($document, $xpath);
        $output = '';
        foreach ($container?->childNodes ?? [] as $child) {
            $output .= $document->saveHTML($child);
        }

        return (new AiContentSanitizer)->sanitize($output);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn vùng bài theo dấu hiệu body rồi mới so prose/link fallback.
     * Input: DOM đã loại chrome và XPath. Output: container hoặc body khi chưa rõ.
     * SIDE EFFECT: chỉ đọc DOM; không gọi AI hoặc gắn selector riêng cho một website.
     * =====================================================================
     */
    private function selectContainer(\DOMDocument $document, \DOMXPath $xpath): ?\DOMNode
    {
        $container = null;
        $preferred = null;
        $bestScore = -INF;
        $preferredScore = -INF;
        $candidates = $xpath->query('//article|//main|//section|//div[p or pre or blockquote or table or h1 or h2]|//*[@class or @id or @itemprop or @role="main"]');
        foreach ($candidates ?: [] as $node) {
            $explicitBody = $this->hasContentSignal($node);
            if (! $explicitBody && ! in_array($node->nodeName, ['article', 'main', 'section', 'div'], true) && $node->getAttribute('role') !== 'main') {
                continue;
            }
            if (! $explicitBody && $node->nodeName === 'div' && ! $xpath->query('./p|./pre|./blockquote|./table|./h1|./h2', $node)?->length && $node->getAttribute('role') !== 'main') {
                continue;
            }
            $textLength = mb_strlen(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $node->textContent) ?? ''));
            if ($textLength === 0) {
                continue;
            }
            $linkLength = 0;
            foreach ($xpath->query('.//a', $node) ?: [] as $link) {
                $linkLength += mb_strlen(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $link->textContent) ?? ''));
            }
            $proseCount = $xpath->query('.//p[normalize-space(.) != ""]|.//pre|.//blockquote', $node)?->length ?? 0;
            $score = max(0, $textLength - $linkLength) - $linkLength * 0.8
                + min($proseCount, 20) * 20 + ($node->nodeName === 'article' ? 80 : 0);
            if ($explicitBody && $score > $preferredScore) {
                $preferred = $node;
                $preferredScore = $score;
            }
            if ($score > $bestScore) {
                $container = $node;
                $bestScore = $score;
            }
        }

        return $preferred ?? $container ?? $document->getElementsByTagName('body')->item(0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận dấu hiệu vùng thân bài độc lập với tag HTML của website.
     * Input: DOMElement và nhãn schema/class/id. Output: boolean body bài có tên rõ.
     * Không coi class content/container chung là bằng chứng đủ để ưu tiên.
     * =====================================================================
     */
    private function hasContentSignal(\DOMElement $node): bool
    {
        if (preg_match('/(?:^|\s)articleBody(?:$|\s)/i', $node->getAttribute('itemprop'))) {
            return true;
        }
        $labels = preg_replace('/[_-]+/', '-', $node->getAttribute('class').' '.$node->getAttribute('id'));

        return (bool) preg_match('/(?:^|[\s-])(?:article|entry|post|story|news)-(?:content|body)(?:$|[\s-])/i', $labels);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ đoạn bài có nhãn copyright/sidebar hợp lệ bên trong thân bài.
     * Input: node cần lọc và ancestors. Output: true nếu nằm trong vùng article/body.
     * SIDE EFFECT: chỉ đọc ancestor, không xóa nội dung vì có từ khóa trong câu.
     * =====================================================================
     */
    private function insideArticleContent(\DOMElement $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if ($parent->nodeName === 'article' || $this->hasContentSignal($parent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * =====================================================================
     * Input: content đã extract, metadata và budget snapshot của run.
     * Output: snapshot với source block IDs/hash; SOURCE_TOO_LARGE nếu quá budget.
     * =====================================================================
     */
    public function snapshot(string $content, array $metadata, array $options = []): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$content.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $images = [];
        foreach (iterator_to_array($xpath->query('//img') ?: []) as $image) {
            $images[] = ['id' => 'SOURCE_IMAGE_'.(count($images) + 1), 'source_url' => $image->getAttribute('src'), 'alt' => $image->getAttribute('alt'), 'context' => mb_substr(trim($image->parentNode?->textContent ?? ''), 0, 1000)];
            $image->parentNode?->removeChild($image);
        }
        $blocks = [];
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//p[not(ancestor::blockquote or ancestor::li or ancestor::table)]|//pre|//table|//blockquote|//li[not(ancestor::li)]|//figcaption');
        foreach ($nodes ?: [] as $node) {
            $text = html_entity_decode(trim($node->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($text !== '') {
                $blocks[] = ['id' => sprintf('S%03d', count($blocks) + 1), 'type' => $node->nodeName, 'text' => $text, 'html' => $document->saveHTML($node)];
            }
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $modelContent = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $modelContent .= $document->saveHTML($child);
        }
        if ($blocks === [] && trim(strip_tags($modelContent)) !== '') {
            $blocks[] = ['id' => 'S001', 'type' => 'text', 'text' => trim(strip_tags($modelContent)), 'html' => $modelContent];
        }
        if (mb_strlen($modelContent) > ($options['max_source_characters'] ?? config('ai.content.max_source_characters', 100000))
            || count($blocks) > ($options['max_source_blocks'] ?? config('ai.content.max_source_blocks', 250))) {
            throw new AiImportException('Nguồn vượt budget pipeline. Hãy chọn phần bài cần viết hoặc rút gọn nguồn; hệ thống không cắt thầm nội dung.', 'SOURCE_TOO_LARGE');
        }
        $snapshot = array_replace($metadata, [
            'version' => 'article.source.v1', 'content_html' => trim($modelContent), 'blocks' => $blocks,
            'source_images' => $images, 'fetched_at' => now()->toIso8601String(),
        ]);
        $snapshot['hash'] = ArticleInputHasher::hash([$modelContent, $blocks, $metadata]);

        return $snapshot;
    }

    /**
     * =====================================================================
     * Input: HTML candidate cha chứa ảnh MediaLibrary đã duyệt.
     * Output: map ref ID -> ảnh hoặc figure một ảnh kèm caption; không tải/import ảnh nguồn.
     * =====================================================================
     */
    public function imageReferences(string $html): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.(new AiContentSanitizer)->sanitize($html).'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $refs = [];
        foreach ($document->getElementsByTagName('img') as $index => $image) {
            if (preg_match('/^[1-9][0-9]*$/D', $image->getAttribute('data-media-asset-id'))) {
                // Chú thích do người biên tập thêm thuộc cùng ảnh, giữ qua regenerate.
                // Figure nhiều ảnh không gán cả container cho từng ref vì sẽ nhân đôi ảnh.
                $container = $image->parentNode;
                while ($container instanceof \DOMElement && $container->nodeName !== 'figure') {
                    $container = $container->parentNode;
                }
                $node = $container instanceof \DOMElement && $container->getElementsByTagName('img')->length === 1 ? $container : $image;
                $refs['I'.($index + 1)] = $document->saveHTML($node);
            }
        }

        return $refs;
    }

    /**
     * =====================================================================
     * Input: DOM nguồn và URL đã qua fetch boundary.
     * Output: chuẩn hóa link/ảnh relative, lazy-src/srcset vào src thật trước sanitize.
     * SIDE EFFECT: chỉ DOM memory, tuyệt đối không tải/import ảnh từ URL này.
     * =====================================================================
     */
    private function normalizeUrls(\DOMDocument $document, string $sourceUrl): void
    {
        foreach ($document->getElementsByTagName('img') as $image) {
            if (! $image->hasAttribute('src') || trim($image->getAttribute('src')) === '') {
                $src = $image->getAttribute('data-src') ?: $image->getAttribute('data-original');
                if ($src === '' && $image->hasAttribute('srcset')) {
                    $src = preg_split('/[\s,]+/', trim($image->getAttribute('srcset')))[0] ?? '';
                }
                if ($src !== '') {
                    $image->setAttribute('src', $src);
                }
            }
        }
        if (! preg_match('#^https?://#i', $sourceUrl)) {
            return;
        }
        foreach ($document->getElementsByTagName('*') as $node) {
            $attribute = $node->nodeName === 'img' ? 'src' : ($node->nodeName === 'a' ? 'href' : null);
            if ($attribute !== null && $node->hasAttribute($attribute)) {
                try {
                    $node->setAttribute($attribute, (string) UriResolver::resolve(new Uri($sourceUrl), new Uri($node->getAttribute($attribute))));
                } catch (\InvalidArgumentException) {
                    $node->removeAttribute($attribute);
                }
            }
        }
    }
}
