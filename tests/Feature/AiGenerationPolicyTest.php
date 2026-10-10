<?php

namespace Tests\Feature;

use App\Enums\AiCapability;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Settings\AiSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

final class AiGenerationPolicyTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database', 'ai.providers.default_provider' => 'deterministic']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    private function model(): AiModel
    {
        $provider = AiProvider::query()->create([
            'key' => 'policy-fixture', 'name' => 'Policy fixture', 'driver' => 'openai-compatible',
            'kind' => 'gateway', 'base_url' => 'https://gateway.example/v1', 'api_key' => 'fixture-only',
            'is_active' => true, 'discovery_mode' => 'manual',
        ]);

        return $provider->models()->create(['remote_model_id' => 'fixture-model', 'label' => 'Fixture model',
            'capabilities' => ['text_generation', 'structured_output'], 'is_enabled' => true, 'is_available' => true]);
    }

    public function test_auto_without_real_model_rejects_before_queueing(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo('posts.manage');
        $this->withToken($actor->createToken('policy', ['admin'])->plainTextToken)
            ->postJson('/api/admin/posts/ai/import', ['text' => 'Nguồn đủ rõ để tạo một bài viết.'])
            ->assertUnprocessable()->assertJsonValidationErrors('model_id');
        $this->assertDatabaseCount('ai_imports', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_explicit_deterministic_remains_an_explicit_selection(): void
    {
        $resolved = app(ModelResolver::class)->resolve(AiCapability::Text, ['provider' => 'deterministic']);
        $this->assertSame('deterministic', $resolved['provider']);
    }

    public function test_auto_uses_configured_fallback_when_default_is_unavailable(): void
    {
        $default = $this->model();
        $fallback = $default->provider->models()->create(['remote_model_id' => 'fallback-model', 'label' => 'Fallback',
            'capabilities' => ['text_generation', 'structured_output'], 'is_enabled' => true, 'is_available' => true]);
        $default->update(['is_available' => false]);
        $settings = app(AiSettings::class);
        $settings->default_text_model_id = $default->id;
        $settings->fallback_text_model_id = $fallback->id;
        $settings->default_temperature = 0;
        $settings->save();
        $resolved = app(ModelResolver::class)->resolve(AiCapability::Text);
        $this->assertSame($fallback->id, $resolved['model_id']);
        $this->assertSame(0.0, $resolved['temperature']);
        Http::assertNothingSent();
    }

    public function test_unconfigured_legacy_auto_cannot_report_deterministic_success(): void
    {
        config(['ai.providers.default_provider' => 'http-json', 'ai.providers.connections.http-json.endpoint' => '', 'ai.providers.connections.http-json.api_key' => '']);
        $this->expectException(ValidationException::class);
        app(ModelResolver::class)->resolve(AiCapability::Text);
    }

    public function test_mhtml_is_rejected_even_when_its_body_is_valid_html(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo('posts.manage');
        $this->withToken($actor->createToken('policy', ['admin'])->plainTextToken)
            ->post('/api/admin/posts/ai/import', ['html_file' => UploadedFile::fake()->createWithContent('source.mhtml', '<html><body><p>Valid source text.</p></body></html>'), 'provider' => 'deterministic'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('html_file');
        Queue::assertNothingPushed();
    }

    public function test_diagnostics_discard_raw_response_headers_keys_and_nested_secrets(): void
    {
        $metadata = AiResponseDiagnostics::sanitize([
            'response_id' => 'safe-id', 'finish_reason' => 'stop', 'usage' => ['total_tokens' => 23, 'secret' => 'fixture-secret'],
            'raw_response' => ['content' => 'Private raw text'], 'headers' => ['Authorization' => 'Bearer fixture-secret'],
            'api_key' => 'fixture-secret', 'prompt' => 'Private prompt', 'error_code' => 'AI_PROVIDER_INVALID_JSON',
        ]);
        $this->assertSame(['response_id' => 'safe-id', 'error_code' => 'AI_PROVIDER_INVALID_JSON', 'finish_reason' => 'stop', 'usage' => ['total_tokens' => 23]], $metadata);
    }
}
