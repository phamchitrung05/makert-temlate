<?php

namespace Tests\Feature;

use App\Enums\AiCapability;
use App\Jobs\ProcessAiImageGenerationJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\AiImageGenerationService;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\ArticleImportService;
use App\Services\Ai\ModelResolver;
use App\Services\Ai\Registries\ProviderRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa contract provider catalog, key redaction và defaults
 * =====================================================================
 *
 * Test dùng database cô lập và HTTP fake; không gọi endpoint AI thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(), tearDown(), token(), connection(): quản lý fixture cô lập.
 * - test_*(): kiểm tra quyền, mã hóa key, catalog sync, model resolver và image run.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: request admin và response HTTP giả lập.
 * - OUTPUT: assertions về security boundary, defaults và lifecycle AI.
 * - SIDE EFFECT: database test cô lập, Queue fake và HTTP fake.
 * - EXCEPTION/TRANSACTION: lỗi validation được assert; không gọi AI thật.
 * =====================================================================
 */
final class AiProviderSettingsApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo database và permission cô lập cho test settings AI.
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: Schema test và permission đã seed.
     * SIDE EFFECT: Reset database và permission cache.
     * EXCEPTION/TRANSACTION: Chỉ setup test; không gọi provider thật.
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
     * CHỨC NĂNG: Giải phóng database cô lập sau test settings AI.
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: Tài nguyên test được giải phóng.
     * SIDE EFFECT: Dọn database cô lập.
     * EXCEPTION/TRANSACTION: Chỉ cleanup test; không gọi provider thật.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo admin token với permission phục vụ test AI
     * =====================================================================
     * INPUT: Fixture user active và các quyền settings/posts/media.
     * OUTPUT: Plaintext token chỉ dùng trong test.
     * SIDE EFFECT: Ghi user/permission/token vào database test, xóa permission cache.
     * EXCEPTION/TRANSACTION: Database cô lập được dọn trong tearDown; không gọi AI thật.
     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo(['ai_settings.manage', 'posts.manage', 'media.view', 'media.upload']);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-provider-settings-test', ['admin'])->plainTextToken;
    }

    /**
     * Input: Catalog có text-only, image-only, unknown và disabled models.
     * Output: Text-only hiển thị/chạy được; output sai JSON vẫn bị từ chối.
     * Side effect: Database cô lập, HTTP/queue fake; không gọi model thật.
     */
    public function test_text_capability_alone_is_visible_and_runs_without_native_json_mode(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $token = $this->token();
        $provider = $this->connection();
        $text = $provider->models()->create(['remote_model_id' => 'text-only', 'label' => 'Text', 'capabilities' => ['text_generation']]);
        $provider->models()->create(['remote_model_id' => 'image-only', 'label' => 'Image', 'capabilities' => ['image_generation']]);
        $provider->models()->create(['remote_model_id' => 'unknown', 'label' => 'Unknown', 'capabilities' => []]);
        $provider->models()->create(['remote_model_id' => 'disabled', 'label' => 'Disabled', 'capabilities' => ['text_generation'], 'is_enabled' => false]);

        $options = $this->withToken($token)->getJson('/api/admin/ai-agent/capabilities/post')->assertOk()->json('data.providers');
        $option = collect($options)->firstWhere('key', $provider->key);
        $this->assertSame(['text-only'], $option['models']);
        $this->assertSame($text->id, $option['model_options'][0]['id']);

        Http::fake(['https://gateway.example/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['title' => 'AI title', 'content_html' => '<p>AI content</p>'])]]]])
            ->push(['choices' => [['message' => ['content' => 'not JSON']]]]),
        ]);
        $request = [
            'target_type' => 'post', 'operation' => 'create',
            'input' => ['type' => 'text', 'text' => 'Nội dung nguồn đủ dài để kiểm thử tạo bài viết bằng model content.'],
            'provider' => $provider->key, 'model' => 'text-only', 'requested_outputs' => ['title', 'content'],
        ];
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', $request)
            ->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.job_id');
        Queue::assertPushed(ProcessAiImportJob::class, fn ($job): bool => $job->importId === $id);
        $run = AiImport::findOrFail($id);
        $this->assertSame(['text_generation'], data_get($run->input_json, 'ai_connection.capabilities'));
        (new ProcessAiImportJob($id))->handle(app(ArticleImportService::class));
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.draft.title', 'AI title');
        Http::assertSent(fn ($request): bool => $request['model'] === 'text-only' && ! isset($request['response_format']));

        $childId = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$id.'/regenerate', ['instructions' => 'Thử lại'])
            ->assertStatus(202)->json('data.job_id');
        (new ProcessAiImportJob($childId))->handle(app(ArticleImportService::class));
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$childId)
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'AI_PROVIDER_INVALID_JSON');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng key mã hóa/write-only và default ảnh
     * =====================================================================
     * INPUT: Provider/key giả, model có image_generation capability.
     * OUTPUT: Assertions xác nhận key không lộ và default_image_model_id được lưu.
     * SIDE EFFECT: Gọi API nội bộ, ghi fixtures và settings trong DB test.
     * EXCEPTION/TRANSACTION: Không gọi model thật; database cô lập được dọn trong tearDown.
     * =====================================================================
     */
    public function test_provider_key_is_write_only_and_model_can_be_selected_as_image_default(): void
    {
        $token = $this->token();
        $provider = $this->withToken($token)->postJson('/api/admin/settings/ai/providers', [
            'name' => 'Test Gateway', 'driver' => 'openai-compatible',
            'base_url' => 'https://gateway.example/v1', 'api_key' => 'secret-test-key',
            'discovery_mode' => 'manual',
        ])->assertCreated()->assertJsonPath('data.has_api_key', true);
        $this->assertArrayNotHasKey('api_key', $provider->json('data'));
        $record = AiProvider::query()->firstOrFail();
        $this->assertNotSame('secret-test-key', $record->getRawOriginal('api_key'));

        $model = $this->withToken($token)->postJson('/api/admin/settings/ai/providers/'.$record->id.'/models', [
            'remote_model_id' => 'image-model', 'label' => 'Image model', 'capabilities' => ['image_generation'],
        ])->assertCreated()->json('data');
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_image_model_id' => $model['id']])
            ->assertOk()->assertJsonPath('data.default_image_model_id', $model['id']);
        $this->assertDatabaseHas('settings', ['group' => 'ai', 'key' => 'default_image_model_id']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng test model không chặn trước theo capability.
     * =====================================================================
     * INPUT: image-only model và response thành công từ endpoint upstream.
     * OUTPUT: request test được gửi đến provider và trả success.
     * SIDE EFFECT: HTTP fake ghi nhận request; provider lưu trạng thái test.
     * EXCEPTION/TRANSACTION: Không gọi AI thật; lỗi tương thích thuộc upstream.
     * =====================================================================
     */
    public function test_model_test_delegates_capability_validation_to_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);
        $token = $this->token();
        $provider = $this->connection();
        $model = $provider->models()->create([
            'remote_model_id' => 'image-model', 'label' => 'Image model',
            'capabilities' => ['image_generation'], 'capability_source' => 'admin',
        ]);

        $this->withToken($token)->postJson('/api/admin/settings/ai/providers/'.$provider->id.'/test', [
            'model_id' => $model->id,
        ])->assertOk()->assertJsonPath('data.status', 'success');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.example/v1/chat/completions'
            && data_get($request->data(), 'model') === 'image-model');
        $this->assertSame('success', $provider->fresh()->test_status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng test model trả lỗi upstream thay vì chặn capability.
     * =====================================================================
     * INPUT: image-only model và HTTP 403 từ provider.
     * OUTPUT: API trả lỗi quyền an toàn và provider có test_status failed.
     * SIDE EFFECT: Gọi HTTP fake; ghi trạng thái test trong database cô lập.
     * EXCEPTION/TRANSACTION: Không gọi AI thật hoặc lộ response chứa secret.
     * =====================================================================
     */
    public function test_model_test_reports_upstream_errors_without_leaking_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response([
                'error' => ['message' => 'denied offline-key'],
            ], 403),
        ]);
        $token = $this->token();
        $provider = $this->connection();
        $model = $provider->models()->create([
            'remote_model_id' => 'image-model', 'label' => 'Image model',
            'capabilities' => ['image_generation'],
        ]);

        $response = $this->withToken($token)->postJson('/api/admin/settings/ai/providers/'.$provider->id.'/test', [
            'model_id' => $model->id,
        ])->assertUnprocessable()->assertJsonPath('errors.code.0', 'AI_PROVIDER_HTTP_403');

        Http::assertSentCount(1);
        $this->assertSame('failed', $provider->fresh()->test_status);
        $this->assertStringNotContainsString('offline-key', $response->getContent());
        $this->assertStringNotContainsString('offline-key', $provider->fresh()->test_message);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng sync giữ model cũ dưới trạng thái unavailable
     * =====================================================================
     * INPUT: Catalog HTTP fake và một model remote không còn xuất hiện.
     * OUTPUT: Assertions model mới available, model stale vẫn tồn tại nhưng unavailable.
     * SIDE EFFECT: Gọi API sync qua HTTP fake; ghi catalog vào DB test.
     * EXCEPTION/TRANSACTION: Không kết nối gateway thật; DB test được dọn sau test.
     * =====================================================================
     */
    public function test_sync_imports_models_without_deleting_stale_catalog(): void
    {
        Http::fake(['https://gateway.example/v1/models' => Http::response(['data' => [
            ['id' => 'text-model', 'owned_by' => 'test'],
        ]])]);
        $token = $this->token();
        $response = $this->withToken($token)->postJson('/api/admin/settings/ai/providers', [
            'name' => 'Sync Gateway', 'driver' => 'openai-compatible',
            'base_url' => 'https://gateway.example/v1', 'api_key' => 'sync-key',
        ])->assertCreated();
        $provider = AiProvider::query()->firstOrFail();
        $stale = $provider->models()->create(['remote_model_id' => 'old-model', 'label' => 'Old', 'capabilities' => [], 'discovery_source' => 'remote']);

        $this->withToken($token)->postJson('/api/admin/settings/ai/providers/'.$provider->id.'/sync')
            ->assertOk()->assertJsonPath('data.count', 1);
        $this->assertDatabaseHas('ai_models', ['remote_model_id' => 'text-model', 'is_available' => 1]);
        $this->assertFalse((bool) $stale->fresh()->is_available);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng ảnh được xếp hàng thành run riêng không chứa key
     * =====================================================================
     * INPUT: Image-capable model và prompt; Queue fake.
     * OUTPUT: 202 chứa run snapshot không có API key và image job được dispatch.
     * SIDE EFFECT: Ghi image run vào DB test; job chỉ được ghi nhận bởi Queue fake.
     * EXCEPTION/TRANSACTION: Không tạo ảnh hoặc gọi endpoint AI thật.
     * =====================================================================
     */
    public function test_image_generation_queues_a_separate_run(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/settings/ai/providers', [
            'name' => 'Image Gateway', 'driver' => 'openai-compatible',
            'base_url' => 'https://gateway.example/v1', 'api_key' => 'image-key', 'discovery_mode' => 'manual',
        ])->assertCreated();
        $provider = AiProvider::query()->firstOrFail();
        $model = $provider->models()->create([
            'remote_model_id' => 'image-model', 'label' => 'Image model',
            'capabilities' => ['image_generation'], 'capability_source' => 'admin',
        ]);

        $response = $this->withToken($token)->postJson('/api/admin/ai-image/generations', [
            'prompt' => 'A clean editorial illustration', 'model_id' => $model->id,
        ])->assertStatus(202);
        $run = \App\Models\AiImport::query()->findOrFail($response->json('data.job_id'));
        $this->assertArrayNotHasKey('api_key', $run->input_json['ai_connection']);
        Queue::assertPushed(ProcessAiImageGenerationJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng AI settings yêu cầu ai_settings.manage.
     * =====================================================================
     * INPUT: authenticated admin thiếu ai_settings.manage.
     * OUTPUT: AI settings bị chặn 403, không lộ catalog/key.
     * SIDE EFFECT: chỉ ghi fixture trong database test cô lập.
     * EXCEPTION/TRANSACTION: authorization được assert tại HTTP boundary.
     * =====================================================================
     */
    public function test_ai_settings_requires_manage_permission(): void
    {
        $this->getJson('/api/admin/settings/ai')->assertUnauthorized();
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->withToken($user->createToken('no-settings', ['admin'])->plainTextToken)
            ->getJson('/api/admin/settings/ai')->assertForbidden();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        $this->withToken($admin->createToken('ai-settings', ['admin'])->plainTextToken)
            ->getJson('/api/admin/settings/ai')->assertOk();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng update provider giữ hoặc rotate key đúng contract.
     * =====================================================================
     * INPUT: edit provider không truyền key hoặc truyền key mới.
     * OUTPUT: giữ key cũ hoặc rotate encrypted key; response luôn write-only.
     * SIDE EFFECT: database test cô lập; không gọi provider thật.
     * EXCEPTION/TRANSACTION: database cô lập được dọn trong tearDown.
     * =====================================================================
     */
    public function test_key_rotation_preserves_key_when_omitted(): void
    {
        $token = $this->token();
        $provider = $this->connection();
        $payload = ['name' => 'Renamed', 'driver' => 'openai-compatible', 'base_url' => $provider->base_url];
        $this->withToken($token)->putJson('/api/admin/settings/ai/providers/'.$provider->id, $payload)->assertOk();
        $this->assertSame('offline-key', $provider->fresh()->api_key);
        $response = $this->withToken($token)->putJson('/api/admin/settings/ai/providers/'.$provider->id, $payload + ['api_key' => 'rotated-key'])
            ->assertOk();
        $this->assertArrayNotHasKey('api_key', $response->json('data'));
        $this->assertSame('rotated-key', $provider->fresh()->api_key);
        $this->assertStringNotContainsString('rotated-key', $provider->fresh()->getRawOriginal('api_key'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng provider URL chặn outbound không an toàn.
     * =====================================================================
     * INPUT: HTTPS URL localhost/private/credential/query và HTTP URL.
     * OUTPUT: validation 422 trước khi HTTP outbound.
     * SIDE EFFECT: Http fake để đảm bảo không có network.
     * EXCEPTION/TRANSACTION: validation được assert tại HTTP boundary.
     * =====================================================================
     */
    public function test_provider_url_rejects_unsafe_egress(): void
    {
        Http::fake();
        $token = $this->token();
        foreach (['http://gateway.example/v1', 'https://127.0.0.1/v1', 'https://10.0.0.1/v1', 'https://user:pass@gateway.example/v1', 'https://gateway.example/v1?key=secret'] as $url) {
            $this->withToken($token)->postJson('/api/admin/settings/ai/providers', [
                'name' => 'Unsafe', 'driver' => 'openai-compatible', 'api_key' => 'offline-key', 'base_url' => $url,
            ])->assertUnprocessable();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_providers', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng sync thất bại giữ catalog và capability admin.
     * =====================================================================
     * INPUT: catalog malformed và model có capability do admin xác nhận.
     * OUTPUT: lỗi sync giữ state cũ; resync thành công vẫn giữ capability admin.
     * SIDE EFFECT: fake HTTPS và database test cô lập.
     * EXCEPTION/TRANSACTION: sync exception được assert qua response 422.
     * =====================================================================
     */
    public function test_sync_failure_preserves_catalog_and_admin_capability(): void
    {
        $token = $this->token();
        $provider = $this->connection(['discovery_mode' => 'models_endpoint']);
        $model = $provider->models()->create([
            'remote_model_id' => 'model.with.dots', 'label' => 'Admin model',
            'capabilities' => ['text_generation', 'structured_output'], 'capability_source' => 'admin', 'discovery_source' => 'remote',
        ]);
        Http::fake(['https://gateway.example/v1/models' => Http::sequence()
            ->push(['data' => 'malformed'])
            ->push(['data' => [['id' => 'model.with.dots']]])]);
        $this->withToken($token)->postJson('/api/admin/settings/ai/providers/'.$provider->id.'/sync')->assertUnprocessable();
        $this->assertTrue($model->fresh()->is_available);
        $this->assertNull($provider->fresh()->last_synced_at);

        $this->withToken($token)->postJson('/api/admin/settings/ai/providers/'.$provider->id.'/sync')->assertOk();
        $this->assertSame(['text_generation', 'structured_output'], $model->fresh()->capabilities);
        $this->assertSame('Admin model', $model->fresh()->label);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng resolver default/fallback và snapshot theo capability.
     * =====================================================================
     * INPUT: default text/image, explicit override và model inactive.
     * OUTPUT: resolver ưu tiên override, dùng fallback khi default inactive;
     * snapshot không đổi khi settings được sửa sau đó.
     * SIDE EFFECT: database test cô lập; không gọi provider.
     * EXCEPTION/TRANSACTION: database cô lập được dọn trong tearDown.
     * =====================================================================
     */
    public function test_resolver_defaults_fallback_and_snapshot_are_capability_aware(): void
    {
        $provider = $this->connection();
        $text = $provider->models()->create(['remote_model_id' => 'text-a', 'label' => 'Text A', 'capabilities' => ['text_generation', 'structured_output']]);
        $other = $provider->models()->create(['remote_model_id' => 'text-b', 'label' => 'Text B', 'capabilities' => ['text_generation', 'structured_output']]);
        $image = $provider->models()->create(['remote_model_id' => 'image-a', 'label' => 'Image A', 'capabilities' => ['image_generation']]);
        $actor = User::factory()->create(['status' => 'active']);
        $settings = app(AiSettingsService::class);
        $settings->update(['default_text_model_id' => $text->id, 'fallback_text_model_id' => $other->id, 'default_image_model_id' => $image->id], $actor->id);
        $resolver = app(ModelResolver::class);
        $snapshot = $resolver->resolve(AiCapability::Text);
        $this->assertSame($text->id, $snapshot['model_id']);
        $this->assertArrayNotHasKey('api_key', $snapshot);
        $this->assertSame($image->id, $resolver->resolve(AiCapability::Image)['model_id']);
        $this->assertSame($other->id, $resolver->resolve(AiCapability::Text, ['model_id' => $other->id])['model_id']);
        $settings->update(['default_text_model_id' => $other->id], $actor->id);
        $this->assertSame('text-a', app(ProviderRegistry::class)->connectionForRun($snapshot, AiCapability::Text)->snapshot['model']);
        $settings->update(['default_text_model_id' => $text->id], $actor->id);
        $text->update(['is_enabled' => false]);
        $this->assertSame($other->id, $resolver->resolve(AiCapability::Text)['model_id']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng model sai capability hoặc unavailable bị từ chối.
     * =====================================================================
     * INPUT: text-only model chọn tạo ảnh/default ảnh hoặc model không available.
     * OUTPUT: validation 422, không queue sai capability.
     * SIDE EFFECT: database test và Queue fake; không gọi provider.
     * EXCEPTION/TRANSACTION: validation được assert tại HTTP boundary.
     * =====================================================================
     */
    public function test_incompatible_or_unavailable_model_is_rejected(): void
    {
        Queue::fake();
        $token = $this->token();
        $provider = $this->connection();
        $model = $provider->models()->create(['remote_model_id' => 'text-only', 'label' => 'Text', 'capabilities' => ['text_generation', 'structured_output']]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_image_model_id' => $model->id])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/ai-image/generations', ['prompt' => 'Illustration', 'model_id' => $model->id])->assertUnprocessable();
        $model->update(['is_available' => false]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_text_model_id' => $model->id])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng lỗi image child không làm hỏng content candidate.
     * =====================================================================
     * INPUT: upstream image 401 cho child run của content ready.
     * OUTPUT: image failed nhưng content vẫn ready; message không chứa provider body/key.
     * SIDE EFFECT: HTTP fake và database test; không tạo ảnh/upload thật.
     * EXCEPTION/TRANSACTION: provider exception được job chuyển thành failed an toàn.
     * =====================================================================
     */
    public function test_image_failure_does_not_fail_content_candidate(): void
    {
        $provider = $this->connection();
        $model = $provider->models()->create(['remote_model_id' => 'image-a', 'label' => 'Image', 'capabilities' => ['image_generation']]);
        $actor = User::factory()->create(['status' => 'active']);
        $parent = AiImport::query()->create([
            'created_by' => $actor->id, 'source_url' => '', 'source_hash' => hash('sha256', 'parent'),
            'status' => 'ready', 'operation' => 'create', 'input_json' => [], 'result_json' => ['draft' => ['title' => 'Content ready']],
        ]);
        $image = AiImport::query()->create([
            'created_by' => $actor->id, 'source_url' => '', 'source_hash' => hash('sha256', 'child'),
            'status' => 'queued', 'operation' => 'image', 'parent_id' => $parent->id,
            'input_json' => ['prompt' => 'Illustration', 'ai_connection' => app(ModelResolver::class)->resolve(AiCapability::Image, ['model_id' => $model->id])],
        ]);
        Http::fake(['https://gateway.example/v1/images/generations' => Http::response(['error' => ['message' => 'upstream secret offline-key']], 401)]);
        (new ProcessAiImageGenerationJob($image->id))->handle(app(AiImageGenerationService::class), app(ProviderRegistry::class));
        $this->assertSame('failed', $image->fresh()->status);
        $this->assertSame('ready', $parent->fresh()->status);
        $this->assertSame('Content ready', $parent->fresh()->result_json['draft']['title']);
        $this->assertStringNotContainsString('offline-key', $image->fresh()->error_message);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo provider fixture với domain và key giả.
     * =====================================================================
     * INPUT: connection fixture overrides.
     * OUTPUT: provider dùng domain test, key giả mã hóa.
     * SIDE EFFECT: chỉ ghi database test cô lập.
     * EXCEPTION/TRANSACTION: database cô lập được dọn trong tearDown.
     * =====================================================================
     */
    private function connection(array $overrides = []): AiProvider
    {
        return AiProvider::query()->create(array_replace([
            'key' => 'offline-'.\Illuminate\Support\Str::uuid(), 'name' => 'Offline Gateway',
            'driver' => 'openai-compatible', 'kind' => 'gateway', 'base_url' => 'https://gateway.example/v1',
            'api_key' => 'offline-key', 'is_active' => true, 'discovery_mode' => 'manual',
        ], $overrides));
    }
}
