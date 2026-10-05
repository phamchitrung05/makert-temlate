<?php

namespace Tests\Unit;

use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Evaluation\ArticleQualityHumanReview;
use App\Services\Ai\Content\Evaluation\ArticleQualityReviewBundle;
use App\Services\Ai\Content\Evaluation\ArticleQualityStudyReport;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm integrity study, rubric người, nguồn và HTML chấm mù.
 * CÁC HÀM: rows(), data(); test_* cho missing/zero/errors/CSV/hash/blinding.
 * INPUT/OUTPUT: fixture offline và corpus thật -> assertions, không AI/DB.
 * Scores trong test là dữ liệu tổng hợp, không ghi vào artifacts nghiệm thu.
 * =====================================================================
 */
final class ArticleQualityStudyReportTest extends TestCase
{
    private const BUNDLE = 'fixture-study-hash';

    private const KEY = ['Q01' => ['X' => 'C', 'Y' => 'B']];

    /**
     * =====================================================================
     * Input: danh tính/mode fixture. Output: rows trống hoặc điểm tổng hợp test.
     * =====================================================================
     */
    private function rows(string $reader = '', bool $complete = false): array
    {
        $rows = [];
        foreach (['X', 'Y'] as $label) {
            $row = array_replace(array_fill_keys((new ArticleQualityHumanReview)->fields(), ''), ['case_id' => 'Q01', 'label' => $label, 'bundle_sha256' => self::BUNDLE, 'reviewer' => $reader]);
            if ($complete) {
                $row = array_replace($row, array_fill_keys(ArticleQualityHumanReview::SCORES, '4'), array_fill_keys(ArticleQualityHumanReview::COUNTS, '0'), ['review_state' => 'completed', 'source_facts_confirmed' => 'yes', 'accuracy_gate' => 'pass', 'editing_minutes' => '0', 'paired_preference' => 'X', 'important_facts_expected' => '2', 'important_facts_preserved' => '2', 'important_fact_evidence' => 'S001 → giữ phiên bản và điều kiện nguồn.']);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * =====================================================================
     * Input: không có. Output: source/outputs fixture paired, không model thật.
     * =====================================================================
     */
    private function data(): array
    {
        $source = (new ArticleSourceExtractor)->snapshot('<p>Chỉ sử dụng phiên bản mới sau khi kiểm tra các điều kiện của môi trường.</p>', ['title' => 'Điều kiện']);
        $manifest = ['cases' => [['case_id' => 'Q01', 'group' => 'Fixture', 'source_language' => 'vi', 'source_sha256' => 'frozen-hash', 'writing_brief' => ['audience' => 'Người mới']]]];
        $common = ['status' => 'ready', 'error_code' => null, 'candidate_for_review' => ['title' => 'Fixture', 'content_html' => '<p>Đọc các yêu cầu của môi trường trước khi chuyển phiên bản.</p>'], 'source_sha256' => ArticleInputHasher::hash(array_replace($source, ['inline_image_refs' => [], 'snapshot_run_id' => ''])), 'comparison_input_sha256' => 'same-input', 'writing_profile_sha256' => 'same-profile', 'brief_sha256' => 'same-brief', 'provider' => 'fixture-provider', 'model' => 'fixture-model', 'calls' => [['task' => 'fixture']], 'cost' => null];
        $artifacts = ['Q01' => ['B' => $common + ['arm' => 'B', 'total_tokens' => 20, 'latency_ms' => 1000], 'C' => $common + ['arm' => 'C', 'total_tokens' => 100, 'latency_ms' => 5000]]];

        return [$manifest, ['Q01' => $source], $artifacts];
    }

    public function test_blank_reviews_stay_pending_and_never_become_zero_scores(): void
    {
        [$manifest, $sources, $artifacts] = $this->data();
        $report = (new ArticleQualityStudyReport)->build($manifest, $sources, $artifacts, self::KEY, [$this->rows(), $this->rows()], self::BUNDLE);
        $this->assertSame('pending_human', $report['human_review']['status']);
        $this->assertSame(0, $report['human_review']['arms']['C']['criteria']['naturalness_1_5']['n']);
        $this->assertNull($report['human_review']['arms']['C']['criteria']['naturalness_1_5']['median']);
        $this->assertNull($report['human_review']['arms']['C']['editing']['minutes']['median']);
        $this->assertFalse($report['technical_gate_is_human_accuracy']);
        $this->assertSame(80, $report['paired_cases'][0]['C_minus_B_tokens']);
        $this->assertStringContainsString('Chưa kết luận', (new ArticleQualityStudyReport)->markdown($report));
    }

    /** Input: phiên mới chỉ C. Output: không dựng B hoặc preference/paired metrics giả. */
    public function test_c_only_study_has_one_candidate_and_no_paired_comparison(): void
    {
        [$manifest, $sources, $artifacts] = $this->data();
        unset($artifacts['Q01']['B']);
        $key = ['Q01' => ['X' => 'C']];
        $rows = array_slice($this->rows(), 0, 1);
        $report = (new ArticleQualityStudyReport)->build($manifest, $sources, $artifacts, $key, [$rows, $rows], self::BUNDLE);
        $this->assertSame(['C'], array_keys($report['arms']));
        $this->assertSame('three_step_C_only', $report['comparison']);
        $this->assertSame(0, $report['matched_pairs']);
        $this->assertNull($report['paired_cases'][0]['C_over_B_tokens']);
        $this->assertArrayNotHasKey('B', $report['human_review']['arms']);
        $html = (new ArticleQualityReviewBundle)->render($manifest, $sources, $artifacts, $key, 'reviewer-1', self::BUNDLE);
        $this->assertStringContainsString('Bài X', $html);
        $this->assertStringNotContainsString('Bài Y', $html);
        $this->assertStringNotContainsString('data-preference=', $html);
        $this->assertStringContainsString('Phiên chỉ có C', (new ArticleQualityStudyReport)->markdown($report));
    }

    /** Input: hai người đã chấm ứng viên C. Output: hoàn thành không cần chọn bài ưu tiên. */
    public function test_c_only_review_accepts_blank_preference_and_keeps_independent_identities(): void
    {
        $readers = [];
        foreach (['Reader A', 'Reader B'] as $reader) {
            $row = $this->rows($reader, true)[0];
            $row['paired_preference'] = '';
            $readers[] = [$row];
        }
        $report = (new ArticleQualityHumanReview)->summarize($readers, ['Q01' => ['X' => 'C']], ['Q01' => ['C' => true]], self::BUNDLE);
        $this->assertSame('independent_reviews_complete', $report['status']);
        $this->assertSame(2, $report['arms']['C']['completed_ratings']);
        $this->assertSame([], $report['paired_preferences']['Q01']);
    }

    public function test_two_completed_reviews_map_blind_labels_and_accept_explicit_zero_minutes(): void
    {
        $a = $this->rows('Reader A', true);
        $b = $this->rows('Reader B', true);
        $a[0]['arm'] = 'B'; // Extra CSV column must not override the frozen X=C mapping.
        $report = (new ArticleQualityHumanReview)->summarize([$a, $b], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
        $this->assertSame('independent_reviews_complete', $report['status']);
        $this->assertSame(2, $report['arms']['C']['completed_ratings']);
        $this->assertSame(0.0, $report['arms']['C']['editing']['minutes']['median']);
        $this->assertSame(['C', 'C'], $report['paired_preferences']['Q01']);
        $this->assertSame('pending_owner_decision', $report['rollout']);
    }

    public function test_completed_review_cannot_hide_a_critical_error_behind_good_style(): void
    {
        $rows = $this->rows('Reader A', true);
        $rows[0]['critical_errors'] = '1';
        $rows[0]['facts_errors_and_severity'] = 'S001 → output bỏ điều kiện; critical; khôi phục điều kiện.';
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityHumanReview)->summarize([$rows, $this->rows('Reader B', true)], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
    }

    public function test_fact_disagreement_is_kept_for_evidence_review_separate_from_style(): void
    {
        $rows = $this->rows('Reader A', true);
        $rows[0]['critical_errors'] = '1';
        $rows[0]['accuracy_gate'] = 'fail';
        $rows[0]['facts_errors_and_severity'] = 'S001 → output bỏ điều kiện; critical.';
        $report = (new ArticleQualityHumanReview)->summarize([$rows, $this->rows('Reader B', true)], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
        $this->assertSame(1, $report['arms']['C']['accuracy']['fail']);
        $this->assertSame(4.0, $report['arms']['C']['criteria']['naturalness_1_5']['median']);
        $this->assertSame('C', $report['fact_disagreements'][0]['arm']);
        $this->assertSame('pending_human_evidence_review', $report['fact_disagreements'][0]['resolution']);
    }

    public function test_two_sheets_from_the_same_person_are_not_independent_reviews(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityHumanReview)->summarize([$this->rows('Reader A', true), $this->rows('reader a', true)], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
    }

    public function test_stale_bundle_hash_cannot_score_a_new_experiment(): void
    {
        $rows = $this->rows();
        $rows[0]['bundle_sha256'] = 'another-study';
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityHumanReview)->summarize([$rows, $this->rows()], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
    }

    public function test_duplicate_or_unknown_label_cannot_be_silently_ignored(): void
    {
        $rows = $this->rows();
        $rows[1]['label'] = 'X';
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityHumanReview)->summarize([$rows, $this->rows()], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
    }

    public function test_missing_editing_minutes_is_not_an_explicit_zero(): void
    {
        $rows = $this->rows('Reader A', true);
        $rows[0]['editing_minutes'] = '';
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityHumanReview)->summarize([$rows, $this->rows('Reader B', true)], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
    }

    public function test_missing_coverage_evidence_or_zero_style_score_is_rejected(): void
    {
        foreach (['important_fact_evidence' => '', 'naturalness_1_5' => '0'] as $field => $value) {
            $rows = $this->rows('Reader A', true);
            $rows[0][$field] = $value;
            try {
                (new ArticleQualityHumanReview)->summarize([$rows, $this->rows('Reader B', true)], self::KEY, ['Q01' => ['B' => true, 'C' => true]], self::BUNDLE);
                $this->fail('Invalid review was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_csv_preserves_bom_quotes_and_multiline_evidence(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'human-review-test-');
        $row = $this->rows('Reader A', true)[0];
        $row['important_fact_evidence'] = "S001 → \"giữ điều kiện\"\nS002 → C:\\notes\\literal";
        try {
            $stream = fopen($file, 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_keys($row), escape: '');
            fputcsv($stream, array_values($row), escape: '');
            fclose($stream);
            $parsed = (new ArticleQualityHumanReview)->readCsv($file);
            $this->assertSame($row, $parsed[0]);
        } finally {
            unlink($file);
        }
    }

    public function test_report_rejects_unpaired_model_settings_or_source(): void
    {
        foreach (['model', 'comparison_input_sha256', 'source_sha256'] as $field) {
            [$manifest, $sources, $artifacts] = $this->data();
            $artifacts['Q01']['C'][$field] = 'different';
            try {
                (new ArticleQualityStudyReport)->build($manifest, $sources, $artifacts, self::KEY, [$this->rows(), $this->rows()], self::BUNDLE);
                $this->fail('Unpaired experiment was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_failed_runs_and_unknown_usage_stay_in_the_denominator(): void
    {
        [$manifest, $sources, $artifacts] = $this->data();
        $artifacts['Q01']['C']['status'] = 'failed';
        $artifacts['Q01']['C']['error_code'] = 'AI_QUALITY_GROUNDING';
        $artifacts['Q01']['C']['total_tokens'] = null;
        $artifacts['Q01']['C']['calls'] = [
            ['task' => 'article.analysis-plan', 'diagnostics' => ['usage' => ['total_tokens' => 7]]],
            ['task' => 'article.writer', 'diagnostics' => ['stage' => 'transport']],
        ];
        $report = (new ArticleQualityStudyReport)->build($manifest, $sources, $artifacts, self::KEY, [$this->rows(), $this->rows()], self::BUNDLE);
        $this->assertSame(1, $report['arms']['C']['runs']);
        $this->assertSame(1, $report['arms']['C']['failed']);
        $this->assertNull($report['arms']['C']['total_tokens']);
        $this->assertSame(1, $report['arms']['C']['unknown_usage_runs']);
        $this->assertSame(7, $report['arms']['C']['known_call_tokens_sum']);
        $this->assertSame(1, $report['arms']['C']['known_usage_calls']);
        $this->assertSame(1, $report['arms']['C']['unknown_usage_calls']);
        $this->assertNull($report['paired_cases'][0]['C_over_B_tokens']);
        $this->assertSame(2, $report['human_review']['progress'][0]['available']);
    }

    public function test_review_html_sanitizes_outputs_and_hides_provider_and_branch_metadata(): void
    {
        [$manifest, $sources, $artifacts] = $this->data();
        $artifacts['Q01']['C']['candidate_for_review']['content_html'] = '<script>window.EVIL=true</script><p onclick="EVIL()">Đọc điều kiện</p><img src="https://example.test/pixel" onerror="EVIL()">';
        $artifacts['Q01']['C']['diagnostics'] = ['secret' => 'DO_NOT_SHOW'];
        $html = (new ArticleQualityReviewBundle)->render($manifest, $sources, $artifacts, self::KEY, 'reviewer-1', self::BUNDLE, 'Giữ điều kiện, không quảng cáo.');
        foreach (['fixture-provider', 'fixture-model', 'DO_NOT_SHOW', 'window.EVIL', 'onclick=', 'onerror='] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
        $this->assertStringContainsString('Giữ điều kiện, không quảng cáo.', $html);
        $this->assertStringContainsString('Bài X', $html);
        $this->assertStringContainsString('connect-src', $html);
        $this->assertStringContainsString('placeholder="Chưa ghi"', $html);
        $this->assertStringNotContainsString('value="4" selected', $html);
    }

    public function test_frozen_corpus_v2_has_independent_vietnamese_news_tourism_tables_and_image_metadata(): void
    {
        $root = base_path('docs/qa/task2-quality/corpus-v2');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(20, $manifest['cases']);
        $this->assertSame(20, $manifest['unique_sources']);
        $this->assertSame(6, count(array_filter($manifest['cases'], fn (array $case): bool => $case['source_language'] === 'vi')));
        $images = $tables = 0;
        foreach ($manifest['cases'] as $case) {
            $bytes = str_replace("\r\n", "\n", file_get_contents($root.'/'.$case['source_snapshot_path']));
            $this->assertSame($case['source_sha256'], hash('sha256', $bytes));
            $source = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            $images += count($source['source_images']);
            $tables += count(array_filter($source['blocks'], fn (array $block): bool => $block['type'] === 'table'));
            $this->assertSame('pending', $case['human_fact_review']);
            $this->assertSame([], $case['important_facts']);
        }
        $this->assertGreaterThan(0, $images);
        $this->assertGreaterThan(0, $tables);
    }
}
