<?php

namespace App\Services\Ai\Content\Evaluation;

use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm hai bảng chấm người, không điền hoặc suy điểm còn thiếu.
 * CÁC HÀM/METHOD: fields(), readCsv(), summarize(), validateRow(), distribution().
 * INPUT/OUTPUT: rows CSV + nhãn mù/output có bài -> tiến độ/điểm/bất đồng.
 * SIDE EFFECT: readCsv chỉ đọc file; không AI, DB, viết CSV hoặc quyết định rollout.
 * Lỗi critical/major và coverage báo riêng khỏi điểm diễn đạt.
 * =====================================================================
 */
final class ArticleQualityHumanReview
{
    public const SCORES = ['naturalness_1_5', 'usefulness_1_5', 'structure_1_5', 'repetition_1_5', 'brief_style_1_5'];

    public const COUNTS = ['critical_errors', 'major_errors', 'minor_errors', 'important_facts_preserved', 'important_facts_expected', 'fact_edits', 'expression_edits', 'paragraphs_added', 'paragraphs_removed'];

    /**
     * =====================================================================
     * Input: không có. Output: hợp đồng CSV của bộ chấm v2.
     * =====================================================================
     */
    public function fields(): array
    {
        return array_merge(['case_id', 'label', 'bundle_sha256', 'reviewer', 'review_state', 'source_facts_confirmed', 'accuracy_gate', 'facts_errors_and_severity', 'important_fact_evidence'], self::SCORES, self::COUNTS, ['editing_minutes', 'coverage_notes', 'preservation_notes', 'paired_preference']);
    }

