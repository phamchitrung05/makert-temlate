<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImageGenerationJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\Content\ArticleImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\ArticlePipelineFixture;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm Settings/default snapshot, pipeline ba bước và chọn field riêng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - test_content_settings_persist_in_shared_contract_and_partial_update_preserves_other_settings().
 * - test_invalid_content_values_and_unusable_text_models_are_rejected_without_changing_saved_defaults().
 * - test_new_run_snapshots_saved_model_temperature_prompt_and_length_and_honors_disabled_automatic_outputs().
 * - test_explicit_output_choices_override_settings_and_regenerate_preserves_unselected_parent_fields().
 * - test_explicit_thumbnail_regeneration_checks_upload_permission_before_resolving_optional_image().
 * - test_content_defaults_are_available_to_authored_runs_but_settings_remain_permission_protected().
 * - test_environment_provider_adapters_also_use_saved_content_tuning().
 * - token().
 * - connection().
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures/requests admin, HTTP và Queue fake, database test cô lập.
 * - OUTPUT: assertions cho contract, snapshot, quyền và lỗi; không gọi AI thật.
 * - SIDE EFFECT: tạo/sửa dữ liệu trong database test; không chỉnh dữ liệu ứng dụng.
 * =====================================================================
 */
final class AiContentSettingsApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo fixtures và cấu hình test.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Fixtures một lượt kiểm riêng baseline được giữ để so sánh rollout.
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
        Queue::fake();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database và state sau test.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_content_settings_persist_in_shared_contract_and_partial_update_preserves_other_settings.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_content_settings_persist_in_shared_contract_and_partial_update_preserves_other_settings(): void
    {
        $token = $this->token();
        $provider = $this->connection();
        $model = $provider->models()->create(['remote_model_id' => 'text', 'label' => 'Text', 'capabilities' => ['text_generation']]);
        $this->withToken($token)->getJson('/api/admin/settings/ai/settings')->assertOk()
            ->assertJsonPath('data.min_word_count', 0)->assertJsonPath('data.default_system_prompt', '')
            ->assertJsonPath('data.auto_thumbnail', true)->assertJsonPath('data.auto_seo', true);
        $payload = [
            'default_text_model_id' => $model->id, 'fallback_text_model_id' => $model->id,
            'default_temperature' => 0.7, 'request_timeout' => 90,
            'min_word_count' => 800, 'default_system_prompt' => 'Viết rõ ràng bằng tiếng Việt.',
            'auto_thumbnail' => false, 'auto_seo' => false,
        ];
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', $payload)->assertOk();
        $loaded = $this->withToken($token)->getJson('/api/admin/settings/ai')->assertOk()->json('data.settings');
        foreach ($payload as $key => $value) {
            $this->assertSame($value, $loaded[$key]);
        }
        $saved = $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['auto_seo' => true])->assertOk()->json('data');
        foreach (array_replace($payload, ['auto_seo' => true]) + [
            'default_image_model_id' => null, 'fallback_image_model_id' => null,
        ] as $key => $value) {
            $this->assertSame($value, $saved[$key]);
        }
        $this->assertSame(800, json_decode(DB::table('settings')->where('group', 'ai')->where('name', 'min_word_count')->value('payload')));
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_system_prompt' => ''])->assertOk()->assertJsonPath('data.default_system_prompt', '');
        $migration = require database_path('settings/2026_10_03_110000_initialize_ai_content_settings.php');
        $migration->up();
        $this->withToken($token)->getJson('/api/admin/settings/ai/settings')->assertOk()->assertJsonPath('data.min_word_count', 800);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_invalid_content_values_and_unusable_text_models_are_rejected_without_changing_saved_defaults.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_invalid_content_values_and_unusable_text_models_are_rejected_without_changing_saved_defaults(): void
    {
        $token = $this->token();
        foreach ([
            ['min_word_count' => -1], ['min_word_count' => 10001], ['min_word_count' => 1.5],
            ['auto_thumbnail' => null], ['auto_seo' => 'yes'], ['default_system_prompt' => str_repeat('x', 10001)],
        ] as $invalid) {
            $this->withToken($token)->putJson('/api/admin/settings/ai/settings', $invalid)->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($invalid));
        }
        $provider = $this->connection();
        foreach ([
            ['remote_model_id' => 'image', 'capabilities' => ['image_generation']],
            ['remote_model_id' => 'disabled', 'capabilities' => ['text_generation'], 'is_enabled' => false],
            ['remote_model_id' => 'unavailable', 'capabilities' => ['text_generation'], 'is_available' => false],
        ] as $attributes) {
            $model = $provider->models()->create($attributes + ['label' => $attributes['remote_model_id']]);
            $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_text_model_id' => $model->id])
                ->assertUnprocessable()->assertJsonValidationErrors('default_text_model_id');
        }
        $model = $provider->models()->create(['remote_model_id' => 'text', 'label' => 'Text', 'capabilities' => ['text_generation']]);
        $provider->update(['is_active' => false]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_text_model_id' => $model->id])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/admin/settings/ai/settings')->assertOk()->assertJsonPath('data.default_text_model_id', null);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_new_run_snapshots_saved_model_temperature_prompt_and_length_and_honors_disabled_automatic_outputs.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_new_run_snapshots_saved_model_temperature_prompt_and_length_and_honors_disabled_automatic_outputs(): void
    {
        $token = $this->token();
        $provider = $this->connection();
        $model = $provider->models()->create(['remote_model_id' => 'text', 'label' => 'Text', 'capabilities' => ['text_generation']]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', [
            'default_text_model_id' => $model->id, 'default_temperature' => 0.6,
            'min_word_count' => 800, 'default_system_prompt' => 'Luôn dùng cách viết đơn giản.',
            'auto_thumbnail' => false, 'auto_seo' => false,
        ])->assertOk();
        $this->withToken($token)->getJson('/api/admin/ai-agent/capabilities/post')->assertOk()
            ->assertJsonPath('data.content_defaults', ['model_id' => $model->id, 'generate_thumbnail' => false, 'generate_seo' => false, 'min_word_count' => 800, 'default_writing_profile_id' => null]);
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'input' => ['type' => 'text', 'text' => 'Nội dung nguồn cho tác vụ có thiết lập mặc định.'],
            'instructions' => 'Viết khoảng 200 từ và giữ nguyên thuật ngữ kỹ thuật.',
        ])->assertStatus(202)->json('data.job_id');
        $run = AiImport::findOrFail($id);
        $this->assertFalse($run->input_json['generate_thumbnail']);
        $this->assertFalse($run->input_json['generate_seo']);
        $this->assertSame($model->id, $run->input_json['ai_connection']['model_id']);
        $this->assertNull($run->input_json['image_connection']);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', [
            'min_word_count' => 1000, 'default_system_prompt' => 'Thiết lập thay đổi sau khi xếp hàng.', 'auto_seo' => true,
        ])->assertOk();
        Http::fake(['https://gateway.example/v1/chat/completions' => fn ($request) => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(ArticlePipelineFixture::httpOutput($request, [
            'title' => 'Bài viết mới', 'content_html' => '<p>Nội dung.</p>', 'seo_title' => ['value' => 'SEO cần bị bỏ'], 'focus_keyword' => ['keyword'],
        ]))]]]])]);
        (new ProcessAiImportJob($id))->handle(app(ArticleImportService::class));
        $run->refresh();
        $this->assertSame('ready', $run->status);
        $this->assertArrayNotHasKey('seo_title', $run->result_json['draft']);
        $this->assertArrayNotHasKey('focus_keyword', $run->result_json['draft']);
        $this->assertSame(800, $run->input_json['ai_connection']['min_word_count']);
        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            $user = json_decode($request['messages'][1]['content'], true);

            return $request['model'] === 'text' && $request['temperature'] === 0.6
                && $user['input']['brief']['website_instructions'] === 'Luôn dùng cách viết đơn giản.'
                && $user['input']['brief']['requested_fields'] === ['title', 'content']
                && str_starts_with($user['input']['brief']['additional_instructions'], 'Viết khoảng 200 từ và giữ nguyên thuật ngữ kỹ thuật.')
                && isset($user['input']['link_requirements']);
        });
        Queue::assertNotPushed(ProcessAiImageGenerationJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_explicit_output_choices_override_settings_and_regenerate_preserves_unselected_parent_fields.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_explicit_output_choices_override_settings_and_regenerate_preserves_unselected_parent_fields(): void
    {
        $token = $this->token();
        $provider = $this->connection();
        $text = $provider->models()->create(['remote_model_id' => 'text', 'label' => 'Text', 'capabilities' => ['text_generation']]);
        $image = $provider->models()->create(['remote_model_id' => 'image', 'label' => 'Image', 'capabilities' => ['image_generation']]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', [
            'default_text_model_id' => $text->id, 'default_image_model_id' => $image->id,
            'auto_thumbnail' => false, 'auto_seo' => false,
        ])->assertOk();
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Nguồn cho tác vụ chọn đầu ra rõ ràng.', 'requested_outputs' => ['content', 'seo', 'thumbnail'], 'thumbnail_mode' => 'generate',
        ])->assertStatus(202)->json('data.job_id');
        $explicit = AiImport::findOrFail($id);
        $this->assertTrue($explicit->input_json['generate_thumbnail']);
        $this->assertTrue($explicit->input_json['generate_seo']);
        $this->assertSame($image->id, $explicit->input_json['image_connection']['model_id']);
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Nguồn cho tác vụ tái tạo từng mục.', 'thumbnail_mode' => 'generate',
        ])->assertStatus(202)->json('data.job_id');
        $parent = AiImport::findOrFail($id);
        $this->assertNull($parent->input_json['image_connection']);
        $parent->update(['status' => 'ready', 'result_json' => ['draft' => [
            'title' => 'Tiêu đề giữ nguyên', 'content_html' => '<p>Nội dung giữ nguyên.</p>', 'content' => '<p>Nội dung giữ nguyên.</p>',
            'thumbnail' => ['media_asset_id' => null, 'source_url' => null, 'alt_text' => ''],
        ]]]);
        $childId = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$id.'/regenerate', ['fields' => ['seo', 'thumbnail'], 'refresh_source' => true])->assertStatus(202)->json('data.job_id');
        $child = AiImport::findOrFail($childId);
        $this->assertTrue($child->input_json['generate_seo']);
        $this->assertTrue($child->input_json['generate_thumbnail']);
        $this->assertSame($image->id, $child->input_json['image_connection']['model_id']);
        Http::fake(['https://gateway.example/v1/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
            'title' => 'Không thay tiêu đề', 'content_html' => '<p>Không thay nội dung.</p>', 'seo_title' => 'SEO mới',
        ])]]]])]);
        (new ProcessAiImportJob($childId))->handle(app(ArticleImportService::class));
        $draft = AiImport::findOrFail($childId)->result_json['draft'];
        $this->assertSame('SEO mới', $draft['seo_title']);
        $this->assertSame('Tiêu đề giữ nguyên', $draft['title']);
        $this->assertSame('<p>Nội dung giữ nguyên.</p>', $draft['content_html']);
        Queue::assertPushed(ProcessAiImageGenerationJob::class, 1);
        $this->assertSame($childId, AiImport::where('operation', 'image')->firstOrFail()->parent_id);
        $this->assertFalse($parent->fresh()->input_json['generate_seo']);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['auto_seo' => true, 'auto_thumbnail' => true])->assertOk();
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Nguồn chỉ cần nội dung.', 'requested_outputs' => ['content'],
        ])->assertStatus(202)->json('data.job_id');
        $run = AiImport::findOrFail($id);
        $this->assertFalse($run->input_json['generate_seo']);
        $this->assertFalse($run->input_json['generate_thumbnail']);
        $other = $provider->models()->create(['remote_model_id' => 'other-text', 'label' => 'Other', 'capabilities' => ['text_generation']]);
        $childId = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$parent->id.'/regenerate', [
            'fields' => ['content'], 'model_id' => $other->id,
            'refresh_source' => true,
        ])->assertStatus(202)->json('data.job_id');
        $child = AiImport::findOrFail($childId);
        $this->assertFalse($child->input_json['generate_seo']);
        $this->assertFalse($child->input_json['ai_connection']['generate_seo']);
        $explicit->update(['status' => 'ready', 'result_json' => $parent->result_json]);
        $childId = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$explicit->id.'/regenerate', [
            'fields' => ['seo'],
            'refresh_source' => true,
        ])->assertStatus(202)->json('data.job_id');
        (new ProcessAiImportJob($childId))->handle(app(ArticleImportService::class));
        $this->assertSame('ready', AiImport::findOrFail($childId)->status);
        // A preserved image snapshot must not create an image for an SEO-only regeneration.
        Queue::assertPushed(ProcessAiImageGenerationJob::class, 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_explicit_thumbnail_regeneration_checks_upload_permission_before_resolving_optional_image.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_explicit_thumbnail_regeneration_checks_upload_permission_before_resolving_optional_image(): void
    {
        $token = $this->token();
        $provider = $this->connection();
        $text = $provider->models()->create(['remote_model_id' => 'text', 'label' => 'Text', 'capabilities' => ['text_generation']]);
        $image = $provider->models()->create(['remote_model_id' => 'image', 'label' => 'Image', 'capabilities' => ['image_generation']]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', [
            'default_text_model_id' => $text->id, 'default_image_model_id' => $image->id, 'auto_thumbnail' => false,
        ])->assertOk();
        $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Nguồn cho tác vụ tạo ảnh cần quyền tải lên.', 'thumbnail_mode' => 'generate',
        ])->assertStatus(202)->json('data.job_id');
        AiImport::findOrFail($id)->update(['status' => 'ready', 'result_json' => ['draft' => [
            'title' => 'Title', 'content_html' => '<p>Content</p>', 'thumbnail' => ['source_url' => null],
        ]]]);
        User::firstOrFail()->revokePermissionTo('media.upload');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$id.'/regenerate', [
            'fields' => ['thumbnail'],
            'refresh_source' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('ai_imports', 1);
        Queue::assertNotPushed(ProcessAiImageGenerationJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_content_defaults_are_available_to_authored_runs_but_settings_remain_permission_protected.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_content_defaults_are_available_to_authored_runs_but_settings_remain_permission_protected(): void
    {
        $token = $this->token(['posts.manage']);
        $this->withToken($token)->getJson('/api/admin/ai-agent/capabilities/post')->assertOk()->assertJsonPath('data.content_defaults.generate_seo', true);
        $this->withToken($token)->getJson('/api/admin/settings/ai')->assertForbidden();
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['auto_seo' => false])->assertForbidden();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_environment_provider_adapters_also_use_saved_content_tuning.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_environment_provider_adapters_also_use_saved_content_tuning(): void
    {
        $token = $this->token();
        config([
            'ai.providers.connections.openai.enabled' => true, 'ai.providers.connections.openai.model' => 'env-text',
            'ai.providers.connections.gemini.enabled' => true, 'ai.providers.connections.gemini.model' => 'env-text',
            'ai.providers.connections.http-json.enabled' => true, 'ai.providers.connections.http-json.model' => 'env-text',
            'ai.providers.connections.openai.key' => 'offline-key', 'ai.providers.connections.openai.endpoint' => 'https://openai.example/v1/chat/completions',
            'ai.providers.connections.gemini.key' => 'offline-key', 'ai.providers.connections.gemini.endpoint' => 'https://gemini.example/v1beta',
            'ai.providers.connections.http-json.key' => 'offline-key', 'ai.providers.connections.http-json.endpoint' => 'https://custom.example/generate',
        ]);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', [
            'default_temperature' => 0.9, 'min_word_count' => 350, 'default_system_prompt' => 'Dùng các câu ngắn.',
        ])->assertOk();
        $output = ['title' => 'Mới', 'content_html' => '<p>Nội dung.</p>'];
        Http::fake([
            'https://openai.example/v1/chat/completions' => fn ($request) => Http::response(['choices' => [[
                'finish_reason' => 'stop', 'message' => ['content' => json_encode(ArticlePipelineFixture::httpOutput($request, $output))],
            ]]]),
            'https://gemini.example/v1beta/*' => fn ($request) => Http::response(['candidates' => [[
                'finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(ArticlePipelineFixture::httpOutput($request, $output))]]],
            ]]]),
            'https://custom.example/generate' => fn ($request) => Http::response(ArticlePipelineFixture::httpOutput($request, $output)),
        ]);
        foreach (['openai', 'gemini', 'http-json'] as $provider) {
            $id = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
                'provider' => $provider, 'model' => 'env-text', 'text' => 'Nguồn cho provider '.$provider,
                'writing_brief' => ['length' => 'Khoảng 350 từ, tránh kéo dài hoặc lặp ý'],
            ])->assertStatus(202)->json('data.job_id');
            (new ProcessAiImportJob($id))->handle(app(ArticleImportService::class));
            $this->assertSame('ready', AiImport::findOrFail($id)->status);
        }
        Http::assertSentCount(9);
        foreach (Http::recorded() as [$request]) {
            $context = ArticlePipelineFixture::httpContext($request)['input'];
            $this->assertSame('Dùng các câu ngắn.', $context['brief']['website_instructions']);
            $this->assertSame('Khoảng 350 từ, tránh kéo dài hoặc lặp ý', $context['brief']['length']);
            $this->assertSame(0.9, $request['temperature'] ?? $request['generationConfig']['temperature']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo fixture dùng riêng trong ca kiểm thử.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    private function token(array $permissions = ['ai_settings.manage', 'posts.manage', 'media.upload']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-content-settings-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo fixture dùng riêng trong ca kiểm thử.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    private function connection(): AiProvider
    {
        return AiProvider::create([
            'key' => 'test-gateway', 'name' => 'Test gateway', 'driver' => 'openai-compatible',
            'kind' => 'gateway', 'base_url' => 'https://gateway.example/v1',
            'api_key' => 'offline-key', 'is_active' => true, 'discovery_mode' => 'manual',
        ]);
    }
}
