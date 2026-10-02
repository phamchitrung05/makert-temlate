<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\User;
use App\Services\Ai\ArticleImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa contract HTTP và quyền của AI import queue API.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), token() và test_* tạo/poll/cancel ownership.
 * - test_store_rejects_provider_model_and_prompt_outside_allowlist(): kiểm tra input AI.
 * - test_session_list_*(): kiểm tra quyền, owner, phân trang và DTO summary an toàn.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : request admin URL/options.
 * - OUTPUT: assertion 202 queued, lifecycle payload và ownership boundary.
 * - SIDE EFFECT: database SQLite cô lập và fake queue; không gọi provider thật.
 * =====================================================================
 */
class AiImportApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo database và permission cô lập cho test AI import.
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: schema cô lập và permission đã seed.
     * SIDE EFFECT: reset database và permission cache.
     * EXCEPTION/TRANSACTION: chỉ setup test; không gọi provider thật.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giải phóng database cô lập sau test AI import.
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: tài nguyên test được giải phóng.
     * SIDE EFFECT: dọn database cô lập.
     * EXCEPTION/TRANSACTION: chỉ cleanup test; không gọi provider thật.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo Sanctum token với quyền quản lý Post cho test.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: personal Sanctum token có posts.manage.
     * SIDE EFFECT: tạo user test và flush permission cache.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.
     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-import-test', ['admin'])->plainTextToken;
    }

    /** Input: collection không có quyền. Output: HTTP 401/403; DB cô lập, không gọi AI. */
    public function test_session_list_requires_authenticated_post_manager(): void
    {
        $this->getJson('/api/admin/ai-agent/sessions')->assertUnauthorized();
        $user = User::factory()->create(['status' => 'active']);
        Auth::forgetGuards();
        $this->withToken($user->createToken('list-test', ['admin'])->plainTextToken)
            ->getJson('/api/admin/ai-agent/sessions')->assertForbidden();
    }

    /** Input: query sai. Output: validation 422; không truy vấn provider hoặc ghi run. */
    public function test_session_list_validates_pagination(): void
    {
        $this->withToken($this->token())->getJson('/api/admin/ai-agent/sessions?page=0&per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
    }

    /**
     * Input: run gốc/con/ảnh/hết hạn và run của owner khác.
     * Output: chỉ root còn hạn của owner, phân trang; không lộ body/input/URL query/key.
     * Side effect: fake queue và SQLite cô lập; không gọi provider thật.
     */
    public function test_session_list_filters_owner_root_and_expiry_and_returns_safe_summaries(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'post', 'operation' => 'create', 'input' => ['type' => 'text', 'text' => 'Private source content'],
        ])->assertAccepted();
        $root = AiImport::query()->firstOrFail();
        $root->forceFill([
            'status' => 'ready', 'source_url' => 'https://example.test/article?token=private-token',
            'input_json' => ['source_type' => 'url', 'model' => 'text-model', 'api_key' => 'private-key'],
            'result_json' => ['draft' => ['title' => 'Article title', 'content_html' => '<p>Private full article</p>']],
        ])->save();
        $second = $root->replicate();
        $second->save();
        $child = $root->replicate()->fill(['parent_id' => $root->id]);
        $child->save();
        $image = $root->replicate()->fill(['operation' => 'image']);
        $image->save();
        $expired = $root->replicate()->fill(['expires_at' => now()->subMinute()]);
        $expired->save();
        $other = $root->replicate()->fill(['created_by' => User::factory()->create(['status' => 'active'])->id]);
        $other->save();

        $response = $this->withToken($token)->getJson('/api/admin/ai-agent/sessions?per_page=1');
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.last_page', 2)->assertJsonPath('data.0.title', 'Article title')
            ->assertJsonPath('data.0.source_host', 'example.test');
        $this->assertArrayNotHasKey('input_json', $response->json('data.0'));
        $this->assertArrayNotHasKey('result_json', $response->json('data.0'));
        $this->assertStringNotContainsString('private-key', $response->getContent());
        $this->assertStringNotContainsString('private-token', $response->getContent());
        $this->assertStringNotContainsString('Private full article', $response->getContent());
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng import lưu options và dispatch queue.
     * =====================================================================
     * INPUT: URL/options import.
     * OUTPUT: assertion 202 và job queued.
     * SIDE EFFECT: fake queue, ghi AiImport test.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.
     * =====================================================================
     */
    public function test_store_queues_import_and_persists_options(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'language' => 'vi', 'generate_thumbnail' => true,
        ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.progress', 0);
        $this->assertDatabaseHas('ai_imports', ['status' => 'queued', 'source_url' => 'https://example.test/article']);
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng status import chỉ được đọc bởi owner.
     * =====================================================================
     * INPUT: UUID import của user khác.
     * OUTPUT: assertion ownership 404.
     * SIDE EFFECT: fake queue và database test.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.
     * =====================================================================
     */
    public function test_import_status_is_private_to_creator(): void
    {
        Queue::fake();
        $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', ['url' => 'https://example.test/article'])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $other = User::factory()->create(['status' => 'active']);
        $other->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        $otherToken = $other->createToken('other', ['admin'])->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($otherToken)->getJson('/api/admin/posts/ai/import/'.$import->id)->assertNotFound();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng capabilities được resolve và redact từ registry.
     * =====================================================================
     * INPUT: target Post và quyền AI của admin.
     * OUTPUT: capabilities không lộ endpoint/API key, chỉ chứa provider/model allowlist.
     * SIDE EFFECT: chỉ đọc config; không tạo job hoặc gọi provider.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.
     * =====================================================================
     */
    public function test_capabilities_are_resolved_from_registries(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/admin/ai-agent/capabilities/post');

        $response->assertOk()
            ->assertJsonPath('data.target_type', 'post')
            ->assertJsonPath('data.prompts.0.key', 'post.create.from_url')
            ->assertJsonPath('data.schemas.0.key', 'post.content.v1');
        $configuredKey = (string) config('ai-import.key');
        if ($configuredKey !== '') {
            $this->assertStringNotContainsString($configuredKey, $response->getContent());
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng provider/model/prompt phải nằm trong allowlist.
     * =====================================================================
     * INPUT: provider/model/prompt không nằm allowlist.
     * OUTPUT: validation 422.
     * SIDE EFFECT: không tạo AiImport hoặc dispatch queue.
     * EXCEPTION/TRANSACTION: validation ở HTTP boundary; dùng database cô lập.
     * =====================================================================
     */
    public function test_store_rejects_provider_model_and_prompt_outside_allowlist(): void
    {
        Queue::fake();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'provider' => 'openai',
        ])->assertStatus(422)->assertJsonValidationErrors('provider');

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'provider' => 'deterministic', 'model' => 'unknown',
        ])->assertStatus(422)->assertJsonValidationErrors('model');

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'prompt_key' => 'post.unknown',
        ])->assertStatus(422)->assertJsonValidationErrors('prompt_key');

        Queue::assertNothingPushed();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng import hỗ trợ nguồn text inline.
     * =====================================================================
     * INPUT: text inline thay cho URL.
     * OUTPUT: queued run có source_type=text.
     * SIDE EFFECT: lưu source_text và dispatch job qua Queue fake; không gọi HTTP.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.
     * =====================================================================
     */
    public function test_store_accepts_inline_text_source(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'text' => "Tiêu đề inline\n\nNội dung do quản trị viên nhập.",
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.source_type', 'text')
            ->assertJsonPath('data.source_url', null);
        $this->assertDatabaseHas('ai_imports', [
            'status' => 'queued',
            'source_url' => '',
            'source_text' => "Tiêu đề inline\n\nNội dung do quản trị viên nhập.",
        ]);
        $this->assertSame('post.create.from_text', data_get(AiImport::query()->firstOrFail()->input_json, 'prompt_key'));
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng session endpoint chuẩn hóa nested input.
     * =====================================================================
     * INPUT: contract session generic từ aiAgentService.
     * OUTPUT: AiImport queued tương thích pipeline hiện tại.
     * SIDE EFFECT: chuẩn hóa input ở FormRequest, lưu run và dispatch qua Queue fake.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.
     * =====================================================================
     */
    public function test_generic_session_endpoint_normalizes_nested_input(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'post',
            'operation' => 'create',
            'input' => ['type' => 'text', 'text' => 'Nội dung generic session'],
            'output_language' => 'vi',
            'requested_outputs' => ['title', 'content'],
            'provider' => 'deterministic',
            'model' => 'deterministic',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.source_type', 'text')
            ->assertJsonPath('data.status', 'queued');
        $import = AiImport::query()->firstOrFail();
        $this->assertSame('Nội dung generic session', $import->source_text);
        $this->assertFalse((bool) data_get($import->input_json, 'generate_thumbnail'));
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng cancelled là trạng thái terminal của worker.
     * =====================================================================
     * INPUT: import đang chạy và request cancel của chính owner.
     * OUTPUT: lifecycle cancelled; worker nhận job cũ không chạy pipeline lại.
     * SIDE EFFECT: cập nhật status/error code, không gọi provider hoặc source fetcher.
     * EXCEPTION/TRANSACTION: dùng database cô lập và mock service.
     * =====================================================================
     */
    public function test_cancelled_import_is_terminal_for_queued_worker(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/cancel',
        ])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $import->update(['status' => 'fetching', 'current_step' => 'fetching', 'progress' => 15]);

        $this->withToken($token)
            ->postJson('/api/admin/posts/ai/import/'.$import->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.error_code', 'CANCELLED');

        $service = Mockery::mock(ArticleImportService::class);
        $service->shouldNotReceive('run');
        (new ProcessAiImportJob($import->id))->handle($service);

        $this->assertSame('cancelled', $import->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng cleanup command dọn candidate hết hạn.
     * =====================================================================
     * INPUT: candidate đã quá expires_at.
     * OUTPUT: scheduler command xóa candidate hết hạn.
     * SIDE EFFECT: dọn AiImport qua command chính thức, không gọi provider.
     * EXCEPTION/TRANSACTION: chỉ xóa fixture trong database test cô lập.
     * =====================================================================
     */
    public function test_cleanup_command_removes_expired_import(): void
    {
        Queue::fake();
        $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/expired',
        ])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $import->update(['status' => 'ready', 'expires_at' => now()->subMinute()]);

        $this->artisan('ai-import:cleanup')
            ->expectsOutput('Đã dọn 1 AI import.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('ai_imports', ['id' => $import->id]);
    }
}
