<?php

namespace Tests\Feature;

use App\Models\AiImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/** Các hạng mục từ config phải được public, validate và chụp đúng vào tác vụ AI. */
class AiOutputSelectionApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-output-test', ['admin'])->plainTextToken;
    }

    private function payload(): array
    {
        return [
            'target_type' => 'post', 'provider' => 'deterministic', 'model' => 'deterministic',
            'input' => ['type' => 'text', 'text' => "Tiêu đề nguồn\n\nNội dung nguồn."],
        ];
    }

    public function test_target_and_capability_options_follow_config_labels_and_allowlist(): void
    {
        config([
            'ai-agent.targets.post.outputs' => ['excerpt', 'seo'],
            'ai-agent.output_definitions.excerpt.label' => 'Mô tả theo config',
        ]);
        $token = $this->token();
        $this->withToken($token)->getJson('/api/admin/ai-agent/targets')->assertOk()
            ->assertJsonPath('data.0.outputs', ['excerpt', 'seo'])
            ->assertJsonPath('data.0.output_options.0.value', 'excerpt')
            ->assertJsonPath('data.0.output_options.0.title', 'Mô tả theo config')
            ->assertJsonCount(2, 'data.0.output_options');
        $this->withToken($token)->getJson('/api/admin/ai-agent/capabilities/post')->assertOk()
            ->assertJsonPath('data.outputs', ['excerpt', 'seo'])
            ->assertJsonPath('data.output_options.0.title', 'Mô tả theo config')
            ->assertJsonCount(2, 'data.output_options');
    }

    public function test_selected_outputs_and_manual_title_are_saved_and_control_automatic_flags(): void
    {
        $payload = $this->payload();
        $payload['input']['title'] = 'Tiêu đề nhập tay';
        $payload['requested_outputs'] = ['excerpt', 'seo'];
        $payload['generate_thumbnail'] = true;
        $payload['generate_seo'] = false;
        $id = $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', $payload)
            ->assertAccepted()->json('data.job_id');
        $input = AiImport::findOrFail($id)->input_json;

        $this->assertSame(['excerpt', 'seo'], $input['fields']);
        $this->assertSame(['excerpt', 'seo'], $input['requested_outputs']);
        $this->assertSame('Tiêu đề nhập tay', $input['title']);
        $this->assertFalse($input['generate_thumbnail']);
        $this->assertTrue($input['generate_seo']);
        $this->assertTrue($input['ai_connection']['generate_seo']);
    }

    public function test_empty_or_unsupported_output_selections_are_rejected_for_the_target(): void
    {
        config(['ai-agent.targets.post.outputs' => ['title']]);
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', $this->payload() + [
            'requested_outputs' => ['thumbnail'],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_outputs.0');
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', $this->payload() + [
            'requested_outputs' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_outputs');
        $this->assertDatabaseCount('ai_imports', 0);
    }

    public function test_legacy_requests_without_output_selection_keep_full_generation_behavior(): void
    {
        $id = $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', $this->payload())
            ->assertAccepted()->json('data.job_id');
        $input = AiImport::findOrFail($id)->input_json;

        $this->assertArrayNotHasKey('fields', $input);
        $this->assertArrayNotHasKey('requested_outputs', $input);
    }
}