    /**
     * =====================================================================
     * Input: CSV UTF-8 có header, quote/newline/BOM. Output: rows nguyên giá trị.
     * Thiếu file/column, lệch số cột hoặc lặp header phải báo lỗi, không bỏ dòng.
     * =====================================================================
     */
    public function readCsv(string $path): array
    {
        $stream = fopen($path, 'r');
        if (! $stream) {
            throw new InvalidArgumentException('Không đọc được CSV người chấm.');
        }
        try {
            $header = fgetcsv($stream, escape: '');
            if (! is_array($header)) {
                throw new InvalidArgumentException('CSV không có header.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            if (array_diff($this->fields(), $header) !== [] || count(array_unique($header)) !== count($header)) {
                throw new InvalidArgumentException('CSV không đúng hợp đồng chấm v2.');
            }
            $rows = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new InvalidArgumentException('Dòng CSV lệch số cột.');
                }
                $rows[] = array_combine($header, $values);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /**
     * =====================================================================
     * Input: hai bộ rows độc lập, blind key, những output có bài để đọc.
     * Output: số hoàn thành, phân bố theo nhánh, preference và bất đồng facts.
     * Reviewer đã hoàn thành phải có danh tính khác nhau, không giả hai người.
     * =====================================================================
     */
    public function summarize(array $readers, array $key, array $available, string $bundleHash): array
    {
        if (count($readers) !== 2) {
            throw new InvalidArgumentException('Cần đúng hai bảng người chấm độc lập.');
        }
        $expected = [];
        foreach ($key as $case => $labels) {
            foreach ($labels as $label => $arm) {
                $expected[$case.'-'.$label] = ['case_id' => $case, 'label' => $label, 'arm' => $arm];
            }
        }
        $valid = [];
        $progress = [];
        $identities = [];
        foreach ($readers as $reader => $rows) {
            $seen = [];
            $names = [];
            foreach ($rows as $row) {
                $id = ($row['case_id'] ?? '').'-'.($row['label'] ?? '');
                if (! isset($expected[$id]) || isset($seen[$id])) {
                    throw new InvalidArgumentException('CSV có nhãn lạ hoặc lặp: '.$id);
                }
                if (($row['bundle_sha256'] ?? '') !== $bundleHash) {
                    throw new InvalidArgumentException('CSV không thuộc nguồn/output/nhãn của bộ chấm này.');
                }
                $seen[$id] = true;
                $completed = $this->validateRow($row, array_keys($key[$row['case_id']]), (bool) ($available[$row['case_id']][$expected[$id]['arm']] ?? false));
                if ($completed) {
                    $valid[$reader][$id] = array_replace($row, $expected[$id]);
                    $names[mb_strtolower(trim($row['reviewer']))] = true;
                }
            }
            if (count($seen) !== count($expected) || count($names) > 1) {
                throw new InvalidArgumentException('Mỗi bảng phải đủ nhãn và thuộc một người đọc.');
            }
            $identities[$reader] = array_key_first($names);
            $progress[$reader] = ['completed' => count($valid[$reader] ?? []), 'available' => array_sum(array_map(fn (array $arms): int => count(array_filter($arms)), $available)), 'reviewer' => array_key_first($names)];
        }
        $names = array_values(array_filter($identities, fn ($name): bool => $name !== null));
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('Hai bảng chấm phải thuộc hai người khác nhau.');
        }
        $arms = [];
        foreach (array_values(array_unique(array_column($expected, 'arm'))) as $arm) {
            $armRows = [];
            foreach ($valid as $rows) {
                foreach ($rows as $row) {
                    if ($row['arm'] === $arm) {
                        $armRows[] = $row;
                    }
                }
            }
            $arms[$arm] = ['completed_ratings' => count($armRows), 'accuracy' => [], 'criteria' => [], 'editing' => []];
            foreach (['pass', 'fail', 'undetermined'] as $gate) {
                $arms[$arm]['accuracy'][$gate] = count(array_filter($armRows, fn (array $row): bool => $row['accuracy_gate'] === $gate));
            }
            foreach (self::SCORES as $field) {
                $arms[$arm]['criteria'][$field] = $this->distribution(array_column($armRows, $field));
            }
            foreach (self::COUNTS as $field) {
                $group = in_array($field, ['fact_edits', 'expression_edits', 'paragraphs_added', 'paragraphs_removed'], true) ? 'editing' : 'accuracy';
                $arms[$arm][$group][$field] = $this->distribution(array_column($armRows, $field));
            }
            $arms[$arm]['editing']['minutes'] = $this->distribution(array_column($armRows, 'editing_minutes'));
        }
        $disagreements = [];
        $preferences = [];
        foreach ($key as $case => $labels) {
            $casePreferences = [];
            foreach (array_keys($readers) as $reader) {
                $pair = array_values(array_filter($valid[$reader] ?? [], fn (array $row): bool => $row['case_id'] === $case));
                if (count($labels) > 1 && count($pair) === count($labels)) {
                    $choices = array_unique(array_column($pair, 'paired_preference'));
                    if (count($choices) !== 1) {
                        throw new InvalidArgumentException('Preference hai output của cùng nguồn phải nhất quán.');
                    }
                    $choice = $choices[0];
                    $casePreferences[$reader] = $labels[$choice] ?? $choice;
                }
            }
            $preferences[$case] = $casePreferences;
            foreach ($labels as $label => $arm) {
                $ratings = array_column($valid, $case.'-'.$label);
                if (count($ratings) === 2) {
                    $fields = ['accuracy_gate', 'critical_errors', 'major_errors', 'important_facts_preserved', 'important_facts_expected'];
                    $different = array_values(array_filter($fields, fn (string $field): bool => (string) $ratings[0][$field] !== (string) $ratings[1][$field]));
                    if ($different !== []) {
                        $disagreements[] = ['case_id' => $case, 'arm' => $arm, 'fields' => $different, 'resolution' => 'pending_human_evidence_review'];
                    }
                }
            }
        }
        $complete = array_sum(array_column($progress, 'completed')) === array_sum(array_column($progress, 'available')) && array_sum(array_column($progress, 'available')) > 0;

        return ['status' => $complete ? 'independent_reviews_complete' : 'pending_human', 'progress' => $progress, 'arms' => $arms, 'paired_preferences' => $preferences, 'fact_disagreements' => $disagreements, 'rollout' => 'pending_owner_decision'];
    }

    /**
     * =====================================================================
     * Input: row/nhãn thật/bài có hay không. Output: completed hoặc pending.
     * Không biến ô trống thành 0; fail khi pass che lỗi critical/major/coverage.
     * =====================================================================
     */
    private function validateRow(array $row, array $labels, bool $available): bool
    {
        foreach (array_merge(self::SCORES, self::COUNTS, ['editing_minutes']) as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $score = in_array($field, self::SCORES, true);
            if ($field === 'editing_minutes' ? (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) : ! preg_match('/^\d+$/D', $value)) {
                throw new InvalidArgumentException('Giá trị chấm không hợp lệ: '.$field);
            }
            if ($score && ((int) $value < 1 || (int) $value > 5)) {
                throw new InvalidArgumentException('Điểm diễn đạt phải từ 1 đến 5.');
            }
            if (! $score && (float) $value > 1000000) {
                throw new InvalidArgumentException('Số chấm vượt giới hạn hợp lệ.');
            }
        }
        if (! in_array($row['review_state'] ?? '', ['', 'pending', 'completed'], true)) {
            throw new InvalidArgumentException('Trạng thái chấm không hợp lệ.');
        }
        if (($row['paired_preference'] ?? '') !== '' && ! in_array($row['paired_preference'], array_merge($labels, ['tie', 'undetermined']), true)) {
            throw new InvalidArgumentException('Preference không thuộc nhãn mù của nguồn.');
        }
        if (count($labels) === 1 && ($row['paired_preference'] ?? '') !== '') {
            throw new InvalidArgumentException('Phiên chỉ có C không có lựa chọn ưu tiên giữa hai bài.');
        }
        if (($row['review_state'] ?? '') !== 'completed') {
            return false;
        }
        $required = array_merge(self::SCORES, self::COUNTS, ['editing_minutes', 'reviewer', 'accuracy_gate'], count($labels) > 1 ? ['paired_preference'] : []);
        foreach ($required as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                throw new InvalidArgumentException('Bài đánh dấu hoàn thành còn thiếu: '.$field);
            }
        }
        if (! $available || ($row['source_facts_confirmed'] ?? '') !== 'yes' || ! in_array($row['accuracy_gate'], ['pass', 'fail', 'undetermined'], true)) {
            throw new InvalidArgumentException('Phải có bài và đối chiếu dữ kiện từ nguồn trước khi hoàn thành.');
        }
        $preserved = (int) $row['important_facts_preserved'];
        $expected = (int) $row['important_facts_expected'];
        $hasErrors = (int) $row['critical_errors'] + (int) $row['major_errors'] + (int) $row['minor_errors'] > 0 || $preserved < $expected;
        if ($preserved > $expected || ($expected > 0 && trim($row['important_fact_evidence'] ?? '') === '') || ($hasErrors && trim($row['facts_errors_and_severity'] ?? '') === '')) {
            throw new InvalidArgumentException('Coverage/lỗi phải có dẫn chứng từ nguồn và câu output.');
        }
        if ($row['accuracy_gate'] === 'pass' && ((int) $row['critical_errors'] > 0 || (int) $row['major_errors'] > 0 || $preserved < $expected)) {
            throw new InvalidArgumentException('Accuracy không thể pass khi còn lỗi critical/major hoặc thiếu dữ kiện quan trọng.');
        }

        return true;
    }

    /**
     * =====================================================================
     * Input: chỉ những điểm/số được người đọc hoàn thành. Output: n/min/median/max.
     * =====================================================================
     */
    public function distribution(array $values): array
    {
        if ($values === []) {
            return ['n' => 0, 'min' => null, 'median' => null, 'max' => null];
        }
        $numbers = array_map('floatval', $values);
        sort($numbers);
        $count = count($numbers);

        return ['n' => $count, 'min' => $numbers[0], 'median' => ($numbers[(int) floor(($count - 1) / 2)] + $numbers[(int) floor($count / 2)]) / 2, 'max' => $numbers[$count - 1]];
    }
}
