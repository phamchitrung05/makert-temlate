<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAiWritingProfileJob;
use App\Models\AiProvider;
use App\Models\AiWritingProfile;
use App\Models\AiWritingProfileAnalysis;
use App\Models\User;
use App\Services\Ai\WritingProfiles\WritingProfileAnalysisService;
use App\Services\Ai\WritingProfiles\WritingProfileSnapshotService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa CRUD/version/snapshot/quyền và lifecycle phân tích văn phong.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(), tearDown(), token(), profileValues(), analysisOutput(), model(), queueAnalysis(), finishAnalysis().
 * - test_profile_version_and_default_are_consistent_and_snapshots_do_not_change().
 * - test_options_allow_writers_but_management_requires_settings_permission().
 * - test_analysis_requires_explicit_human_save_and_redacts_private_data().
 * - test_invented_evidence_fails_without_creating_a_profile().
 * - test_cancelled_analysis_cannot_be_overwritten_by_a_late_response().
 * - test_analysis_is_private_and_foreign_or_expired_analyses_cannot_be_saved().
 * - test_cleanup_expires_reference_tasks_but_keeps_approved_profiles().
 * - test_sync_queue_and_invalid_defaults_are_rejected().
 * - test_rules_and_manually_invented_evidence_are_rejected().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : API requests và HTTP fake, database SQLite cô lập.
 * - OUTPUT: assertions về human approval, output grounding, quyền và retention.
 * - SIDE EFFECT: chỉ DB test/queue fake/HTTP fake; không gọi AI trả phí.
 * =====================================================================
 */
