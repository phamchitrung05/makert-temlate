<?php

namespace App\Services\Ai\Content\Quality;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đối chiếu cách viết số tương đương có ngữ cảnh rõ ràng.
 * =====================================================================
 * Không làm tròn, đổi đơn vị tiền/tỷ lệ hoặc bỏ điều kiện nguồn. Semantic
 * grounding vẫn cần người đọc; phiên bản có dấu chấm giữ nguyên.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - tokens(): tổng hợp số và ngữ cảnh sau chuẩn hóa.
 * - integerCounts(): chuẩn hóa dấu nhóm nghìn của số lượng nguyên.
 * - dates(): chuẩn hóa ngày/tháng và giữ năm đã nêu.
 * - times(): chuẩn hóa giờ/phút theo định dạng 12/24 giờ.
 * - centuries(): đối chiếu số thế kỷ và chữ số La Mã.
 * - listLabels(): bỏ thứ tự trình bày của nhãn nội dung liệt kê.
 * - counts(): chuẩn hóa số đếm có đơn vị rõ ràng.
 * - weekdays(): giữ số ngày và danh sách thứ trong tuần.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : plain text từ bài nguồn hoặc output AI.
 * - OUTPUT: tokens số và ngày/giờ/thế kỷ; không sửa HTML lưu.
 * =====================================================================
 * =====================================================================
 */
final class ArticleNumberNormalizer
{
    /** Input: text nguồn/output. Output: tokens chuẩn hóa theo ngữ cảnh hẹp. */
    public function tokens(string $text): array
    {
        $contexts = [];
        $text = $this->dates($text, $contexts);
        $text = $this->times($text, $contexts);
        $text = $this->centuries($text, $contexts);
        $text = $this->weekdays($text, $contexts);
        $text = $this->wordNumbers($text);
        $text = $this->listLabels($text);
        $text = $this->counts($text);
        $text = $this->integerCounts($text);
        $quarters = ['I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4'];
        $text = preg_replace_callback('/\b(?:quý|quarter)[\s\p{Z}]+(IV|III|II|I)\b/iu', fn (array $match): string => 'quarter '.$quarters[strtoupper($match[1])], $text) ?? $text;
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $matches);

