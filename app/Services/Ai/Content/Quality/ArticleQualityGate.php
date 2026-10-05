<?php

namespace App\Services\Ai\Content\Quality;

use App\Exceptions\AiImportException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Gate copy/ngôn ngữ/code/link/số liệu trên output AI mới.
 * =====================================================================
 * Chạy trước merge/thumbnail; similarity chỉ warning, detector cho phép chưa rõ.
 * CÁC HÀM/METHOD TRONG FILE: inspect(), prose(), language(), preserve(),
 * numberText(), numbers(), missingNumbers(), failure().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : source snapshot, output mới, ngôn ngữ và facts đã có anchors.
 * - OUTPUT: checks pass/skipped/undetermined/warning; lỗi chặn có mã rõ ràng.
 * =====================================================================
 */
final class ArticleQualityGate
{
    /**
     * =====================================================================
     * Input: snapshot, final mới, language, analysis và quality config snapshot.
     * Output: metric safe; ném lỗi copy/ngôn ngữ/grounding với lý do bounded.
     * =====================================================================
     */
    public function inspect(array $source, array $output, string $language, array $analysis = [], array $settings = []): array
    {
        if (! isset($output['content_html'])) {
            return [['check' => 'rewrite', 'status' => 'skipped', 'reason' => 'short_field_task']];
        }
        $fresh = $this->prose($output['content_html']);
        $original = $this->prose($source['content_html']);
        $minimum = $settings['minimum_prose_characters'] ?? 160;
        $checks = [];
        if (mb_strlen($fresh) < $minimum || mb_strlen($original) < $minimum) {
            $checks[] = ['check' => 'copy', 'status' => 'skipped', 'reason' => 'short_prose'];
        } else {
            if ($settings['exact_copy_blocks'] ?? true) {
                $copied = str_contains($fresh, $original);
                foreach ($source['blocks'] ?? [] as $block) {
                    if (in_array($block['type'], ['p', 'li'], true)) {
                        $blockProse = $this->prose($block['html']);
                        $copied = $copied || (mb_strlen($blockProse) >= $minimum && str_contains($fresh, $blockProse));
                    }
                }
                if ($copied) {
                    $this->failure('AI_QUALITY_EXACT_COPY', 'Nội dung AI sao chép nguyên văn phần văn xuôi nguồn.', 'exact_copy');
                }
            }
            $sourceTokens = array_values(array_unique(preg_split('/\s+/u', $original) ?: []));
            $outputTokens = array_values(array_unique(preg_split('/\s+/u', $fresh) ?: []));
            $union = array_unique(array_merge($sourceTokens, $outputTokens));
            $similarity = count(array_intersect($sourceTokens, $outputTokens)) / max(1, count($union));
            $checks[] = ['check' => 'copy', 'status' => 'pass'];
            $checks[] = ['check' => 'similarity', 'status' => $similarity >= ($settings['similarity_warning_threshold'] ?? 0.85) ? 'warning' : 'pass', 'metric' => round($similarity, 3), 'reason' => 'lexical_metric_not_style_score'];
        }
        $checks[] = $this->language($fresh, $language, (int) ($settings['language_minimum_words'] ?? 60));
        $checks = array_merge($checks, $this->preserve($source, $output['content_html'], $analysis));

        return $checks;
    }

    /**
     * =====================================================================
     * Input: HTML; code/quote/ảnh không thuộc copy/language gate.
     * Output: prose Unicode lowercase và whitespace chuẩn; không mutate HTML.
     * =====================================================================
     */
    private function prose(string $html): string
    {
        $html = preg_replace('/<(pre|code|blockquote|q|h[1-6])\b[^>]*>.*?<\/\1>/isu', '', $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|li|tr|td|th|figure|figcaption)>|<br\s*\/?>/i', ' ', $html) ?? $html;
        $text = mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/["“][^"”]{40,}["”]/u', '', $text) ?? $text;
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        return trim(preg_replace('/[\s\p{Z}\p{Cf}]+/u', ' ', $text) ?? $text);
    }

    /**
     * =====================================================================
     * Input: prose mới và language yêu cầu.
     * Output: lexical detector vi/en hoặc undetermined; không dựa riêng dấu Việt.
     * =====================================================================
     */
    private function language(string $text, string $requested, int $minimumWords): array
    {
        $words = preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < $minimumWords || ! in_array($requested, ['vi', 'en'], true)) {
            return ['check' => 'language', 'status' => 'undetermined', 'reason' => 'short_or_unsupported_language'];
        }
        $vietnamese = ['và', 'của', 'được', 'các', 'một', 'không', 'trong', 'cho', 'với', 'khi', 'những', 'này', 'để', 'là', 'có', 'từ'];
        $english = ['the', 'and', 'is', 'are', 'this', 'that', 'with', 'for', 'from', 'which', 'will', 'have', 'your', 'you', 'can', 'not'];
        $vi = count(array_filter($words, fn (string $word): bool => in_array($word, $vietnamese, true)));
        $en = count(array_filter($words, fn (string $word): bool => in_array($word, $english, true)));
        $detected = $vi >= 8 && $vi > $en * 2 ? 'vi' : ($en >= 8 && $en > $vi * 2 ? 'en' : null);
        if ($detected !== null && $detected !== $requested) {
            $this->failure('AI_QUALITY_LANGUAGE', 'Nội dung AI có dấu hiệu sai ngôn ngữ được yêu cầu.', 'dominant_language_mismatch');
        }

        return ['check' => 'language', 'status' => $detected === null ? 'undetermined' : 'pass', 'detected' => $detected];
    }

