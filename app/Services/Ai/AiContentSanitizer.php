<?php

namespace App\Services\Ai;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Làm sạch HTML của nguồn, AI output và editor theo một allowlist.
 * CÁC HÀM/METHOD TRONG FILE: sanitize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTML không tin cậy -> HTML không có script/event.
 * =====================================================================
 */
final class AiContentSanitizer
{
    /** Input: HTML. Output: semantic HTML an toàn; chỉ xử lý DOM trong memory. */
    public function sanitize(string $html): string
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        /**
         * =====================================================================
         * GHI CHÚ: Giữ semantic markup, code example và bảng dữ liệu đơn giản.
         * =====================================================================
         * Attributes vẫn allowlist bên dưới để HTML sao chép không chạy JavaScript.
         * =====================================================================
         */
        $allowed = [
            'html', 'body', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li',
            'blockquote', 'strong', 'em', 'a', 'br', 'hr', 'pre', 'code', 'span',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        ];
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $node) {
            if (! in_array(strtolower($node->nodeName), $allowed, true)) {
                $node->parentNode?->removeChild($node);

                continue;
            }
            $tag = strtolower($node->nodeName);
            $safeAttributes = match ($tag) {
                'a' => ['href', 'target', 'rel', 'title'],
                'td', 'th' => ['colspan', 'rowspan', 'scope'],
                default => [],
            };
            for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $node->attributes->item($i);
                if (! in_array(strtolower($attribute->name), $safeAttributes, true) || ($tag === 'a' && preg_match('/^(javascript|data|vbscript):/i', trim($attribute->value)))) {
                    $node->removeAttributeNode($attribute);
                }
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