        return array_values(array_unique(array_merge($matches[0], $contexts)));
    }

    /** Input: văn xuôi có số viết bằng chữ Anh/Việt. Output: số chữ số tương đương để đối chiếu. */
    private function wordNumbers(string $text): string
    {
        $numbers = [
            'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
            'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10',
        ];
        $pattern = '/(?<![\pL\pN])('.implode('|', array_keys($numbers)).')(?![\pL\pN])/iu';

        $normalized = preg_replace_callback($pattern, function (array $match) use ($numbers, $text): string {
            $offset = (int) ($match[0][1] ?? 0);
            $before = mb_strtolower(substr($text, max(0, $offset - 60), 60));
            $after = mb_strtolower(substr($text, $offset + strlen($match[0][0]), 60));
            if (preg_match('/(?:twenty|thirty|forty|mười|mươi|trăm|phẩy|point)\s*$/u', $before)
                || preg_match('/^(?:groups?|nhóm|rưỡi|half)\b/u', ltrim($after))
                || preg_match('/^(?:nội\s+dung)\s+rưỡi\b/u', ltrim($after))
                || preg_match('/^(?:zero|one|two|three|four|five|six|seven|eight|nine|ten|không|một|hai|ba|bốn|năm|sáu|bảy|tám|chín|mười)\b/u', ltrim($after))) {
                return $match[0][0];
            }

            return $numbers[mb_strtolower($match[0][0])] ?? $match[0][0];
        }, $text, -1, $count, PREG_OFFSET_CAPTURE) ?? $text;

        return preg_replace_callback('/(?<![\pL\pN])(một|ba)(?=\s+(?:câu\s+hỏi|yêu\s+cầu|điểm)\b)/iu', fn (array $match): string => mb_strtolower($match[1]) === 'ba' ? '3' : '1', $normalized) ?? $normalized;
    }

    /** Input: số lượng nguyên có đơn vị đếm rõ. Output: nhóm nghìn cùng giá trị. */
    private function integerCounts(string $text): string
    {
        // Chỉ số đếm runner/job/event; không áp vào tiền, phần trăm hoặc phiên bản.
        // Maximum gắn trực tiếp với loại runner vẫn là một số lượng nguyên.
        $unit = '(?:check\\s+runs?|runners?|jobs?|workflow\\s+runs?|events?|sự\\s+kiện|max(?:imum)?\\s+for\\s+(?:[\\pL-]+\\s+){0,4}runners?)';

        // Exact evidence từ bảng cũ có thể liền cell: "suite50,000 check runs".
        // Đơn vị đếm phía sau vẫn bắt buộc; version/phiên bản/v không được đổi.
        return preg_replace_callback('/(?<![\\pN.,])(?<prefix>(?:version|phiên\\s+bản|v)\\s*)?(?<amount>[1-9]\\d{0,2}(?<separator>[.,])\\d{3}(?:\\k<separator>\\d{3})*)(?=\\s+'.$unit.'(?-i:\\b|[A-Z]))/iu', fn (array $match): string => ($match['prefix'] ?? '') !== '' ? $match[0] : str_replace(['.', ','], '', $match['amount']), $text) ?? $text;
    }

    /** Input: dates có ngày/tháng hợp lệ. Output: không coi 03 là khác 3. */
    private function dates(string $text, array &$contexts): string
    {
        $replace = function (array $match) use (&$contexts): string {
            $day = (int) $match[1];
            $month = (int) $match[2];
            $year = (int) ($match[3] ?? 0);
            if (! checkdate($month, $day, $year >= 100 ? $year : 2000 + $year)) {
                return $match[0];
            }
            $contexts[] = 'date:'.$day.'/'.$month;
            if ($year >= 100) {
                $contexts[] = 'date:'.$day.'/'.$month.'/'.$year;
            }
            if (isset($match[3]) && $match[3] !== '') {
                // YY chỉ chốt hai chữ số năm; không tự suy ra thế kỷ từ nguồn.
                $contexts[] = 'date:'.$day.'/'.$month.'/@'.($year % 100);
            }

            return $year >= 100 ? ' '.$year.' ' : ' ';
        };
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $text = preg_replace_callback('/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+(\d{1,2}),?\s+(\d{4})\b/iu', fn (array $match): string => $replace([$match[0], $match[2], (string) $months[strtolower(substr($match[1], 0, 3))], $match[3]]), $text) ?? $text;
        $text = preg_replace_callback('/\bngày\s+(\d{1,2})\s+tháng\s+(\d{1,2})(?:\s+năm\s+(\d{4}))?\b/iu', $replace, $text) ?? $text;

        return preg_replace_callback('/(?<![\pL\pN.])(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{4}|\d{2}))?(?![\/\-\pN])/u', $replace, $text) ?? $text;
    }

    /** Input: giờ 12/24 giờ hoặc 8h00. Output: giữ cùng giờ/phút, AM/PM đúng. */
    private function times(string $text, array &$contexts): string
    {
        return preg_replace_callback('/(?<![\pL\pN.])(\d{1,2})[:h](\d{2})(?:\s*(AM|PM))?(?![\pL\pN])/iu', function (array $match) use (&$contexts): string {
            $hour = (int) $match[1];
            $minute = (int) $match[2];
            $period = strtoupper($match[3] ?? '');
            if ($minute > 59 || $hour > 23 || ($period !== '' && ($hour < 1 || $hour > 12))) {
                return $match[0];
            }
            if ($period !== '') {
                $hour = $hour % 12 + ($period === 'PM' ? 12 : 0);
            }
            $contexts[] = 'time:'.$hour.':'.$minute;

            return ' ';
        }, $text) ?? $text;
    }

    /** Input: thế kỷ I–XXXIX hoặc ordinal tiếng Anh. Output: cùng thế kỷ. */
    private function centuries(string $text, array &$contexts): string
    {
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI', 'XVII', 'XVIII', 'XIX', 'XX', 'XXI', 'XXII', 'XXIII', 'XXIV', 'XXV', 'XXVI', 'XXVII', 'XXVIII', 'XXIX', 'XXX', 'XXXI', 'XXXII', 'XXXIII', 'XXXIV', 'XXXV', 'XXXVI', 'XXXVII', 'XXXVIII', 'XXXIX'];
        $record = function (int $number) use (&$contexts): string {
            $contexts[] = 'century:'.$number;

            return ' '.$number.' ';
        };
        $text = preg_replace_callback('/\bthế\s+kỷ\s+([IVX]+|\d{1,2})\b/iu', function (array $match) use ($roman, $record): string {
            $index = array_search(strtoupper($match[1]), $roman, true);
            $number = ctype_digit($match[1]) ? (int) $match[1] : ($index === false ? 0 : $index + 1);

            return $number >= 1 && $number <= 39 ? $record($number) : $match[0];
        }, $text) ?? $text;
        $text = preg_replace_callback('/(?<![\pL\pN])(\d{1,2})(?:st|nd|rd|th)?\s+century\b/iu', fn (array $match): string => (int) $match[1] >= 1 && (int) $match[1] <= 39 ? $record((int) $match[1]) : $match[0], $text) ?? $text;
        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fifteenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth', 'twentieth'];

        return preg_replace_callback('/(?<![\pL-])(?:(twenty|thirty|forty|hundred)\s+)?('.implode('|', $ordinals).')\s+century\b/iu', fn (array $match): string => ($match[1] ?? '') !== '' ? $match[0] : $record(array_search(strtolower($match[2]), $ordinals, true) + 1), $text) ?? $text;
    }

    /**
     * Bỏ số thứ tự của nhãn “Nội dung thứ 2 là/về” để bài được đổi bố cục.
     *
     * Input: text có nhãn nội dung liệt kê; thứ của hội nghị/điều khoản giữ nguyên.
     * Output: text dùng để đối chiếu số liệu, giữ số văn bản và số lượng nội dung.
     */
    private function listLabels(string $text): string
    {
        return preg_replace('/\bnội[\s\p{Z}]+dung[\s\p{Z}]+thứ[\s\p{Z}]+(?:[1-9]|nhất|hai|ba|tư|bốn|năm|sáu|bảy|tám|chín)(?=[\s\p{Z}]+(?:là|về)\b)/iu', 'nội dung', $text) ?? $text;
    }

    /** Input: số đếm 1–9 trước tầng/nhóm/ngày/nội dung. Output: không lấy phần của số lớn. */
    private function counts(string $text): string
    {
        $counts = ['một' => '1', 'hai' => '2', 'ba' => '3', 'bốn' => '4', 'năm' => '5', 'sáu' => '6', 'bảy' => '7', 'tám' => '8', 'chín' => '9'];
        $unit = '(?:tầng|nhóm|ngày|nội[\s\p{Z}]+dung)';
        $text = preg_replace_callback('/\b(?:(mười|mươi|trăm|nghìn|ngàn|triệu|tỷ|lẻ|linh|chấm|phẩy|phần|âm)[\s\p{Z}]+)?(một|hai|ba|bốn|năm|sáu|bảy|tám|chín)[\s\p{Z}]+('.$unit.')\b(?![\s\p{Z}]+(?:rưỡi\b|và[\s\p{Z}]+(?:một|1)[\s\p{Z}]+nửa\b))/iu', fn (array $match): string => ($match[1] ?? '') !== '' ? $match[0] : $counts[mb_strtolower($match[2])].' '.$match[3], $text) ?? $text;

        return preg_replace_callback('/(?<![\pL\pN.,])0+([1-9]\d*)[\s\p{Z}]+('.$unit.')\b/iu', fn (array $match): string => $match[1].' '.$match[2], $text) ?? $text;
    }

    /** Input: danh sách ít nhất hai thứ trong tuần. Output: số ngày và chính danh sách. */
    private function weekdays(string $text, array &$contexts): string
    {
        $day = '(?:thứ\s+(?:hai|ba|tư|bốn|năm|sáu|bảy|[2-7])|chủ\s+nhật)';

        return preg_replace_callback('/\b'.$day.'(?:\s*(?:,|và)\s*'.$day.')+\b/iu', function (array $match) use (&$contexts, $day): string {
            preg_match_all('/'.$day.'/iu', $match[0], $days);
            $values = ['hai' => 2, 'ba' => 3, 'tư' => 4, 'bốn' => 4, 'năm' => 5, 'sáu' => 6, 'bảy' => 7, 'nhật' => 8];
            $numbers = array_map(function (string $value) use ($values): int {
                $parts = preg_split('/\s+/u', mb_strtolower($value));
                $last = end($parts);

                return $values[$last] ?? (int) $last;
            }, $days[0]);
            $numbers = array_values(array_unique($numbers));
            sort($numbers);
            $contexts[] = 'weekdays:'.implode(',', $numbers);

            return ' '.count($numbers).' ngày ';
        }, $text) ?? $text;
    }
}