    /**
     * =====================================================================
     * Input: source có blocks, final HTML và analysis có facts đã kiểm evidence.
     * Output: kiểm code/link/số liệu quan trọng; ngữ nghĩa đầy đủ vẫn do editor duyệt.
     * =====================================================================
     */
    private function preserve(array $source, string $html, array $analysis): array
    {
        $decodedOutput = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ($source['blocks'] ?? [] as $block) {
            if ($block['type'] === 'pre' && ! str_contains(str_replace("\r\n", "\n", $decodedOutput), str_replace("\r\n", "\n", trim($block['text'])))) {
                $this->failure('AI_QUALITY_GROUNDING', 'Nội dung AI làm mất hoặc sửa code nguồn.', 'source_code_changed');
            }
        }
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $source['content_html'], $sourceLinks);
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $html, $finalLinks);
        $decode = fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (array_diff(array_map($decode, $sourceLinks[1]), array_map($decode, $finalLinks[1])) !== []) {
            $this->failure('AI_QUALITY_GROUNDING', 'Nội dung AI làm mất link tham khảo trong nguồn.', 'source_link_missing');
        }
        $outputNumbers = $this->numbers($this->numberText($html));
        foreach ($analysis['knowledge']['facts'] ?? [] as $fact) {
            if ($fact['important'] && $this->missingNumbers($this->numbers($fact['evidence']), $outputNumbers) !== []) {
                $this->failure('AI_QUALITY_GROUNDING', 'Nội dung AI thiếu số liệu hoặc phiên bản trong bằng chứng quan trọng.', 'important_number_missing');
            }
        }
        $sourceNumbers = $this->numbers($this->numberText($source['content_html']));
        $unanchoredNumbers = $this->missingNumbers($outputNumbers, $sourceNumbers, allowGroupedExpected: true);

        return [
            ['check' => 'source_code_links', 'status' => 'pass'],
            ['check' => 'important_numbers', 'status' => 'pass'],
            ['check' => 'unanchored_numbers', 'status' => $unanchoredNumbers === [] ? 'pass' : 'warning', 'count' => count($unanchoredNumbers), 'reason' => 'requires_editor_review'],
            ['check' => 'semantic_grounding', 'status' => 'undetermined', 'reason' => 'source_anchors_are_not_external_fact_verification'],
        ];
    }

    /**
     * =====================================================================
     * Input: HTML nguồn/final. Output: text có ranh giới ô bảng/khối văn bản.
     * Không ghép 2017 và 5 ở hai ô thành 20175; không mutate HTML/code để lưu.
     * =====================================================================
     */
    private function numberText(string $html): string
    {
        $html = preg_replace('/<\/(?:p|div|li|tr|td|th|h[1-6]|pre|figure|figcaption)>|<br\s*\/?>/i', ' ', $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * =====================================================================
     * Input: tokens phải giữ và tokens đối chiếu. Output: tokens chưa khớp.
     * Chỉ số nguyên nguồn >= 4 chữ số được chấp nhận dạng nhóm nghìn ở output.
     * Token nguồn có dấu chấm giữ nguyên (version/decimal có thể trông như nhóm nghìn).
     * Đối chiếu warning chiều ngược có thể bật allowGroupedExpected, không nới gate.
     * Không tự suy diễn số viết bằng chữ hoặc thế kỷ của năm hai chữ số.
     * =====================================================================
     */
    private function missingNumbers(array $expected, array $actual, bool $allowGroupedExpected = false): array
    {
        return array_values(array_filter($expected, function (string $token) use ($actual, $allowGroupedExpected): bool {
            if (in_array($token, $actual, true)) {
                return false;
            }
            if (! ctype_digit($token) && ! $allowGroupedExpected) {
                return true;
            }
            foreach ($actual as $other) {
                $plain = ctype_digit($token) ? $token : $other;
                $grouped = ctype_digit($token) ? $other : $token;
                if (preg_match('/^\d{4,}$/D', $plain) && preg_match('/^[1-9]\d{0,2}([.,])\d{3}(?:\1\d{3})*$/D', $grouped)
                    && str_replace(['.', ','], '', $grouped) === $plain) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * =====================================================================
     * Input: text nguồn/final.
     * Output: tokens số/phiên bản duy nhất; không coi regex là kiểm ngữ nghĩa.
     * =====================================================================
     */
    private function numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * =====================================================================
     * Input: mã lỗi, message chung và mã lý do.
     * Output: ném lỗi chất lượng; không lưu source excerpts vào diagnostics public.
     * =====================================================================
     */
    private function failure(string $code, string $message, string $reason): never
    {
        throw new AiImportException($message, $code, diagnostics: ['stage' => 'validate', 'validation_errors' => [['group' => 'content', 'field' => 'content_html', 'reason' => $reason]]]);
    }
}
