<?php

namespace Tests\Unit;

use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Registries\TargetRegistry;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Test registry AI và allowlist độc lập với provider thật.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: các test resolve/reject prompt, target, provider
 * và parse config boolean.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : cấu hình target/provider/prompt/schema.
 * - OUTPUT: registry an toàn, đúng version và không lộ secret.
 * - SIDE EFFECT: không gọi mạng hoặc ghi database nghiệp vụ.
 * - EXCEPTION/TRANSACTION: assertion bắt exception allowlist; không mở transaction.
 * =====================================================================
 */
class AiRegistriesTest extends TestCase
{
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

    /** Target chưa triển khai không được public như capability đang hoạt động. */
    public function test_rejects_disabled_target(): void
    {
        $this->assertArrayHasKey('post', app(TargetRegistry::class)->all());
        $this->assertArrayNotHasKey('sound', app(TargetRegistry::class)->all());
        $this->expectException(InvalidArgumentException::class);

        app(TargetRegistry::class)->get('sound');
    }

    /** Provider public metadata không được chứa key hoặc endpoint cấu hình. */
    public function test_provider_boundary_is_bound_and_options_are_redacted(): void
    {
        config(['ai-agent.providers.http-json.key' => 'secret-token']);

        $this->assertInstanceOf(AiProviderContract::class, app(AiProviderContract::class));
        $options = app(ProviderRegistry::class)->publicOptions();
        $this->assertNotEmpty($options);
        $this->assertArrayNotHasKey('key_secret', $options[0]);
        $this->assertStringNotContainsString('secret-token', json_encode($options));
    }

    /** Chuỗi `.env` false phải thật sự tắt provider, không bị ép thành true. */
    public function test_provider_boolean_configuration_respects_false_string(): void
    {
        config(['ai-agent.providers.http-json.enabled' => filter_var('false', FILTER_VALIDATE_BOOLEAN)]);

        $options = app(ProviderRegistry::class)->publicOptions();

        $this->assertNotContains('http-json', array_column($options, 'key'));
    }
}
