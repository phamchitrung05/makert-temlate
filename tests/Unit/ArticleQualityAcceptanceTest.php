<?php

namespace Tests\Unit;

use App\Services\Ai\Content\Evaluation\ArticleQualityAcceptance;
use App\Services\Ai\Content\Evaluation\ArticleQualityHumanReview;
use InvalidArgumentException;
use Tests\TestCase;

final class ArticleQualityAcceptanceTest extends TestCase
{
    private function criteria(): array
    {
        return array_replace(json_decode(file_get_contents(base_path('scripts/ai-quality/acceptance-criteria.json')), true), [
            'status' => 'approved', 'approved_by' => 'Fixture owner', 'maximum_median_editing_minutes' => 15,
        ]);
    }

    private function report(): array
    {
        $distribution = ['n' => 40, 'min' => 0, 'median' => 0, 'max' => 0];
        $accuracy = ['pass' => 40, 'fail' => 0, 'undetermined' => 0, 'critical_errors' => $distribution, 'major_errors' => $distribution];
        $scores = array_fill_keys(ArticleQualityHumanReview::SCORES, array_replace($distribution, ['median' => 4]));

        return [
            'version' => 'article.quality.study.v2', 'bundle_sha256' => str_repeat('a', 64),
            'cases' => 20, 'unique_sources' => 20, 'source_languages' => ['en' => 14, 'vi' => 6], 'coverage_gaps' => [],
            'arms' => ['C' => ['runs' => 20, 'ready' => 20, 'cost' => null, 'unknown_usage_runs' => 1]],
            'human_review' => ['status' => 'independent_reviews_complete', 'progress' => [
                'reviewer-1' => ['completed' => 20, 'available' => 20, 'reviewer' => 'Reader A'],
                'reviewer-2' => ['completed' => 20, 'available' => 20, 'reviewer' => 'Reader B'],
            ], 'fact_disagreements' => [], 'arms' => ['C' => ['accuracy' => $accuracy, 'criteria' => $scores, 'editing' => ['minutes' => array_replace($distribution, ['median' => 8])]]]],
        ];
    }

    public function test_complete_evidence_is_ready_for_owner_but_never_approves_rollout(): void
    {
        $result = (new ArticleQualityAcceptance)->evaluate($this->report(), $this->criteria());
        $this->assertSame('ready_for_owner_decision', $result['status']);
        $this->assertSame('pending_owner_decision', $result['rollout']);
        $this->assertNull($result['cost']);
        $this->assertFalse($result['scores_filled_by_agent']);
    }

    public function test_blank_scores_and_unapproved_criteria_remain_pending(): void
    {
        $report = $this->report();
        $report['human_review']['status'] = 'pending_human';
        $report['human_review']['progress']['reviewer-1']['completed'] = 0;
        $report['human_review']['progress']['reviewer-2']['completed'] = 0;
        $criteria = array_replace($this->criteria(), ['status' => 'proposed', 'approved_by' => '', 'maximum_median_editing_minutes' => null]);
        $result = (new ArticleQualityAcceptance)->evaluate($report, $criteria);
        $this->assertSame('pending_evidence', $result['status']);
        $this->assertGreaterThan(0, $result['pending_checks']);
    }

    public function test_high_style_scores_cannot_hide_critical_errors_or_failed_runs(): void
    {
        $report = $this->report();
        $report['arms']['C']['ready'] = 19;
        $report['human_review']['arms']['C']['accuracy']['critical_errors']['max'] = 1;
        $result = (new ArticleQualityAcceptance)->evaluate($report, $this->criteria());
        $this->assertSame('criteria_not_met', $result['status']);
        $this->assertSame(2, $result['failed_checks']);
    }

    public function test_same_reader_twice_does_not_complete_the_evidence(): void
    {
        $report = $this->report();
        $report['human_review']['progress']['reviewer-2']['reviewer'] = ' reader a ';
        $result = (new ArticleQualityAcceptance)->evaluate($report, $this->criteria());
        $this->assertSame('pending_evidence', $result['status']);
    }

    public function test_unresolved_coverage_and_fact_disagreements_block_acceptance(): void
    {
        $report = $this->report();
        $report['coverage_gaps'] = ['No independent benchmark'];
        $report['human_review']['fact_disagreements'] = [['case_id' => 'Q01']];
        $result = (new ArticleQualityAcceptance)->evaluate($report, $this->criteria());
        $this->assertSame(2, $result['failed_checks']);
    }

    public function test_proposal_cannot_silently_accept_coverage_limits(): void
    {
        $criteria = array_replace($this->criteria(), ['status' => 'proposed', 'accepted_coverage_gaps' => ['No benchmark']]);
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityAcceptance)->evaluate($this->report(), $criteria);
    }

    public function test_coverage_exception_requires_a_named_owner(): void
    {
        $criteria = array_replace($this->criteria(), ['approved_by' => ' ', 'accepted_coverage_gaps' => ['No benchmark']]);
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityAcceptance)->evaluate($this->report(), $criteria);
    }
}