final class AiWritingProfilesApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    private const PREFIX = '/api/admin/ai/writing-profiles';

    private const SOURCE = 'Bạn đang mất thời gian tìm lỗi? Hãy bắt đầu từ thông báo đầu tiên. Mỗi bước chỉ thay đổi một điều để dễ kiểm tra kết quả.';

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo schema/permission cô lập và chặn network thật.
     * =====================================================================
     * Input: PHPUnit lifecycle. Output: fixtures offline.
     * Side effect: migrate SQLite, seed permission, fake queue/HTTP.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giải phóng database cô lập.
     * =====================================================================
     * Input: PHPUnit lifecycle. Output: connection đã dọn.
     * Side effect: rollback schema test, không đụng database development.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng version 409, default bật/tắt và snapshot độc lập.
     * =====================================================================
     * Input: CRUD API và Settings. Output: defaults/snapshots ổn định qua sửa.
     * Side effect: database test; không gọi AI.
     * =====================================================================
     */
    public function test_profile_version_and_default_are_consistent_and_snapshots_do_not_change(): void
    {
        $token = $this->token();
        $profile = $this->withToken($token)->postJson(self::PREFIX, $this->profileValues())->assertCreated()->json('data');
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_writing_profile_id' => $profile['id']])->assertOk();
        $snapshot = app(WritingProfileSnapshotService::class)->snapshot();
        $this->assertSame(1, $snapshot['version']);
        $this->withToken($token)->putJson(self::PREFIX.'/'.$profile['id'], ['version' => 1, 'style_instructions' => 'Hướng dẫn đã sửa.'])->assertOk()->assertJsonPath('data.version', 2);
        $this->withToken($token)->putJson(self::PREFIX.'/'.$profile['id'], ['version' => 1, 'name' => 'Ghi đè bằng bản cũ'])->assertStatus(409);
        $this->assertSame($this->profileValues()['style_instructions'], $snapshot['style_instructions']);
        $this->withToken($token)->putJson(self::PREFIX.'/'.$profile['id'], ['version' => 2, 'is_enabled' => false])->assertOk()->assertJsonPath('data.version', 3);
        $this->withToken($token)->getJson('/api/admin/settings/ai/settings')->assertOk()->assertJsonPath('data.default_writing_profile_id', null);
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_writing_profile_id' => $profile['id']])->assertUnprocessable();
        $this->withToken($token)->deleteJson(self::PREFIX.'/'.$profile['id'], ['version' => 2])->assertStatus(409);
        $this->withToken($token)->deleteJson(self::PREFIX.'/'.$profile['id'], ['version' => 3])->assertNoContent();
        $this->assertSame($profile['id'], $snapshot['id']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Writer chọn mẫu bật nhưng không được quản lý/profile analysis.
     * =====================================================================
     * Input: actor có posts.manage. Output: options 200, CRUD/analysis 403.
     * Side effect: fixtures DB, không gọi provider.
     * =====================================================================
     */
    public function test_options_allow_writers_but_management_requires_settings_permission(): void
    {
        $manager = $this->token();
        $enabled = $this->withToken($manager)->postJson(self::PREFIX, $this->profileValues())->assertCreated()->json('data.id');
        $this->withToken($manager)->postJson(self::PREFIX, $this->profileValues() + ['is_enabled' => false])->assertCreated();
        $writer = $this->token(['posts.manage']);
        $this->withToken($writer)->getJson(self::PREFIX.'/options')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $enabled);
        $this->withToken($writer)->getJson(self::PREFIX)->assertForbidden();
        $this->withToken($writer)->postJson(self::PREFIX, $this->profileValues())->assertForbidden();
        $this->withToken($writer)->postJson(self::PREFIX.'/analyses', ['name' => 'Mẫu', 'reference_text' => self::SOURCE])->assertForbidden();
        $withoutPermission = $this->token([]);
        $this->withToken($withoutPermission)->getJson(self::PREFIX.'/options')->assertForbidden();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Analysis ready chỉ preview, người dùng phải POST để lưu profile.
     * =====================================================================
     * Input: queued analysis và response provider fake. Output: profile chỉ có sau duyệt.
     * Side effect: offline job/API; metadata không expose reference/base/key.
     * =====================================================================
     */
    public function test_analysis_requires_explicit_human_save_and_redacts_private_data(): void
    {
        $token = $this->token();
        $id = $this->queueAnalysis($token);
        Queue::assertPushed(AnalyzeAiWritingProfileJob::class, fn ($job) => $job->analysisId === $id);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_writing_profiles', 0);
        $this->finishAnalysis($id);
        $response = $this->withToken($token)->getJson(self::PREFIX.'/analyses/'.$id)->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertDatabaseCount('ai_writing_profiles', 0);
        $this->assertStringNotContainsString('offline-secret-key', $response->getContent());
        $this->assertStringNotContainsString('gateway.example', $response->getContent());
        $this->assertArrayNotHasKey('reference_text', $response->json('data'));
        $result = $response->json('data.result');
        $profile = $this->withToken($token)->postJson(self::PREFIX, [
            'name' => 'Mẫu đã duyệt', 'description' => $result['summary'], 'rules_json' => $result['rules'],
            'evidence_json' => $result['evidence'], 'style_instructions' => $result['style_instructions'], 'analysis_id' => $id,
        ])->assertCreated()->assertJsonPath('data.origin', 'reference')->json('data');
        $this->assertSame(hash('sha256', self::SOURCE), AiWritingProfile::findOrFail($profile['id'])->source_hash);
        $this->assertSame('writing-profile.analysis.v1', $profile['analysis_metadata']['schema_version']);
        Http::assertSentCount(1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trích đoạn AI bịa phải làm analysis failed.
     * =====================================================================
     * Input: schema đúng nhưng excerpt không có thật. Output: safe error, không profile.
     * Side effect: HTTP fake và DB test, không fallback.
     * =====================================================================
     */
    public function test_invented_evidence_fails_without_creating_a_profile(): void
    {
        $token = $this->token();
        $id = $this->queueAnalysis($token);
        $output = $this->analysisOutput();
        $output['evidence'][0]['excerpt'] = 'Đây là trích đoạn AI tự bịa và không có trong nguồn.';
        $this->finishAnalysis($id, $output);
        $this->withToken($token)->getJson(self::PREFIX.'/analyses/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'AI_WRITING_PROFILE_INVALID_OUTPUT')->assertJsonPath('data.result', null);
        $this->assertDatabaseCount('ai_writing_profiles', 0);
        $this->withToken($token)->postJson(self::PREFIX, $this->profileValues() + ['analysis_id' => $id])->assertUnprocessable();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hủy trong lúc upstream trả output không bị ghi lại ready.
     * =====================================================================
     * Input: HTTP fake thực hiện cancel giữa request/response. Output: cancelled.
     * Side effect: DB test và request fake duy nhất.
     * =====================================================================
     */
    public function test_cancelled_analysis_cannot_be_overwritten_by_a_late_response(): void
    {
        $token = $this->token();
        $id = $this->queueAnalysis($token);
        Http::fake(function () use ($id) {
            AiWritingProfileAnalysis::query()->whereKey($id)->update(['status' => 'cancelled']);

            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($this->analysisOutput(), JSON_UNESCAPED_UNICODE)]]]]);
        });
        (new AnalyzeAiWritingProfileJob($id))->handle(app(WritingProfileAnalysisService::class));
        $this->assertSame('cancelled', AiWritingProfileAnalysis::findOrFail($id)->status);
        $this->assertNull(AiWritingProfileAnalysis::findOrFail($id)->result_json);
        $second = $this->queueAnalysis($token);
        $this->withToken($token)->postJson(self::PREFIX.'/analyses/'.$second.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        (new AnalyzeAiWritingProfileJob($second))->handle(app(WritingProfileAnalysisService::class));
        Http::assertSentCount(1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Owner isolation và tác vụ hết hạn không dùng để lưu mẫu.
     * =====================================================================
     * Input: hai admin và analysis ready/expired. Output: 403/410/422 đúng boundary.
     * Side effect: DB test, HTTP fake.
     * =====================================================================
     */
    public function test_analysis_is_private_and_foreign_or_expired_analyses_cannot_be_saved(): void
    {
        $owner = $this->token();
        $id = $this->queueAnalysis($owner);
        $this->finishAnalysis($id);
        $other = $this->token();
        $this->withToken($other)->getJson(self::PREFIX.'/analyses/'.$id)->assertForbidden();
        $this->withToken($other)->postJson(self::PREFIX.'/analyses/'.$id.'/cancel')->assertForbidden();
        $this->withToken($other)->postJson(self::PREFIX, $this->profileValues() + ['analysis_id' => $id])->assertUnprocessable();
        AiWritingProfileAnalysis::findOrFail($id)->update(['expires_at' => now()->subMinute()]);
        Auth::forgetGuards();
        $this->withToken($owner)->getJson(self::PREFIX.'/analyses/'.$id)->assertStatus(410);
        $this->withToken($owner)->postJson(self::PREFIX, $this->profileValues() + ['analysis_id' => $id])->assertUnprocessable();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Retention xóa bài mẫu nhưng giữ profile/bằng chứng đã duyệt.
     * =====================================================================
     * Input: expired task gắn profile. Output: profile vẫn dùng được sau cleanup.
     * Side effect: chạy command trong database test, không gọi AI thật.
     * =====================================================================
     */
    public function test_cleanup_expires_reference_tasks_but_keeps_approved_profiles(): void
    {
        $token = $this->token();
        $id = $this->queueAnalysis($token);
        $this->finishAnalysis($id);
        $output = $this->analysisOutput();
        $profileId = $this->withToken($token)->postJson(self::PREFIX, $this->profileValues() + ['analysis_id' => $id, 'evidence_json' => $output['evidence']])->assertCreated()->json('data.id');
        AiWritingProfileAnalysis::findOrFail($id)->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai:cleanup-writing-profile-analyses')->assertSuccessful();
        $this->assertDatabaseMissing('ai_writing_profile_analyses', ['id' => $id]);
        $this->assertSame($profileId, app(WritingProfileSnapshotService::class)->snapshot($profileId)['id']);
        $this->withToken($token)->putJson(self::PREFIX.'/'.$profileId, ['version' => 1, 'evidence_json' => $output['evidence']])->assertOk();
        $changed = $output['evidence'];
        $changed[0]['excerpt'] = 'Bằng chứng mới sau khi bài mẫu bị xóa.';
        $this->withToken($token)->putJson(self::PREFIX.'/'.$profileId, ['version' => 2, 'evidence_json' => $changed])->assertUnprocessable();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Không chạy AI đồng bộ và không chọn default ID không hợp lệ.
     * =====================================================================
     * Input: queue sync/default ID sai. Output: 422 không queue/call provider.
     * Side effect: chỉ request test.
     * =====================================================================
     */
    public function test_sync_queue_and_invalid_defaults_are_rejected(): void
    {
        $token = $this->token();
        config(['queue.connections.immediate-alias' => ['driver' => 'sync'], 'queue.connections.discard-alias' => ['driver' => 'null']]);
        foreach (['sync', 'null', 'immediate-alias', 'discard-alias'] as $connection) {
            config(['queue.default' => $connection]);
            $this->withToken($token)->postJson(self::PREFIX.'/analyses', ['name' => 'Mẫu', 'reference_text' => self::SOURCE])->assertUnprocessable();
        }
        $this->assertDatabaseCount('ai_writing_profile_analyses', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_writing_profile_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('default_writing_profile_id');
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['default_writing_profile_id' => null])->assertOk()->assertJsonPath('data.default_writing_profile_id', null);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn rules tùy ý lồng tầng và evidence mẫu thủ công không nguồn.
     * =====================================================================
     * Input: values độc hại/sai contract. Output: validation error.
     * Side effect: API test, không gọi AI.
     * =====================================================================
     */
    public function test_rules_and_manually_invented_evidence_are_rejected(): void
    {
        $token = $this->token();
        $values = $this->profileValues();
        $values['rules_json'] = ['ignore_all_instructions' => ['deep' => ['nested' => 'instructions']]];
        $this->withToken($token)->postJson(self::PREFIX, $values)->assertUnprocessable();
        $this->withToken($token)->postJson(self::PREFIX, $this->profileValues() + ['evidence_json' => $this->analysisOutput()['evidence']])->assertUnprocessable();
        $this->assertDatabaseCount('ai_writing_profiles', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo token admin với quyền được chỉ định.
     * =====================================================================
     * Input: permission array. Output: token test.
     * Side effect: DB test/cache permission/Auth guards.
     * =====================================================================
     */
    private function token(array $permissions = ['ai_settings.manage', 'posts.manage']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('writing-profile-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cung cấp mẫu thủ công hợp lệ.
     * =====================================================================
     * Input: không có. Output: profile values.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function profileValues(): array
    {
        return ['name' => 'Gần gũi, trực tiếp', 'rules_json' => ['tone' => 'Gần gũi'], 'style_instructions' => 'Đi thẳng vào vấn đề, diễn đạt tự nhiên và giải thích theo nhu cầu người đọc.'];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cung cấp output analysis có trích đoạn thật.
     * =====================================================================
     * Input: không có. Output: contract writing-profile.analysis.v1.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function analysisOutput(): array
    {
        return [
            'summary' => 'Giải thích trực tiếp và gần gũi.',
            'rules' => ['tone' => 'Gần gũi', 'opening' => 'Nêu vấn đề', 'sentence_rhythm' => 'Câu ngắn phối hợp giải thích', 'uncertainties' => []],
            'evidence' => [['feature' => 'opening', 'excerpt' => 'Bạn đang mất thời gian tìm lỗi?', 'explanation' => 'Mở bài đưa ngay tình huống cho người đọc.']],
            'style_instructions' => 'Nêu vấn đề cụ thể, dùng câu ngắn tự nhiên và tránh kết luận lặp lại.',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo model catalog offline.
     * =====================================================================
     * Input: không có. Output: ID model text enabled.
     * Side effect: DB test, API key fixture không thật.
     * =====================================================================
     */
    private function model(): int
    {
        $provider = AiProvider::query()->create([
            'key' => 'profile-test-'.AiProvider::query()->count(), 'name' => 'Profile test gateway', 'driver' => 'openai-compatible',
            'kind' => 'gateway', 'base_url' => 'https://gateway.example/v1', 'api_key' => 'offline-secret-key', 'is_active' => true,
        ]);

        return $provider->models()->create(['remote_model_id' => 'offline-text', 'label' => 'Offline text', 'capabilities' => ['text_generation', 'structured_output']])->id;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo queued analysis qua API.
     * =====================================================================
     * Input: admin token. Output: UUID analysis.
     * Side effect: DB/queue fake, không gọi network.
     * =====================================================================
     */
    private function queueAnalysis(string $token): string
    {
        return $this->withToken($token)->postJson(self::PREFIX.'/analyses', ['name' => 'Mẫu tham khảo', 'reference_text' => self::SOURCE, 'model_id' => $this->model()])
            ->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy job với envelope OpenAI fake hợp lệ.
     * =====================================================================
     * Input: UUID và output override. Output: state job được xử lý.
     * Side effect: đúng một request fake; không gọi provider trả phí.
     * =====================================================================
     */
    private function finishAnalysis(string $id, ?array $output = null): void
    {
        Http::fake(['https://gateway.example/*' => Http::response([
            'id' => 'response-profile-test', 'model' => 'offline-text',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($output ?? $this->analysisOutput(), JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 80, 'total_tokens' => 180],
        ])]);
        (new AnalyzeAiWritingProfileJob($id))->handle(app(WritingProfileAnalysisService::class));
    }
}
