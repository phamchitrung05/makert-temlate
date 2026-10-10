<?php

namespace Tests\Feature;

use App\Models\AiArticleEvaluation;
use App\Models\AiImport;
use App\Models\User;
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
        $user->givePermissionTo(['posts.manage', 'media.attach']);
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
}
