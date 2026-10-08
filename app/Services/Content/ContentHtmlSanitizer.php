<?php

namespace App\Services\Content;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Làm sạch HTML không tin cậy trước khi lưu hoặc hiển thị.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: sanitize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML do editor, admin hoặc pipeline AI cung cấp.
 * - OUTPUT: semantic HTML theo allowlist, không script/event/URL thực thi.
 * =====================================================================
 */
class ContentHtmlSanitizer
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ markup bài viết hợp lệ và loại bỏ HTML nguy hiểm.
     * =====================================================================
     * INPUT: HTML UTF-8 không tin cậy.
     * OUTPUT: HTML semantic đã sanitize, giữ code whitespace và media refs.
     * SIDE EFFECT: không ghi database, không tải URL hoặc truy cập media.
     * EXCEPTION/TRANSACTION: libxml lỗi markup được xử lý như HTML rỗng/phẳng;
     * không mở transaction.
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
         * Attribute phải đi qua allowlist; CSS chỉ cho phép text-align với bốn
         * giá trị cố định để giữ định dạng editor mà không nhận CSS tùy ý.
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
            $styleAllowed = in_array($tag, [
                'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre',
                'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'figure', 'figcaption', 'span',
            ], true);
            $safeAttributes = match ($tag) {
                'a' => ['href', 'target', 'rel', 'title'],
                'td', 'th' => ['colspan', 'rowspan', 'scope'],
                'img' => ['src', 'alt', 'title', 'width', 'height', 'data-media-asset-id'],
                'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'figure', 'figcaption', 'span' => ['style'],
                default => [],
            };
            for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $node->attributes->item($i);
                $attributeName = strtolower($attribute->name);
                if ($attributeName === 'style') {
                    $safeStyle = $styleAllowed ? $this->sanitizeStyle($attribute->value) : null;
                    if ($safeStyle === null) {
                        $node->removeAttributeNode($attribute);
                    } else {
                        $node->setAttribute('style', $safeStyle);
                    }

                    continue;
                }
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ một tập CSS inline tối thiểu cho bố cục bài viết.
     * =====================================================================
     * INPUT: chuỗi style do editor/admin gửi.
     * OUTPUT: `text-align` an toàn hoặc null nếu không còn declaration hợp lệ.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    private function sanitizeStyle(string $style): ?string
    {
        $safe = [];
        foreach (preg_split('/;/', html_entity_decode($style, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $declaration) {
            if (preg_match('/^\s*text-align\s*:\s*(left|center|right|justify)\s*$/i', $declaration, $match)) {
                $safe[] = 'text-align:'.strtolower($match[1]);
            }
        }

        return $safe === [] ? null : implode(';', array_values(array_unique($safe)));
    }
}
