<?php

namespace Tests\Feature;

use App\Models\AiArticleEvaluation;
use App\Models\AiImport;
use App\Models\User;
use App\Services\Marketing\SocialPostLifecycleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm hash/lifecycle và cổng duyệt evaluator G2/G3.
 * =====================================================================
 * Không gọi provider thật; kiểm worker dispatch, API quality và gate approve.
 * =====================================================================
 */
class AiArticleQualityEvaluationTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: quyền posts.manage. Output: owner và Sanctum token admin. */
    private function credentials(): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo(['posts.manage', 'media.attach', 'social_posts.create']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Auth::forgetGuards();

        return [$user, $user->createToken('quality-test', ['admin'])->plainTextToken];
    }

    /** Input: actor. Output: ready Post AI có source block và generated_fields. */
    private function candidate(User $actor): AiImport
    {
        return AiImport::query()->create([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'create',
            'provider' => 'deterministic', 'source_url' => '', 'source_text' => 'Nguồn kiểm thử.',
            'input_json' => ['target_type' => 'post', 'model' => 'test-model', 'ai_connection' => ['provider' => 'deterministic', 'model' => 'deterministic']],
            'source_meta_json' => ['article_source' => ['title' => 'Nguồn', 'content_html' => '<p>Nguồn kiểm thử.</p>', 'blocks' => [['id' => 'S001', 'text' => 'Nguồn kiểm thử.']]]],
            'result_json' => [
                'provider' => 'deterministic',
                'generation_meta' => ['mode' => 'ai', 'generated_fields' => ['title', 'content_html']],
                'draft' => ['title' => 'Bài kiểm thử', 'content_html' => '<p>Nguồn kiểm thử.</p>', 'excerpt' => 'Tóm tắt'],
            ],
            'expires_at' => now()->addDays(2), 'completed_at' => now(),
        ]);
    }

    /** Input: candidate. Output: draft/review versions cho approve request. */
    private function versions(AiImport $run): array
    {
        return [
            'expected_version' => hash('sha256', json_encode(data_get($run->result_json, 'draft', []))),
            'expected_review_version' => \App\Services\Ai\Content\AiContentReviewService::version($run),
        ];
    }

    /** G2 tạo evaluation đúng hash và không dispatch trùng khi polling lại. */
    public function test_schedule_is_idempotent_for_generation_and_candidate_hash(): void
    {
        [$actor] = $this->credentials();
        $run = $this->candidate($actor);
        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);

        $evaluation = $service->schedule($run);
        $this->assertNotNull($evaluation);
        $this->assertSame('queued', $evaluation->status);
        Queue::assertPushed(\App\Jobs\EvaluateAiArticleJob::class, 1);
        $this->assertSame($evaluation->id, $service->schedule($run)?->id);
        $this->assertDatabaseCount('ai_article_evaluations', 1);
    }

    /** G3 chặn approve/apply trước score, sau đó cho qua khi đủ score/source/facts. */
    public function test_quality_gate_blocks_approval_until_ready_eligible_evaluation(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        $evaluation = $service->schedule($run);

        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/quality')
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.eligibility.eligible', false);
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [...$this->versions($run), 'fields' => ['title']])
            ->assertConflict();

        $evaluation->forceFill([
            'status' => 'ready', 'score_total' => 4.4,
            'scores_json' => ['accuracy' => 4.5, 'source_grounding' => 4.5, 'clarity' => 4.3, 'structure' => 4.3, 'style' => 4.4],
            'source_references_json' => ['S001'],
            'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []],
            'completed_at' => now(),
        ])->save();

        $evaluation->forceFill(['score_total' => 4.0, 'eligibility_json' => ['score' => false, 'source' => true, 'facts' => true, 'eligible' => false, 'reasons' => ['Điểm tổng phải lớn hơn 4/5.']]])->save();
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [...$this->versions($run), 'fields' => ['title']])
            ->assertConflict();
        $evaluation->forceFill(['score_total' => 4.4, 'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []]])->save();

        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')
            ->assertOk()->assertJsonPath('data.can_approve', true)->assertJsonPath('data.quality_evaluation.score_total', 4.4);
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/quality/rescore')
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $evaluation->refresh()->forceFill(['status' => 'ready', 'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []], 'score_total' => 4.4])->save();
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [...$this->versions($run), 'fields' => ['title']])
            ->assertOk();
        $this->assertDatabaseHas('ai_article_archives', ['run_id' => $run->id]);
        $archive = \App\Models\AiArticleArchive::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(4.4, (float) data_get($archive->lifecycle_json, 'quality_evaluation.score_total'));
    }

    /** Kết quả ready quá hạn không được dùng lại để duyệt và phải báo expired. */
    public function test_expired_evaluation_is_not_eligible_and_is_reported_as_expired(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $evaluation = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class)->schedule($run);
        $evaluation->forceFill([
            'status' => 'ready',
            'score_total' => 4.8,
            'scores_json' => ['accuracy' => 4.8],
            'source_references_json' => ['S001'],
            'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []],
            'expires_at' => now()->subMinute(),
            'completed_at' => now()->subMinutes(2),
        ])->save();

        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/quality')
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.score_total', null)
            ->assertJsonPath('data.expired', true)
            ->assertJsonPath('data.eligibility.eligible', false)
            ->assertJsonPath('data.eligibility.reasons.0', 'Kết quả chấm đã hết hạn. Hãy chấm lại candidate.');
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            ...$this->versions($run), 'fields' => ['title'],
        ])->assertConflict();
    }

    /** Approve chỉ tạo draft mới và từ chối target_id legacy. */
    public function test_approve_rejects_target_id(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);

        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            ...$this->versions($run), 'fields' => ['title'], 'target_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['target_id']);
    }

    /** Rescore legacy candidate thiếu metadata AI phải trả lỗi hướng dẫn rõ ràng. */
    public function test_rescore_rejects_missing_ai_content_field(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $run->forceFill(['result_json' => [
            'generation_meta' => ['mode' => 'ai', 'generated_fields' => ['title']],
            'draft' => ['title' => 'Bài kiểm thử', 'content_html' => '<p>Nội dung.</p>'],
        ]])->save();

        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/quality/rescore')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Candidate không có generated field content_html do AI. Hãy tạo candidate mới.');
    }

    /** Rescore candidate không có source blocks phải bị từ chối trước khi xếp evaluator. */
    public function test_rescore_rejects_missing_source_blocks(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $run->forceFill([
            'source_meta_json' => ['article_source' => ['content_html' => '<p>Nguồn.</p>', 'blocks' => []]],
        ])->save();

        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/quality/rescore')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Candidate thiếu article source blocks nên evaluator không thể chấm. Hãy tạo candidate mới.');
    }

    /** Service retry không vượt max_attempts và job không tự cộng retry lần hai. */
    public function test_retry_cap_and_job_attempt_policy(): void
    {
        [$actor] = $this->credentials();
        $run = $this->candidate($actor);
        $evaluation = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class)->schedule($run);
        $evaluation->forceFill(['status' => 'running', 'attempt' => 2])->save();
        config(['ai.quality.max_attempts' => 2]);

        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        $method = new \ReflectionMethod($service, 'requeue');
        $method->setAccessible(true);
        $method->invoke($service, $evaluation->id, new \RuntimeException('temporary'));

        $this->assertSame('failed', $evaluation->fresh()->status);
        $this->assertSame(2, (int) $evaluation->fresh()->attempt);
        $this->assertSame(1, (new \App\Jobs\EvaluateAiArticleJob($evaluation->id))->tries);
    }

    /** Social caption-only được duyệt ở bước AI, không qua article scoring; thiếu 3 ảnh thì submit review bị chặn. */
    public function test_social_caption_only_approve_reports_missing_gallery(): void
    {
        [$actor, $token] = $this->credentials();
        $run = AiImport::query()->create([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'social_caption',
            'provider' => 'deterministic', 'source_url' => '', 'source_text' => '',
            'input_json' => ['target_type' => 'social_post', 'generation_no' => 0],
            'result_json' => ['draft' => ['content_html' => '<p>Caption Facebook.</p>']],
            'expires_at' => now()->addDays(2), 'completed_at' => now(),
        ]);

        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            ...$this->versions($run), 'fields' => ['social_content'],
        ]);
        $response->assertOk()
            ->assertJsonPath('data.gallery_ready', false)
            ->assertJsonPath('data.missing', 'Cần thêm 3 ảnh trước khi submit review.');

        $postId = $response->json('data.social_post_id');
        $this->assertNotNull($postId);
        $this->assertDatabaseCount('ai_article_evaluations', 0);

        try {
            app(SocialPostLifecycleService::class)->transition(\App\Models\SocialPost::query()->findOrFail($postId), 'submit', $actor);
            $this->fail('Submit phải bị chặn khi thiếu gallery.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(
                'Social Post cần thêm 3 ảnh (tối thiểu 3 ảnh) trước khi review.',
                $e->errors()['generation'][0] ?? ''
            );
        }
    }

