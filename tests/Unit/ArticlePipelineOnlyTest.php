<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use Mockery;
use Tests\ArticlePipelineFixture;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chặn đường thực thi B qua config/snapshot cũ và fallback lỗi C.
 * CÁC HÀM: provider(), legacyRun(), test_*.
 * INPUT/OUTPUT: transient run/provider fake -> thứ tự task và lỗi; 0 AI/DB thật.
 * =====================================================================
 */
final class ArticlePipelineOnlyTest extends TestCase
{
    /** Input: task sẽ lỗi hoặc null. Output: provider fake không cho gọi generate(). */
    private function provider(array &$tasks, ?string $failure = null): AiProviderContract
    {
        $provider = Mockery::mock(AiProviderContract::class);
        $provider->shouldReceive('configured')->andReturn(true);
        $provider->shouldReceive('providerName')->andReturn('pipeline-fixture');
        $provider->shouldReceive('modelName')->andReturn('offline');
        $provider->shouldNotReceive('generate');
        $provider->shouldReceive('execute')->andReturnUsing(function (AiTaskRequest $request) use (&$tasks, $failure): AiTaskResponse {
            $tasks[] = $request->task;
            if ($request->task === $failure) {
                throw new AiImportException('Fixture Writer timeout', 'AI_PROVIDER_TIMEOUT');
            }

            return new AiTaskResponse(ArticlePipelineFixture::output($request->task, $request->input, ['title' => 'Biên tập bản nháp', 'content_html' => '<p>Người viết sửa bản nháp trước khi công bố bài.</p>'], $request->schema));
        });

        return $provider;
    }

    /** Input: config/snapshot B còn sót. Output: transient run dùng để kiểm routing. */
    private function legacyRun(): AiImport
    {
        config()->set('ai-content.pipeline', 'single_step');

        return new AiImport(['source_text' => 'Bản nháp được dùng để chỉnh sửa trước khi công bố.', 'input_json' => ['source_type' => 'text', 'fields' => ['title', 'content'], 'pipeline_snapshot' => array_replace(config('ai-content'), ['pipeline' => 'single_step'])]]);
    }

    /** Input: snapshot cũ. Output: đủ ba task C, tuyệt đối không gọi B. */
    public function test_old_snapshot_and_configuration_cannot_restore_single_call_articles(): void
    {
        $tasks = [];
        $result = (new ArticleImportService($this->provider($tasks)))->run($this->legacyRun());
        $this->assertSame(['article.analysis-plan', 'article.writer', 'article.editor'], $tasks);
        $this->assertSame('Biên tập bản nháp', $result['draft']['title']);
    }

    /** Input: lỗi Writer. Output: dừng sau hai task, không fallback sang generate(). */
    public function test_failed_three_step_generation_never_falls_back_to_removed_branch(): void
    {
        $tasks = [];
        try {
            (new ArticleImportService($this->provider($tasks, 'article.writer')))->run($this->legacyRun());
            $this->fail('Writer timeout must stop the pipeline.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_PROVIDER_TIMEOUT', $exception->errorCode);
            $this->assertSame(['article.analysis-plan', 'article.writer'], $tasks);
        }
    }
}
