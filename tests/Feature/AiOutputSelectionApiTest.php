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

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm output whitelist public và manual taxonomy request contract.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - token().
 * - payload().
 * - test_target_and_capability_options_follow_config_labels_and_allowlist().
 * - test_selected_outputs_and_manual_title_are_saved_and_control_automatic_flags().
 * - test_empty_or_unsupported_output_selections_are_rejected_for_the_target().
 * - test_legacy_requests_without_output_selection_keep_full_generation_behavior().
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures/requests admin, HTTP và Queue fake, database test cô lập.
 * - OUTPUT: assertions cho contract, snapshot, quyền và lỗi; không gọi AI thật.
 * - SIDE EFFECT: tạo/sửa dữ liệu trong database test; không chỉnh dữ liệu ứng dụng.
 * =====================================================================
 */
class AiOutputSelectionApiTest extends TestCase
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
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
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
     * CHỨC NĂNG: Tạo fixture dùng riêng trong ca kiểm thử.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-output-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo fixture dùng riêng trong ca kiểm thử.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    private function payload(): array
    {
        return [
            'target_type' => 'post', 'provider' => 'deterministic', 'model' => 'deterministic',
            'input' => ['type' => 'text', 'text' => "Tiêu đề nguồn\n\nNội dung nguồn."],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_target_and_capability_options_follow_config_labels_and_allowlist.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_target_and_capability_options_follow_config_labels_and_allowlist(): void
    {
        config([
            'ai.agent.targets.post.outputs' => ['excerpt', 'seo'],
            'ai.agent.output_definitions.excerpt.label' => 'Mô tả theo config',
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_selected_outputs_and_manual_title_are_saved_and_control_automatic_flags.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_empty_or_unsupported_output_selections_are_rejected_for_the_target.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_empty_or_unsupported_output_selections_are_rejected_for_the_target(): void
    {
        config(['ai.agent.targets.post.outputs' => ['title']]);
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', $this->payload() + [
            'requested_outputs' => ['thumbnail'],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_outputs.0');
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', $this->payload() + [
            'requested_outputs' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_outputs');
        $this->assertDatabaseCount('ai_imports', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm regression test_legacy_requests_without_output_selection_keep_full_generation_behavior.
     * INPUT: fixtures/request của kịch bản regression.
     * OUTPUT: assertions xác nhận contract và side effect mong đợi.

     * =====================================================================
     */
    public function test_legacy_requests_without_output_selection_keep_full_generation_behavior(): void
    {
        $id = $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', $this->payload())
            ->assertAccepted()->json('data.job_id');
        $input = AiImport::findOrFail($id)->input_json;

        $this->assertArrayNotHasKey('fields', $input);
        $this->assertArrayNotHasKey('requested_outputs', $input);
    }
}