/** Candidate source-only dùng provider AI nhưng không tạo text mới thì không qua quality gate. */
    public function test_source_only_candidate_without_ai_content_can_be_approved_without_evaluation(): void
    {
        [$actor, $token] = $this->credentials();
        $run = AiImport::query()->create([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'regenerate',
            'provider' => 'openai', 'source_url' => '', 'source_text' => 'Nguồn kế thừa.',
            'input_json' => ['target_type' => 'post', 'fields' => ['thumbnail'], 'ai_connection' => ['provider' => 'openai', 'model' => 'gpt-test']],
            'source_meta_json' => ['article_source' => ['title' => 'Nguồn', 'content_html' => '<p>Nguồn kế thừa.</p>', 'blocks' => [['id' => 'S001', 'text' => 'Nguồn kế thừa.']]]],
            'result_json' => [
                'provider' => 'openai',
                'generation_meta' => ['mode' => 'source_only', 'generated_fields' => [], 'inherited_fields' => ['content_html'], 'content_origin' => 'source'],
                'draft' => ['title' => 'Tiêu đề kế thừa', 'content_html' => '<p>Nguồn kế thừa.</p>'],
            ],
            'expires_at' => now()->addDays(2), 'completed_at' => now(),
        ]);

        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        $this->assertFalse($service->supports($run));
        $this->assertFalse($service->requiresGate($run));

        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')
            ->assertOk()->assertJsonPath('data.can_approve', true);
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            ...$this->versions($run), 'fields' => ['title'],
        ])->assertOk();
    }

    /** Regenerate chỉ đổi field phụ vẫn phải chấm nếu body AI được kế thừa từ parent. */
    public function test_inherited_ai_content_still_requires_quality_gate(): void
    {
        [$actor, $token] = $this->credentials();
        $run = AiImport::query()->create([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'regenerate',
            'provider' => 'openai', 'source_url' => '', 'source_text' => 'Nguồn kế thừa.',
            'input_json' => ['target_type' => 'post', 'fields' => ['title'], 'ai_connection' => ['provider' => 'openai', 'model' => 'gpt-test']],
            'source_meta_json' => ['article_source' => ['title' => 'Nguồn', 'content_html' => '<p>Nguồn kế thừa.</p>', 'blocks' => [['id' => 'S001', 'text' => 'Nguồn kế thừa.']]]],
            'result_json' => [
                'provider' => 'openai',
                'generation_meta' => ['mode' => 'ai', 'generated_fields' => ['title'], 'inherited_fields' => ['content_html'], 'content_origin' => 'ai'],
                'draft' => ['title' => 'Tiêu đề mới', 'content_html' => '<p>Nội dung AI của parent.</p>'],
            ],
            'expires_at' => now()->addDays(2), 'completed_at' => now(),
        ]);

        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        $this->assertTrue($service->supports($run));
        $this->assertTrue($service->requiresGate($run));
        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')
            ->assertOk()->assertJsonPath('data.can_approve', false);
    }

    /** Thiếu content_origin được coi là cần chấm (fail-closed), không bỏ qua gate. */
    public function test_candidate_without_content_origin_fails_closed(): void
    {
        [$actor] = $this->credentials();
        $run = AiImport::query()->create([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'regenerate',
            'provider' => 'openai', 'source_url' => '', 'source_text' => 'Nguồn.',
            'input_json' => ['target_type' => 'post', 'fields' => ['thumbnail']],
            'source_meta_json' => ['article_source' => ['title' => 'Nguồn', 'content_html' => '<p>Nguồn.</p>', 'blocks' => [['id' => 'S001', 'text' => 'Nguồn.']]]],
            'result_json' => [
                'generation_meta' => ['mode' => 'source_only', 'generated_fields' => [], 'inherited_fields' => ['content_html']],
                'draft' => ['title' => 'Thiếu origin', 'content_html' => '<p>Nội dung.</p>'],
            ],
            'expires_at' => now()->addDays(2), 'completed_at' => now(),
        ]);

        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        $this->assertTrue($service->supports($run));
        $this->assertTrue($service->requiresGate($run));
        $this->assertNotNull($service->schedule($run));
    }

    /** content_origin=source/deterministic được miễn chấm. */
    public function test_source_and_deterministic_origin_skip_evaluation(): void
    {
        [$actor] = $this->credentials();
        $service = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        foreach (['source', 'deterministic'] as $origin) {
            $run = AiImport::query()->create([
                'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'regenerate',
                'provider' => 'openai', 'source_url' => '', 'source_text' => 'Nguồn '.$origin,
                'input_json' => ['target_type' => 'post', 'fields' => ['thumbnail']],
                'source_meta_json' => ['article_source' => ['title' => 'Nguồn', 'content_html' => '<p>Nguồn.</p>', 'blocks' => [['id' => 'S001', 'text' => 'Nguồn.']]]],
                'result_json' => [
                    'generation_meta' => ['mode' => 'source_only', 'generated_fields' => [], 'content_origin' => $origin],
                    'draft' => ['title' => 'Bản '.$origin, 'content_html' => '<p>Nội dung '.$origin.'.</p>'],
                ],
                'expires_at' => now()->addDays(2), 'completed_at' => now(),
            ]);
            $this->assertFalse($service->supports($run), $origin);
            $this->assertFalse($service->requiresGate($run), $origin);
            $this->assertNull($service->schedule($run), $origin);
        }
    }

    /** Regenerate từ parent thiếu content_origin bị chặn trước khi gọi pipeline. */
    public function test_regeneration_rejects_parent_without_content_origin(): void
    {
        [$actor] = $this->credentials();
        $parent = AiImport::query()->create([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'create',
            'provider' => 'openai', 'source_url' => '', 'source_text' => 'Nguồn parent.',
            'result_json' => ['draft' => ['title' => 'Parent', 'content_html' => '<p>Body AI cũ.</p>']],
        ]);
        $child = AiImport::query()->create([
            'created_by' => $actor->id, 'parent_id' => $parent->id, 'status' => 'queued', 'operation' => 'regenerate',
            'provider' => 'deterministic', 'source_url' => '', 'source_text' => 'Nguồn child.',
            'input_json' => ['source_type' => 'text', 'target_type' => 'post', 'fields' => ['title']],
        ]);

        try {
            (new \App\Services\Ai\Content\ArticleImportService(
                new \App\Services\Ai\Providers\Adapters\DeterministicAiProvider
            ))->run($child);
            $this->fail('Regenerate từ parent thiếu content_origin phải bị chặn.');
        } catch (\App\Exceptions\AiImportException $exception) {
            $this->assertSame('AI_PARENT_ORIGIN_MISSING', $exception->errorCode);
        }
    }
}
