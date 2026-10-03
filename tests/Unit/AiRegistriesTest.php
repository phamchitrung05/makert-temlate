<?php

namespace Tests\Unit;

use App\Models\AiProvider;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\DeterministicAiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Registries\TargetRegistry;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Test registry AI và allowlist độc lập với provider thật.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: các test resolve/select/reject prompt, target,
 * provider và parse config boolean.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : cấu hình target/provider/prompt/schema.
 * - OUTPUT: registry an toàn, đúng version và không lộ secret.
 * - SIDE EFFECT: dùng database SQLite cô lập, không gọi mạng hoặc ghi dữ liệu thật.
 * - EXCEPTION/TRANSACTION: assertion bắt exception allowlist; không mở transaction.
 * =====================================================================
 */
class AiRegistriesTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Prompt key chứa dấu chấm phải được coi là key, không phải config path. */
    public function test_resolves_versioned_prompt_and_schema(): void
    {
        $prompt = app(PromptRegistry::class)->get('post.create.from_url', 'post', 'create');

        $this->assertSame('1.0', $prompt['version']);
        $this->assertSame('post.content.v1', $prompt['schema']);
        $this->assertContains('content_html', app(SchemaRegistry::class)->get($prompt['schema'])['fields']);
    }

    /** Không cho target khác mượn prompt Post qua request giả mạo. */
    public function test_rejects_prompt_for_another_target(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PromptRegistry::class)->get('post.create.from_url', 'sound', 'create');
    }

    /** Manual prompt luôn thắng rule; auto text chọn đúng prompt có rule nguồn. */
    public function test_select_prefers_manual_then_source_rule(): void
    {
        $registry = app(PromptRegistry::class);

        $manual = $registry->select('post.create.from_url', 'post', 'create', ['source_type' => 'text']);
        $text = $registry->select(null, 'post', 'create', ['source_type' => 'text']);
        $url = $registry->select(null, 'post', 'create', ['source_type' => 'url']);

        $this->assertSame('post.create.from_url', $manual['key']);
        $this->assertSame('manual', $manual['selection']);
        $this->assertSame('post.create.from_text', $text['key']);
        $this->assertSame('rule', $text['selection']);
        $this->assertSame('post.create.from_url', $url['key']);
        $this->assertSame('rule', $url['selection']);
    }

    /** Target chưa triển khai không được public như capability đang hoạt động. */
    public function test_rejects_disabled_target(): void
    {
        config(['ai-agent.targets.sound.enabled' => false]);
        $this->assertArrayHasKey('post', app(TargetRegistry::class)->all());
        $this->assertArrayNotHasKey('sound', app(TargetRegistry::class)->all());
        $this->expectException(InvalidArgumentException::class);

        app(TargetRegistry::class)->get('sound');
    }

    /** Provider public metadata không được chứa key hoặc endpoint cấu hình. */
    public function test_provider_boundary_is_bound_and_options_are_redacted(): void
    {
        config([
            'ai-providers.connections.http-json.enabled' => true,
            'ai-providers.connections.http-json.key' => 'secret-token',
            'ai-providers.connections.http-json.endpoint' => 'https://private-provider.example/generate',
        ]);

        $this->assertInstanceOf(AiProviderContract::class, app(AiProviderContract::class));
        $options = app(ProviderRegistry::class)->publicOptions();
        $this->assertNotEmpty($options);
        $this->assertArrayNotHasKey('key_secret', $options[0]);
        $this->assertStringNotContainsString('secret-token', json_encode($options));
        $this->assertStringNotContainsString('private-provider.example', json_encode($options));
        foreach ($options as $provider) {
            $this->assertArrayNotHasKey('key_secret', $provider);
            $this->assertArrayNotHasKey('api_key', $provider);
            $this->assertArrayNotHasKey('endpoint', $provider);
            $this->assertArrayNotHasKey('record', $provider);
            $this->assertArrayNotHasKey('adapter', $provider);
        }
    }

    /** Chuỗi `.env` false phải thật sự tắt provider, không bị ép thành true. */
    public function test_provider_boolean_configuration_respects_false_string(): void
    {
        config(['ai-providers.connections.http-json.enabled' => filter_var('false', FILTER_VALIDATE_BOOLEAN)]);

        $options = app(ProviderRegistry::class)->publicOptions();

        $this->assertNotContains('http-json', array_column($options, 'key'));
    }

    /** Target/output definitions, connection metadata and runtime limits have separate owners. */
    public function test_ai_configuration_keeps_provider_settings_out_of_agent_and_import(): void
    {
        $agent = config('ai-agent');
        $runtime = config('ai-import');

        $this->assertArrayNotHasKey('providers', $agent);
        foreach (['provider', 'endpoint', 'key', 'model', 'openai', 'gemini'] as $providerKey) {
            $this->assertArrayNotHasKey($providerKey, $runtime);
        }
        $this->assertArrayHasKey('job_timeout', $runtime);
        $this->assertSame(
            ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail'],
            $agent['targets']['post']['outputs'],
        );
        foreach ($agent['targets']['post']['outputs'] as $output) {
            $this->assertArrayHasKey($output, $agent['output_definitions']);
            $this->assertNotEmpty($agent['output_definitions'][$output]['label']);
        }
        $this->assertSame(OpenAiProvider::class, config('ai-providers.presets.openai.adapter'));
        $this->assertSame(DeterministicAiProvider::class, config('ai-providers.internal.deterministic.adapter'));
    }

    /** Environment connections use shared driver metadata rather than a second adapter list. */
    public function test_environment_provider_resolves_shared_driver_metadata_and_adapter(): void
    {
        config([
            'ai-providers.connections.test-connection' => [
                'enabled' => true, 'driver' => 'deterministic', 'model' => 'custom-model',
            ],
            'ai-providers.internal.deterministic.label' => 'Configured extraction',
        ]);
        $registry = app(ProviderRegistry::class);
        $metadata = $registry->get('test-connection');

        $this->assertSame('Configured extraction', $metadata['label']);
        $this->assertSame(['custom-model'], $metadata['models']);
        $this->assertInstanceOf(DeterministicAiProvider::class, $registry->resolve('test-connection'));
    }

    /** A catalog record wins over an environment connection with the same public key. */
    public function test_database_provider_takes_priority_and_resolves_the_configured_adapter(): void
    {
        config([
            'ai-providers.connections.openai.enabled' => true,
            'ai-providers.connections.openai.model' => 'environment-model',
            'ai-providers.presets.openai.adapter' => DeterministicAiProvider::class,
        ]);
        $record = AiProvider::create([
            'key' => 'openai', 'name' => 'Database connection', 'kind' => 'official',
            'driver' => 'openai', 'base_url' => 'https://database-provider.example/v1',
            'api_key' => 'database-secret', 'is_active' => true, 'discovery_mode' => 'manual',
        ]);
        $record->models()->create([
            'remote_model_id' => 'database-model', 'label' => 'Database model',
            'capabilities' => ['text_generation'],
        ]);
        $registry = app(ProviderRegistry::class);
        $metadata = $registry->get('openai');
        $options = collect($registry->publicOptions())->where('key', 'openai')->values();

        $this->assertSame($record->id, $metadata['record']->id);
        $this->assertSame(['database-model'], $metadata['models']);
        $this->assertInstanceOf(DeterministicAiProvider::class, $registry->resolve('openai'));
        $this->assertCount(1, $options);
        $this->assertSame('Database connection', $options[0]['label']);
        $this->assertSame(['database-model'], $options[0]['models']);
        $this->assertStringNotContainsString('database-secret', json_encode($options));
        $this->assertStringNotContainsString('database-provider.example', json_encode($options));

        $record->update(['is_active' => false]);
        $this->assertNotContains('openai', array_column($registry->publicOptions(), 'key'));
        $this->expectException(InvalidArgumentException::class);
        $registry->get('openai');
    }
}
