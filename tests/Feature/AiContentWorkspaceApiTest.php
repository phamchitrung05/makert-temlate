<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\User;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Contracts\AiProviderContract;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm workspace nhiều target và optimistic version của candidate.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - token().
 * - createRun().
 * - test_targets_and_creation_are_driven_by_config().
 * - test_permissions_follow_target_and_owner().
 * - test_pipeline_uses_target_prompt_and_sanitizes_result().
 * - test_edit_validates_version_status_and_html_without_applying().
 * - test_regenerate_ignores_empty_overrides_and_lists_child().
 * - test_remove_rejects_running_and_preserves_other_candidates().
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures/requests admin, HTTP và Queue fake, database test cô lập.
 * - OUTPUT: assertions cho contract, snapshot, quyền và lỗi; không gọi AI thật.
 * - SIDE EFFECT: tạo/sửa dữ liệu trong database test; không chỉnh dữ liệu ứng dụng.
 * =====================================================================
 */
class AiContentWorkspaceApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * Input: PHPUnit lifecycle. Output: schema SQLite và permission seed cô lập.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    /**
     * =====================================================================
     * Input: kết thúc test. Output: dọn DB cô lập.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * Input: permission tùy chọn. Output: token admin test; không dùng credential thật.
     * =====================================================================
     */
    private function token(array $permissions = ['posts.manage', 'resources.create']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-workspace-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * Input: token và target. Output: run queued, không thực thi worker.
     * =====================================================================
     */
    private function createRun(string $token, string $target = 'post'): AiImport
    {
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => $target, 'input' => ['type' => 'text', 'text' => 'Nguồn tài nguyên đã xác thực.'],
            'provider' => 'deterministic', 'model' => 'deterministic', 'generate_thumbnail' => false,
        ])->assertAccepted();

        return AiImport::findOrFail($response->json('data.job_id'));
    }

    /**
     * =====================================================================
     * Input: ba target cùng nguồn. Output: identity/hash riêng và labels theo config.
     * =====================================================================
     */
    public function test_targets_and_creation_are_driven_by_config(): void
    {
        $token = $this->token();
        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertOk()
            ->assertJsonPath('data.0.key', 'post')->assertJsonPath('data.1.key', 'resource')->assertJsonPath('data.2.key', 'sound');
        foreach (['post', 'resource', 'sound'] as $target) {
            $run = $this->createRun($token, $target);
            $this->assertSame($target, $run->input_json['target_type']);
            $this->assertSame($target.'.create.from_text', $run->input_json['prompt_key']);
        }
        $this->assertSame(3, AiImport::distinct()->count('source_hash'));
        Queue::assertPushed(ProcessAiImportJob::class, 3);
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')->assertJsonCount(3, 'data');
        config(['ai-agent.targets.lesson' => [
            'enabled' => true, 'label' => 'Lesson', 'permission' => 'posts.manage',
            'content_instructions' => 'Write a lesson.', 'inputs' => ['text'], 'outputs' => ['title', 'content'],
        ]]);
        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertJsonPath('data.3.label', 'Lesson');
        $this->createRun($token, 'lesson');
        config(['ai-agent.targets.lesson.enabled' => false]);
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'lesson', 'text' => 'A lesson',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_type');
    }

    /**
     * =====================================================================
     * Input: actor chỉ có quyền Resource. Output: không thấy/không tạo/sửa Post.
     * =====================================================================
     */
    public function test_permissions_follow_target_and_owner(): void
    {
        $token = $this->token(['resources.create']);
        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertOk()->assertJsonCount(2, 'data');
        $run = $this->createRun($token, 'resource');
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'post', 'text' => 'Not allowed',
        ])->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/ai-agent/capabilities/post')->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/posts')->assertForbidden();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', ['text' => 'Not allowed'])->assertForbidden();
        $other = $this->token();
        $this->withToken($other)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
        $this->withToken($other)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
    }

    /**
     * =====================================================================
     * Input: Resource/Sound run. Output: pipeline dùng đúng prompt target và chỉ tạo candidate văn bản.
     * =====================================================================
     */
    public function test_pipeline_uses_target_prompt_and_sanitizes_result(): void
    {
        $token = $this->token();
        foreach (['resource', 'sound'] as $target) {
            $run = $this->createRun($token, $target);
            $provider = Mockery::mock(AiProviderContract::class);
            $provider->shouldReceive('configured')->andReturnTrue();
            $provider->shouldReceive('generate')->once()->withArgs(fn ($title, $content, $language, $style, $prompt) => $prompt === $target.'.create.from_text')
                ->andReturn(['title' => 'Nội dung '.$target, 'content_html' => '<p onclick="bad()">Nội dung <strong>an toàn</strong></p><script>bad()</script>']);
            $provider->shouldReceive('providerName')->andReturn('fake');
            $provider->shouldReceive('modelName')->andReturn('fake');
            $result = (new ArticleImportService($provider))->run($run);
            $this->assertSame($target.'.create.from_text', $result['prompt_key']);
            $this->assertStringNotContainsString('script', $result['draft']['content_html']);
            $this->assertStringNotContainsString('onclick', $result['draft']['content_html']);
        }
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('resources', 0);
    }

    /**
     * =====================================================================
     * Input: sửa title/content và phiên bản cũ. Output: HTML sạch; stale/applied/busy không sửa được.
     * =====================================================================
     */
    public function test_edit_validates_version_status_and_html_without_applying(): void
    {
        $token = $this->token();
        $run = $this->createRun($token, 'resource');
        $run->update(['status' => 'ready', 'result_json' => ['draft' => ['title' => 'Old', 'content_html' => '<p>Old</p>', 'thumbnail' => ['media_asset_id' => null]]]]);
        $version = $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->json('data.draft_version');
        $payload = ['expected_version' => $version, 'title' => 'Edited', 'content_html' => '<p onclick="bad()">Safe</p><script>bad()</script>'];
        $this->withToken($token)->patchJson('/api/admin/ai-agent/candidates/'.$run->id, $payload)
            ->assertOk()->assertJsonPath('data.draft.title', 'Edited')->assertJsonPath('data.draft.content_html', '<p>Safe</p>');
        $this->assertSame('<p>Safe</p>', data_get($run->fresh()->result_json, 'draft.content'));
        $this->assertDatabaseHas('activity_log', ['description' => 'candidate.edited']);
        $this->withToken($token)->patchJson('/api/admin/ai-agent/candidates/'.$run->id, $payload)->assertConflict();
        $payload['expected_version'] = $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->json('data.draft_version');
        $this->withToken($token)->patchJson('/api/admin/ai-agent/candidates/'.$run->id, array_replace($payload, ['content_html' => '<script>bad()</script>']))->assertUnprocessable();
        $run->update(['applied_target_id' => 99]);
        $this->withToken($token)->patchJson('/api/admin/ai-agent/candidates/'.$run->id, $payload)->assertConflict();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('resources', 0);
    }

    /**
     * =====================================================================
     * Input: tạo lại Sound với optional trống. Output: child/list tồn tại, parent giữ nguyên, không đổi model.
     * =====================================================================
     */
    public function test_regenerate_ignores_empty_overrides_and_lists_child(): void
    {
        $token = $this->token();
        $run = $this->createRun($token, 'sound');
        $run->update(['status' => 'ready', 'result_json' => ['draft' => ['title' => 'Parent', 'content_html' => '<p>Parent</p>']]]);
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', [
            'prompt_key' => null, 'provider' => null, 'model' => '', 'model_id' => null, 'fields' => ['title', 'content'],
            'refresh_source' => true,
        ])->assertAccepted()->assertJsonPath('data.target_type', 'sound')->assertJsonPath('data.parent_id', $run->id);
        $child = AiImport::findOrFail($response->json('data.job_id'));
        $this->assertSame($run->input_json['ai_connection'], $child->input_json['ai_connection']);
        $this->assertSame('Parent', data_get($run->fresh()->result_json, 'draft.title'));
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')->assertJsonCount(2, 'data');
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/apply', ['fields' => ['title', 'content']])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['prompt_key' => 'post.create.from_text'])->assertUnprocessable();
    }

    /**
     * =====================================================================
     * Input: xóa run đang chạy/ready. Output: 409 hoặc xóa chính candidate, giữ child đã tạo.
     * =====================================================================
     */
    public function test_remove_rejects_running_and_preserves_other_candidates(): void
    {
        $token = $this->token();
        $run = $this->createRun($token);
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertConflict();
        $run->update(['status' => 'ready']);
        $child = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['refresh_source' => true])->assertAccepted()->json('data.job_id');
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk();
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
        $this->assertDatabaseHas('ai_imports', ['id' => $child]);
    }
}
