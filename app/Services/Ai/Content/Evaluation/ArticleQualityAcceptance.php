<?php

namespace App\Services\Ai\Content\Evaluation;

use App\Services\Ai\Content\Data\ArticleInputHasher;
use InvalidArgumentException;

/**
 * Evaluates agreed criteria against a validated study without inventing scores or approving rollout.
 * Methods: evaluate(), markdown(), validateCriteria().
 * Input: validated study/criteria arrays. Output: acceptance checks or Markdown.
 * No database writes, HTTP calls, model calls or Apply/Publish actions.
 */
final class ArticleQualityAcceptance
{
    /** Input: validated study and owner criteria. Output: passed/failed/pending checks, no side effects. */
    public function evaluate(array $report, array $criteria): array
    {
        $this->validateCriteria($criteria);
        if (($report['version'] ?? '') !== 'article.quality.study.v2' || empty($report['bundle_sha256']) || empty($report['arms']['C'])) {
            throw new InvalidArgumentException('Cần báo cáo study v2 đã kiểm nguồn, artifacts và hai phiếu chấm.');
        }

        $checks = [];
        $add = static function (string $id, string $label, mixed $observed, mixed $required, ?bool $passed) use (&$checks): void {
            $checks[] = compact('id', 'label', 'observed', 'required') + ['status' => $passed === null ? 'pending' : ($passed ? 'passed' : 'failed')];
        };
        $approved = $criteria['status'] === 'approved' && trim($criteria['approved_by']) !== '';
        $add('criteria', 'Tiêu chí được chủ dự án xác nhận', $criteria['status'], 'approved + approved_by', $approved ? true : null);
        $add('cases', 'Số ca đã freeze', $report['cases'], $criteria['minimum_cases'], $report['cases'] >= $criteria['minimum_cases']);
        $add('sources', 'Số nguồn độc lập', $report['unique_sources'], $criteria['minimum_unique_sources'], $report['unique_sources'] >= $criteria['minimum_unique_sources']);
        $missingLanguages = array_values(array_diff($criteria['required_source_languages'], array_keys(array_filter($report['source_languages']))));
        $add('languages', 'Coverage ngôn ngữ nguồn', array_keys($report['source_languages']), $criteria['required_source_languages'], $missingLanguages === []);
        $unresolvedGaps = array_values(array_diff($report['coverage_gaps'], $criteria['accepted_coverage_gaps']));
        $add('coverage', 'Khoảng trống coverage cần xác nhận phạm vi', $unresolvedGaps, [], $unresolvedGaps === []);

        $runs = $report['arms']['C'];
        $readyRate = $runs['runs'] > 0 ? $runs['ready'] / $runs['runs'] : 0;
        $add('technical', 'Tỷ lệ run C hoàn tất trong study gốc', $readyRate, $criteria['minimum_ready_rate'], $readyRate >= $criteria['minimum_ready_rate']);
        $human = $report['human_review'];
        $progress = array_values($human['progress']);
        $names = array_map(static fn (array $reader): string => mb_strtolower(trim((string) $reader['reviewer'])), $progress);
        $complete = $human['status'] === 'independent_reviews_complete' && count($progress) === 2
            && count(array_unique(array_filter($names))) === 2
            && array_sum(array_column($progress, 'completed')) === 2 * $runs['runs'];
        $add('readers', 'Hai người chấm độc lập hoàn thành mọi ca C', $human['progress'], 2 * $runs['runs'], $complete ? true : null);
        $add('disagreements', 'Bất đồng facts được giải quyết bằng dẫn chứng', $human['fact_disagreements'], [], $complete ? $human['fact_disagreements'] === [] : null);
        $ratings = $human['arms']['C'];
        foreach (['critical_errors', 'major_errors'] as $field) {
            $actual = $ratings['accuracy'][$field]['max'] ?? null;
            $add($field, 'Số lỗi '.$field.' lớn nhất mỗi bài', $actual, $criteria['maximum_'.$field], $complete && $actual !== null ? $actual <= $criteria['maximum_'.$field] : null);
        }
        $accuracyPasses = $ratings['accuracy']['pass'] ?? 0;
        $add('accuracy', 'Mọi bài đạt accuracy do người đọc xác nhận', $accuracyPasses, 2 * $runs['runs'], $complete ? $accuracyPasses === 2 * $runs['runs'] : null);
        foreach (ArticleQualityHumanReview::SCORES as $field) {
            $actual = $ratings['criteria'][$field]['median'] ?? null;
            $add($field, 'Điểm trung vị '.$field, $actual, $criteria['minimum_median_score'], $complete && $actual !== null ? $actual >= $criteria['minimum_median_score'] : null);
        }
        $minutes = $ratings['editing']['minutes']['median'] ?? null;
        $limit = $criteria['maximum_median_editing_minutes'];
        $add('editing', 'Phút chỉnh sửa trung vị', $minutes, $limit ?? 'Ghi nhận, chưa chốt giới hạn', $complete && $minutes !== null && $limit !== null ? $minutes <= $limit : null);

        $pending = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'pending'));
        $failed = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'failed'));

        return [
            'version' => 'article.quality.acceptance.v1', 'bundle_sha256' => $report['bundle_sha256'],
            'criteria_sha256' => ArticleInputHasher::hash($criteria),
            'status' => $failed !== [] ? 'criteria_not_met' : ($pending !== [] ? 'pending_evidence' : 'ready_for_owner_decision'),
            'checks' => $checks, 'pending_checks' => count($pending), 'failed_checks' => count($failed),
            'rollout' => 'pending_owner_decision', 'scores_filled_by_agent' => false,
            'cost' => $runs['cost'], 'unknown_usage_runs' => $runs['unknown_usage_runs'],
            'scope' => 'C only; technical rechecks never rewrite original run status; no baseline improvement claim',
        ];
    }

    /** Input: evaluated checks. Output: Markdown preserving pending human evidence. */
    public function markdown(array $result): string
    {
        $lines = ['# Điều kiện nghiệm thu AI Content', '', '**Trạng thái:** '.$result['status'], '',
            '| Điều kiện | Kết quả | Hiện tại | Yêu cầu |', '| --- | --- | --- | --- |'];
        foreach ($result['checks'] as $check) {
            $display = static fn (mixed $value): string => str_replace(['|', "\n", "\r"], ['/', ' ', ''], json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $lines[] = '| '.$check['label'].' | '.$check['status'].' | '.$display($check['observed']).' | '.$display($check['required']).' |';
        }
        $lines[] = '';
        $lines[] = 'Điểm trống tiếp tục chờ người đọc. Kết quả qua gate không thay accuracy hoặc chất lượng văn phong. Ready for owner decision không tự Apply/Publish hoặc phê duyệt rollout. Không có baseline mới để kết luận công sửa đã giảm.';

        return implode("\n", $lines)."\n";
    }

    /** Input: criteria contract. Output: exception on invalid limits or unapproved coverage exceptions. */
    private function validateCriteria(array $criteria): void
    {
        if (($criteria['version'] ?? '') !== 'article.quality.criteria.v1'
            || ! in_array($criteria['status'] ?? '', ['proposed', 'approved'], true)
            || ! is_string($criteria['approved_by'] ?? null)) {
            throw new InvalidArgumentException('Tiêu chí nghiệm thu sai phiên bản hoặc trạng thái.');
        }
        foreach (['minimum_cases', 'minimum_unique_sources', 'maximum_critical_errors', 'maximum_major_errors'] as $field) {
            if (! is_int($criteria[$field] ?? null) || $criteria[$field] < (str_starts_with($field, 'minimum') ? 1 : 0)) {
                throw new InvalidArgumentException('Tiêu chí '.$field.' phải là số nguyên hợp lệ.');
            }
        }
        foreach (['minimum_ready_rate' => [0, 1], 'minimum_median_score' => [1, 5], 'maximum_median_editing_minutes' => [0, 100000]] as $field => [$min, $max]) {
            $value = $criteria[$field] ?? null;
            if ($field === 'maximum_median_editing_minutes' && $value === null) {
                continue;
            }
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value) || $value < $min || $value > $max) {
                throw new InvalidArgumentException('Tiêu chí '.$field.' ngoài phạm vi.');
            }
        }
        foreach (['required_source_languages', 'accepted_coverage_gaps'] as $field) {
            if (! is_array($criteria[$field] ?? null) || array_filter($criteria[$field], static fn ($value): bool => ! is_string($value) || trim($value) === '') !== []) {
                throw new InvalidArgumentException('Tiêu chí '.$field.' phải là danh sách chuỗi.');
            }
        }
        if ($criteria['accepted_coverage_gaps'] !== [] && ($criteria['status'] !== 'approved' || trim($criteria['approved_by']) === '')) {
            throw new InvalidArgumentException('Chỉ chủ dự án đã xác nhận tiêu chí mới được chấp nhận giới hạn coverage.');
        }
    }
}
