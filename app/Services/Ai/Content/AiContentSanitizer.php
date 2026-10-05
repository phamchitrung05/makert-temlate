<?php

namespace App\Services\Ai\Content;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Làm sạch HTML của nguồn, AI output và editor theo một allowlist.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: sanitize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML nguồn/model/editor không tin cậy.
 * - OUTPUT: semantic HTML, không script/event, giữ inline media asset refs.
 * =====================================================================
 */
final class AiContentSanitizer
{
    /**
     * =====================================================================
     * Input: HTML nguồn/model/editor không tin cậy.
     * Output: HTML semantic an toàn, giữ code whitespace và media refs;
     * không ghi DB. Quyền asset và src khớp asset được kiểm ở media service.
     * =====================================================================
     */
    public function sanitize(string $html): string
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        /**
         * =====================================================================
         * GHI CHÚ: Giữ semantic markup, code example và bảng dữ liệu đơn giản.
         * =====================================================================
         * Attributes vẫn allowlist bên dưới để HTML sao chép không chạy JavaScript.
         * =====================================================================
         */
        $allowed = [
            'html', 'body', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li',
            'blockquote', 'q', 'cite', 'strong', 'em', 'a', 'br', 'hr', 'pre', 'code', 'span',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
            'img', 'figure', 'figcaption', 'h5', 'h6', 'sub', 'sup',
        ];
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $node) {
            if (! in_array(strtolower($node->nodeName), $allowed, true)) {
                if (in_array(strtolower($node->nodeName), ['script', 'style', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'input', 'button', 'select', 'textarea'], true)) {
                    $node->parentNode?->removeChild($node);
                } else {
                    while ($node->firstChild && $node->parentNode) {
                        $node->parentNode->insertBefore($node->firstChild, $node);
                    }
                    $node->parentNode?->removeChild($node);
                }

                continue;
            }
            $tag = strtolower($node->nodeName);
            $safeAttributes = match ($tag) {
                'a' => ['href', 'target', 'rel', 'title'],
                'td', 'th' => ['colspan', 'rowspan', 'scope'],
                'img' => ['src', 'alt', 'title', 'width', 'height', 'data-media-asset-id'],
                default => [],
            };
            for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $node->attributes->item($i);
                $attributeName = strtolower($attribute->name);
                $normalizedUrl = preg_replace('/[\s\p{Cc}\p{Cf}]+/u', '', html_entity_decode($attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
                $urlUnsafe = in_array($attributeName, ['href', 'src'], true)
                    && (str_starts_with($normalizedUrl, '//') || (preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalizedUrl, $match) && ! in_array(strtolower($match[1]), $tag === 'a' ? ['http', 'https', 'mailto'] : ['http', 'https'], true)));
                $idUnsafe = $attributeName === 'data-media-asset-id' && ! preg_match('/^[1-9][0-9]*$/D', $attribute->value);
                $sizeUnsafe = in_array($attributeName, ['width', 'height', 'colspan', 'rowspan'], true) && ! preg_match('/^[1-9][0-9]{0,4}$/D', $attribute->value);
                if (! in_array($attributeName, $safeAttributes, true) || $urlUnsafe || $idUnsafe || $sizeUnsafe) {
                    $node->removeAttributeNode($attribute);
                }
            }
            if ($tag === 'a' && $node->getAttribute('target') === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'img' && ! $node->hasAttribute('src')) {
                $node->parentNode?->removeChild($node);
            }
        }
        $root = $document->getElementsByTagName('div')->item(0);
        $output = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }
        }

        return trim($output);
    }
}
