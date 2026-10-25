<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Jobs\ProcessSocialPostCaptionJob;
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
 * CHỨC NĂNG FILE: Kiểm workspace target AI hiện có và optimistic version của candidate.
 * =====================================================================
 *
 * PHPUnit kiểm workspace Post/Social, quyền theo target/owner và optimistic version.
 * Các ca bao gồm tạo/sửa/regenerate/xóa candidate qua API và sanitize output.
 * Queue/provider và database được cô lập; không gọi AI hoặc sửa dữ liệu ứng dụng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - token().
 * - createRun().
 * - test_targets_and_creation_are_driven_by_config().
 * - test_social_target_is_available_to_social_post_creators().
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
    private function token(array $permissions = ['posts.manage', 'social_posts.create']): string
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
     * - token, target và requested_outputs tùy chọn.
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
    private function createRun(string $token, string $target = 'post', array $outputs = ['title']): AiImport
    {
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => $target, 'input' => ['type' => 'text', 'text' => 'Nguồn đã xác thực.'],
            'provider' => 'deterministic', 'model' => 'deterministic', 'requested_outputs' => $outputs,
        ])->assertAccepted()->assertJsonPath('data.task_run_id', fn ($value): bool => is_string($value) && $value !== '');

        return AiImport::findOrFail($response->json('data.job_id'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử target catalog và create theo config
     * =====================================================================
     *
     * INPUT:
     * - target được bật trong config backend.
     *
     * OUTPUT:
     * - identity/hash riêng và prompt key theo config.
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
            ->assertJsonPath('data.0.key', 'post')->assertJsonPath('data.1.key', 'social_post');
        $run = $this->createRun($token, 'post');
        $this->assertSame('post', $run->input_json['target_type']);
        $this->assertSame('post.create.from_text', $run->input_json['prompt_key']);
        Queue::assertPushed(ProcessAiImportJob::class, 1);

        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')->assertJsonCount(1, 'data');
        config(['ai.agent.targets.lesson' => [
            'enabled' => true, 'label' => 'Lesson', 'permission' => 'posts.manage',
            'content_instructions' => 'Write a lesson.', 'inputs' => ['text'], 'outputs' => ['title', 'content'],
        ]]);
        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertJsonPath('data.2.label', 'Lesson');
        $this->createRun($token, 'lesson', ['title', 'content']);
        config(['ai.agent.targets.lesson.enabled' => false]);
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'lesson', 'text' => 'A lesson',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_type');
    }

    public function test_configured_lengths_and_image_count_are_validated_and_snapshotted(): void
    {
        $token = $this->token();
        $option = ['label' => 'Siêu ngắn', 'min_words' => 10, 'max_words' => 20, 'prompt' => 'Viết từ 10 đến 20 từ.'];
        config(['ai.content.length_options.tiny' => $option, 'ai.content.default_length' => 'tiny']);
        $response = $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertOk();
        $this->assertContains(['value' => 'tiny', 'title' => 'Siêu ngắn'] + $option, $response->json('data.0.length_options'));
        $payload = ['target_type' => 'social_post', 'text' => 'Nguồn đã kiểm chứng.', 'provider' => 'deterministic', 'model' => 'deterministic',
            'requested_outputs' => ['social_content', 'social_gallery'], 'length' => 'tiny', 'image_count' => 7];
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', $payload)->assertAccepted()->json('data.job_id');
        $run = AiImport::findOrFail($id);
        $this->assertSame($option, data_get($run->input_json, 'length_snapshot'));
        $this->assertSame(7, data_get($run->input_json, 'image_count'));
        $this->assertSame($option['prompt'], data_get($run->input_json, 'length_snapshot.prompt'));
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', array_replace($payload, ['length' => 'missing']))->assertUnprocessable()->assertJsonValidationErrors('length');
        foreach ([0, 11, 1.5] as $count) {
            $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', array_replace($payload, ['image_count' => $count]))->assertUnprocessable()->assertJsonValidationErrors('image_count');
        }
    }

    /**
     * Social là target riêng của AI Content và dùng permission Social Post.
     */
    public function test_social_target_is_available_to_social_post_creators(): void
    {
        $token = $this->token(['social_posts.create']);

        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'social_post')
            ->assertJsonPath('data.0.label', 'Social')
            ->assertJsonPath('data.0.outputs', ['social_content', 'social_gallery'])
            ->assertJsonPath('data.0.output_options.0.value', 'social_content');

        $run = $this->createRun($token, 'social_post', ['social_content']);
        $this->assertSame('social_post', $run->input_json['target_type']);
        $this->assertSame('social_post.facebook.create.from_text', $run->input_json['prompt_key']);
        // Social giờ đi pipeline chuẩn giống Post: candidate queued qua ProcessAiImportJob,
        // chỉ duyệt xong mới tạo SocialPost + queue ảnh.
        Queue::assertPushed(ProcessAiImportJob::class, 1);
        Queue::assertNotPushed(ProcessSocialPostCaptionJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử actor chỉ có quyền Social Post
     * =====================================================================
     *
     * INPUT:
     * - actor chỉ có quyền Social.
     *
     * OUTPUT:
     * - không thấy/tạo/sửa run Post của người khác.
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
        $token = $this->token(['social_posts.create']);
        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertOk()->assertJsonCount(1, 'data');
        $run = $this->createRun($token, 'social_post', ['social_content']);
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'post', 'requested_outputs' => ['title'], 'text' => 'Not allowed',
        ])->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/ai-agent/capabilities/post')->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/posts')->assertForbidden();
        $other = $this->token();
        $this->withToken($other)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
        $this->withToken($other)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử Post run dùng prompt target và sanitize output
     * =====================================================================
     *
     * INPUT:
     * - Post run với provider giả trả HTML không an toàn.
     *
     * OUTPUT:
     * - pipeline dùng đúng prompt target và chỉ giữ markup an toàn.
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
        $run = $this->createRun($token, 'post');
        $provider = Mockery::mock(AiProviderContract::class);
        $provider->shouldReceive('configured')->andReturnTrue();
        $provider->shouldReceive('generate')->once()
            ->andReturn(['title' => 'Nội dung bài viết', 'content_html' => '<p onclick="bad()">Nội dung <strong>an toàn</strong></p><script>bad()</script>']);
        $provider->shouldReceive('providerName')->andReturn('fake');
        $provider->shouldReceive('modelName')->andReturn('fake');
        $result = (new ArticleImportService($provider))->run($run);
        $this->assertSame('post.create.from_text', $result['prompt_key']);
        $this->assertStringNotContainsString('script', $result['draft']['content_html']);
        $this->assertStringNotContainsString('onclick', $result['draft']['content_html']);
        $this->assertDatabaseCount('posts', 0);
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
     * - HTML sạch; stale/applied không sửa được.
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
        $run = $this->createRun($token, 'post');
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
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử regenerate với override rỗng
     * =====================================================================
     *
     * INPUT:
     * - regenerate với override rỗng và prompt không thuộc target.
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
        $run = $this->createRun($token, 'post');
        $run->update(['status' => 'ready', 'result_json' => ['draft' => ['title' => 'Parent', 'content_html' => '<p>Parent</p>'], 'generation_meta' => ['content_origin' => 'ai']]]);
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', [
            'prompt_key' => null, 'provider' => null, 'model' => '', 'model_id' => null,
            'refresh_source' => true,
        ])->assertAccepted()->assertJsonPath('data.target_type', 'post')->assertJsonPath('data.parent_id', $run->id);
        $child = AiImport::findOrFail($response->json('data.job_id'));
        $this->assertSame($run->input_json['ai_connection'], $child->input_json['ai_connection']);
        $this->assertSame('Parent', data_get($run->fresh()->result_json, 'draft.title'));
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')->assertJsonCount(2, 'data');
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['prompt_key' => 'social_post.facebook.create.from_text'])->assertUnprocessable();
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
        $run = $this->createRun($token, 'post');
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertConflict();
        $run->update(['status' => 'ready', 'result_json' => ['draft' => ['title' => 'Parent'], 'generation_meta' => ['content_origin' => 'ai']]]);
        $child = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['refresh_source' => true])->assertAccepted()->json('data.job_id');
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk();
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertNotFound();
        $this->assertDatabaseHas('ai_imports', ['id' => $child]);
    }
}
