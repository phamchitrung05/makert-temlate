<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Evaluation\ArticleQualityEvaluation;
use App\Services\Ai\Content\Evaluation\EvaluationProviderRecorder;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Contracts\AiResponseMetadataProvider;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm harness B/C không sửa DB/default và không dựng kết quả giả.
 * CÁC HÀM/METHOD TRONG FILE: source(), input(),
 * test_budget_counts_three_calls_and_rejects_fake_baseline(),
 * test_insufficient_budget_stops_before_call(),
 * test_three_step_records_usage_and_preserves_the_input_without_database_work(),
 * test_three_step_uses_same_source_and_exports_real_canonical_artifacts(),
 * test_failed_stage_retains_artifacts_and_missing_usage_is_unknown(),
 * test_recorder_enforces_call_limit_even_when_called_directly();
 * EvaluationFixture: __construct(), configured(), providerName(), modelName(),
 * responseMetadata(), execute(), generate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTTP-free fixture -> assertions call/output/budget.
 * SIDE EFFECT: không network, không ghi database hoặc Apply Post.
 * =====================================================================
 */
final class ArticleQualityEvaluationTest extends TestCase
{
    /**
     * =====================================================================
     * Input: không có.
     * Output: source nguyên bản có anchor để validate evidence.
     * =====================================================================
     */
    private function source(): array
    {
        return (new ArticleSourceExtractor)->snapshot('<p>Người viết chỉnh nội dung trong bản nháp trước khi công bố bài.</p>', ['title' => 'Chuẩn bị bài']);
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: brief/profile/config immutable, không có key.
     * =====================================================================
     */
    private function input(): array
    {
        return ['language' => 'vi', 'writing_brief' => ['audience' => 'Người mới'], 'writing_profile_snapshot' => ['id' => 7, 'version' => 2, 'style_instructions' => 'Viết tự nhiên.'], 'pipeline_snapshot' => config('ai-content')];
    }

    /**
     * =====================================================================
     * Input: 5 case C.
     * Output: 15 call; B không được chạy lại.
     * =====================================================================
     */
    public function test_budget_counts_three_calls_and_rejects_fake_baseline(): void
    {
        $runner = new ArticleQualityEvaluation;
        $this->assertSame(15, $runner->plannedCalls(5, ['C']));
        $this->expectException(InvalidArgumentException::class);
        $runner->plannedCalls(5, ['A', 'B', 'C']);
    }

    /**
     * =====================================================================
     * Input: budget thiếu cho C.
     * Output: từ chối trước call, không gọi provider.
     * =====================================================================
     */
    public function test_insufficient_budget_stops_before_call(): void
    {
        $provider = new EvaluationFixture;
        try {
            (new ArticleQualityEvaluation)->run($this->source(), $this->input(), $provider, 'C', 2);
            $this->fail('Insufficient budget must fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $provider->requests);
        }
    }

    /**
     * =====================================================================
     * Input: C và brief/profile.
     * Output: ba call, usage fixture, không DB write/read.
     * =====================================================================
     */
    public function test_three_step_records_usage_and_preserves_the_input_without_database_work(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $provider = new EvaluationFixture;
        $input = $this->input();
        $result = (new ArticleQualityEvaluation)->run($this->source(), $input, $provider, 'C', 3);
        $this->assertSame('ready', $result['status']);
        $this->assertCount(3, $result['calls']);
        $this->assertSame(36, $result['total_tokens']);
        $this->assertNull($result['cost']);
        $this->assertSame('Viết tự nhiên.', $provider->requests[0]->input['profile']['style_instructions']);
        $this->assertSame('Người mới', $provider->requests[0]->input['brief']['audience']);
        $this->assertSame($input, $this->input());
        $this->assertSame([], $queries);
    }

    /**
     * =====================================================================
     * Input: C và source/profile immutable.
     * Output: đúng ba task, hash source/profile/brief giữ nguyên.
     * =====================================================================
     */
    public function test_three_step_uses_same_source_and_exports_real_canonical_artifacts(): void
    {
        $runner = new ArticleQualityEvaluation;
        $source = $this->source();
        $input = $this->input();
        $c = $runner->run($source, $input, new EvaluationFixture, 'C', 3);
        $this->assertSame('ready', $c['status']);
        $this->assertSame(['article.analysis-plan', 'article.writer', 'article.editor'], array_column($c['calls'], 'task'));
        $this->assertSame(ArticleInputHasher::hash(array_replace($source, ['inline_image_refs' => [], 'snapshot_run_id' => ''])), $c['source_sha256']);
        $this->assertSame(ArticleInputHasher::hash($input['writing_profile_snapshot']), $c['writing_profile_sha256']);
        $this->assertSame(ArticleInputHasher::hash($input['writing_brief']), $c['brief_sha256']);
        $this->assertSame('not_evaluated', $c['image_evaluation']['pixel_understanding']);
        $this->assertSame(36, $c['total_tokens']);
        $this->assertNotEmpty($c['quality_checks']);
        $this->assertArrayNotHasKey('score', $c);
    }

    /** Input: yêu cầu B trực tiếp. Output: từ chối trước provider, không call nào. */
    public function test_removed_branch_is_rejected_before_any_provider_call(): void
    {
        $provider = Mockery::mock(AiProviderContract::class);
        $provider->shouldNotReceive('configured');
        $provider->shouldNotReceive('generate');
        $provider->shouldNotReceive('execute');
        $this->expectException(InvalidArgumentException::class);
        (new ArticleQualityEvaluation)->run($this->source(), $this->input(), $provider, 'B', 1);
    }

    /**
     * =====================================================================
     * Input: lỗi Writer sau Analyzer.
     * Output: giữ artifact, không retry/0 token/final giả.
     * =====================================================================
     */
    public function test_failed_stage_retains_artifacts_and_missing_usage_is_unknown(): void
    {
        $provider = new EvaluationFixture('article.writer');
        $result = (new ArticleQualityEvaluation)->run($this->source(), $this->input(), $provider, 'C', 3);
        $this->assertSame('AI_PROVIDER_TIMEOUT', $result['error_code']);
        $this->assertSame('failed', $result['status']);
        $this->assertCount(2, $result['calls']);
        $this->assertNotNull($result['calls'][0]['output']);
        $this->assertNull($result['final']);
        $this->assertNull($result['total_tokens']);
    }

    /**
     * =====================================================================
     * Input: direct recorder vượt call.
     * Output: call bị chặn trước adapter.
     * =====================================================================
     */
    public function test_recorder_enforces_call_limit_even_when_called_directly(): void
    {
        $recorder = new EvaluationProviderRecorder(new EvaluationFixture, 1);
        $recorder->generate('Nguồn', '<p>Source</p>');
        $this->expectException(AiImportException::class);
        $recorder->generate('Nguồn', '<p>Source</p>');
    }
}

/**
 * =====================================================================
 * CHỨC NĂNG CLASS: Provider offline đúng schema để kiểm collector, không chấm chất lượng.
 * CÁC METHOD: __construct(), configured(), providerName(), modelName(),
 * responseMetadata(), generate(), execute(). INPUT/OUTPUT: fixture -> DTO cố định.
 * =====================================================================
 */
final class EvaluationFixture implements AiProviderContract, AiResponseMetadataProvider
{
    public array $requests = [];

    public string $instructions = '';

    private array $metadata = [];

    private array $fields = ['title' => 'Biên tập trước khi công bố', 'content_html' => '<p>Bản nháp là nơi người viết sửa câu chữ để chuẩn bị bài trước lúc công bố.</p>'];

    /**
     * =====================================================================
     * Input: task cần lỗi.
     * Output: fixture không network.
     * =====================================================================
     */
    public function __construct(private readonly ?string $failTask = null) {}

    /**
     * =====================================================================
     * Input: không có.
     * Output: configured fixture.
     * =====================================================================
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: tên fixture.
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'evaluation-fixture';
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: tên model fixture.
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'fixture';
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: usage của call gần nhất hoặc rỗng.
     * =====================================================================
     */
    public function responseMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * =====================================================================
     * Input: source/instructions.
     * Output: fields fixture và metadata; không gọi model.
     * =====================================================================
     */
    public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
    {
        $this->instructions = $instructions;
        $this->metadata = ['usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12]];

        return $this->fields;
    }

    /**
     * =====================================================================
     * Input: task/schema.
     * Output: artifact đúng schema hoặc timeout fixture.
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        $this->requests[] = $request;
        $this->metadata = [];
        if ($request->task === $this->failTask) {
            throw new AiImportException('Fixture timeout', 'AI_PROVIDER_TIMEOUT');
        }
        $output = match ($request->task) {
            'article.analysis-plan' => ['knowledge' => ['facts' => [['id' => 'F1', 'claim' => 'Chỉnh bản nháp trước khi công bố.', 'source_block_ids' => ['S001'], 'evidence' => 'Người viết chỉnh nội dung trong bản nháp trước khi công bố bài.', 'important' => true]], 'terms' => [], 'uncertainties' => []], 'writing_plan' => ['article_type' => 'Giải thích', 'audience' => 'Người mới', 'angle' => 'Biên tập', 'outline' => ['Bản nháp'], 'coverage_fact_ids' => ['F1']]],
            'article.writer' => ['draft' => $this->fields, 'used_fact_ids' => ['F1'], 'used_asset_ids' => []],
            default => ['final' => $this->fields, 'used_fact_ids' => ['F1'], 'issues' => []],
        };
        $this->metadata = ['usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12]];

        return new AiTaskResponse($output, $this->metadata);
    }
}
