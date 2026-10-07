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
 *
 * PHPUnit kiểm workspace nhiều target, quyền theo target/owner và optimistic version.
 * Các ca bao gồm tạo/sửa/regenerate/xóa candidate qua API và sanitize output.
 * Queue/provider và database được cô lập; không gọi AI hoặc sửa dữ liệu ứng dụng.
 *
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
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Fixtures, HTTP request và dependency test đã cô lập.
 * - OUTPUT: Assertions cho output, quyền, validation và lifecycle hiện có.
 * - SIDE EFFECT: Tạo/đọc/sửa dữ liệu ở DB test; setup/teardown quản lý schema và connection riêng.
 * - EXCEPTION/TRANSACTION: Lỗi assertion/dependency truyền ra PHPUnit; transaction code nghiệp vụ chạy trong môi trường test.
 * =====================================================================
 */
class AiContentWorkspaceApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị môi trường cô lập trước mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - PHPUnit lifecycle.
     *
     * OUTPUT:
     * - schema SQLite và permission seed cô lập.
     *
     * SIDE EFFECT:
     * - Khởi tạo app và schema SQLite in-memory qua setup của class; không đổi database ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi setup/schema truyền ra PHPUnit; không mở transaction nghiệp vụ bao toàn bộ ca test.
     *
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
     * CHỨC NĂNG: Dọn môi trường cô lập sau mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - kết thúc test.
     *
     * OUTPUT:
     * - dọn DB cô lập.
     *
     * SIDE EFFECT:
     * - Rollback hoặc drop schema test và giải phóng connection theo teardown của class; không dọn dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi teardown truyền ra PHPUnit; không gọi provider hoặc mở transaction nghiệp vụ mới.
     *
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị actor và token admin cho request kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - permission tùy chọn.
     *
     * OUTPUT:
     * - token admin test; không dùng credential thật.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Tạo AI run qua API để kiểm snapshot và lifecycle
     * =====================================================================
     *
     * INPUT:
     * - token và target.
     *
     * OUTPUT:
     * - run queued, không thực thi worker.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử ba target cùng nguồn
     * =====================================================================
     *
     * INPUT:
     * - ba target cùng nguồn.
     *
     * OUTPUT:
     * - identity/hash riêng và labels theo config.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử actor chỉ có quyền Resource
     * =====================================================================
     *
     * INPUT:
     * - actor chỉ có quyền Resource.
     *
     * OUTPUT:
     * - không thấy/không tạo/sửa Post.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử resource/Sound run
     * =====================================================================
     *
     * INPUT:
     * - Resource/Sound run.
     *
     * OUTPUT:
     * - pipeline dùng đúng prompt target và chỉ tạo candidate văn bản.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử sửa title/content và phiên bản cũ
     * =====================================================================
     *
     * INPUT:
     * - sửa title/content và phiên bản cũ.
     *
     * OUTPUT:
     * - HTML sạch; stale/applied/busy không sửa được.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử tạo lại Sound với optional trống
     * =====================================================================
     *
     * INPUT:
     * - tạo lại Sound với optional trống.
     *
     * OUTPUT:
     * - child/list tồn tại, parent giữ nguyên, không đổi model.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử xóa run đang chạy/ready
     * =====================================================================
     *
     * INPUT:
     * - xóa run đang chạy/ready.
     *
     * OUTPUT:
     * - 409 hoặc xóa chính candidate, giữ child đã tạo.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_remove_rejects_running_and_preserves_other_candidates(): void
    {
        $token = $this->token();
        $run = $this->createRun($token);
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertConflict();
        // Candidate legacy dựng trực tiếp; chưa hoàn tất qua worker archive v1.
        $run->update(['archive_version' => null, 'status' => 'ready']);
        $child = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['refresh_source' => true])->assertAccepted()->json('data.job_id');
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk();
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
        $this->assertDatabaseHas('ai_imports', ['id' => $child]);
    }
}
