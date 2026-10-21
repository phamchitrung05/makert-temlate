<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVisualStyleJob;
use App\Models\AiImageProfile;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\VisualStyleShot;
use App\Services\Ai\VisualStyle\VisualStyleAnalyzerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Feature test cho API Visual Style Engine (VSE-1/2/3).
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - token()/model()/upload(): helper tạo actor/provider/upload analyze.
 * - dna(): fixture DNA hợp lệ theo VisualStyleDnaDefinition.
 * - test_analyze_queue_process_and_activate: upload → job → provider → DNA
 *   analyzed → activate; suggested_shots tạo VisualStyleShot is_active=false.
 * - test_manual_store_update_dna_version_increment: tạo thủ công, updateDna
 *   version++ và conflict expected_version bị reject.
 * - test_activate_guard_requires_dna: style chưa analyzed/dna rỗng không activate.
 * - test_shots_crud_and_reorder: CRUD + reorder nested theo style.
 * - test_duplicate_copies_dna_and_shots: duplicate sang bản draft.
 * - test_permission_and_ownership_denied: thiếu quyền/khác owner bị chặn.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : HTTP request admin có Sanctum token + ai_settings.manage.
 * - OUTPUT: JSON BaseResponse envelope; DB/storage/queue fake.
 * =====================================================================
 */
final class VisualStyleApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    private const URL = '/api/admin/ai/visual-styles';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database']);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    private function token(array $permissions = ['ai_settings.manage']): string
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor->createToken('visual-style-test', ['admin'])->plainTextToken;
    }

    private function model(string $driver = 'openai-compatible', array $capabilities = ['text_generation', 'vision', 'structured_output']): int
    {
        $provider = AiProvider::create([
            'key' => 'vision-'.AiProvider::count(), 'name' => 'Offline Vision', 'driver' => $driver,
            'kind' => 'gateway', 'base_url' => 'https://gateway.example/v1', 'api_key' => 'offline-secret', 'is_active' => true,
        ]);

        return $provider->models()->create(['remote_model_id' => 'vision-model', 'label' => 'Offline Vision', 'capabilities' => $capabilities])->id;
    }

    private function dna(): array
    {
        return [
            'summary' => 'Studio sáng dịu.',
            'composition' => [
                'framing' => 'medium', 'subject_position' => 'center', 'subject_scale' => 0.6,
                'orientation' => 'square', 'visual_balance' => 'symmetric', 'negative_space' => 'moderate',
                'depth_layers' => ['subject', 'background'],
            ],
            'camera' => [
                'shot_type' => 'product_single', 'angle' => 'eye_level', 'lens_look' => 'standard',
                'depth_of_field' => 'moderate', 'focus' => 'sharp_all', 'perspective' => 'frontal',
            ],
            'lighting' => [
                'type' => 'studio_softbox', 'direction' => 'front_side', 'quality' => 'soft',
                'intensity' => 'medium', 'temperature' => 'neutral', 'shadows' => 'soft', 'highlights' => 'controlled',
            ],
            'environment' => [
                'type' => 'studio_gradient', 'background_style' => 'gradient',
                'background_blur' => 'none', 'environment_adaptation' => true,
            ],
            'color_mood' => [
                'palette' => ['#ffffff'], 'saturation' => 'natural', 'contrast' => 'moderate', 'mood' => ['clean'],
            ],
        ];
    }

    public function test_analyze_queue_process_activate_and_suggested_shots(): void
    {
        $token = $this->token();
        $modelId = $this->model();
        $id = $this->withToken($token)->postJson(self::URL.'/analyze', [
            'name' => 'Soft Studio', 'category' => 'product', 'model_id' => $modelId,
            'image' => UploadedFile::fake()->image('ref.png', 24, 24),
        ])->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(AnalyzeVisualStyleJob::class, fn ($job) => $job->profileId === $id);
        Http::assertNothingSent();

        $output = array_merge($this->dna(), [
            'suggested_shots' => [
                ['code' => 'hero_front', 'name' => 'Hero Front', 'camera_override' => ['angle' => 'eye_level'], 'suggested_aspect_ratio' => '1:1'],
                ['code' => 'detail_macro', 'name' => 'Detail Macro', 'camera_override' => ['shot_type' => 'macro', 'lens_look' => 'macro']],
            ],
        ]);
        Http::fake(['gateway.example/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($output)]]]])]);
        app(VisualStyleAnalyzerService::class)->process($id);

        $profile = AiImageProfile::findOrFail($id);
        $this->assertSame('analyzed', $profile->status);
        $this->assertSame('medium', $profile->dna_json['composition']['framing']);
        $this->assertSame('1.0', $profile->schema_version);
        $shots = VisualStyleShot::where('visual_style_id', $id)->orderBy('sort_order')->get();
        $this->assertCount(2, $shots);
        $this->assertFalse($shots[0]->is_active);
        $this->assertSame('hero_front', $shots[0]->code);

        $this->withToken($token)->postJson(self::URL.'/'.$id.'/activate')->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.is_enabled', true);
        $this->withToken($token)->getJson(self::URL.'/'.$id)->assertOk()
            ->assertJsonPath('data.dna_json.camera.angle', 'eye_level')
            ->assertJsonCount(2, 'data.shots')
            ->assertJsonMissingPath('data.reference_path')
            ->assertJsonMissingPath('data.connection_snapshot_json');
    }

    public function test_manual_store_update_dna_version_increment_and_conflict(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson(self::URL, ['name' => 'Manual Style', 'category' => 'lifestyle'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->withToken($token)->putJson(self::URL.'/'.$id.'/dna', ['dna' => $this->dna(), 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.dna_json.composition.framing', 'medium');

        // Conflict optimistic lock — version đã là 2.
        $this->withToken($token)->putJson(self::URL.'/'.$id.'/dna', ['dna' => $this->dna(), 'expected_version' => 1])
            ->assertUnprocessable();

        // DNA sai enum bị reject ngay từ service.
        $bad = $this->dna();
        $bad['camera']['angle'] = 'eye level';
        $this->withToken($token)->putJson(self::URL.'/'.$id.'/dna', ['dna' => $bad, 'expected_version' => 2])
            ->assertUnprocessable();

        // Activate manual style có DNA hợp lệ.
        $this->withToken($token)->postJson(self::URL.'/'.$id.'/activate')->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_activate_guard_rejects_style_without_dna(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson(self::URL, ['name' => 'No DNA'])
            ->assertCreated()->json('data.id');
        $this->withToken($token)->postJson(self::URL.'/'.$id.'/activate')->assertUnprocessable();
    }

    public function test_shots_crud_and_reorder(): void
    {
        $token = $this->token();
        $styleId = $this->withToken($token)->postJson(self::URL, ['name' => 'Shots Style'])
            ->assertCreated()->json('data.id');

        $a = $this->withToken($token)->postJson(self::URL.'/'.$styleId.'/shots', [
            'code' => 'hero', 'name' => 'Hero', 'camera_override' => ['angle' => 'low_angle'],
        ])->assertCreated()->json('data.id');
        $b = $this->withToken($token)->postJson(self::URL.'/'.$styleId.'/shots', [
            'code' => 'flat', 'name' => 'Flat Lay', 'suggested_aspect_ratio' => '1:1',
        ])->assertCreated()->json('data.id');

        // Code trùng bị reject.
        $this->withToken($token)->postJson(self::URL.'/'.$styleId.'/shots', ['code' => 'hero', 'name' => 'Dup'])
            ->assertUnprocessable();
        // Override enum sai bị reject.
        $this->withToken($token)->postJson(self::URL.'/'.$styleId.'/shots', [
            'code' => 'bad', 'name' => 'Bad', 'camera_override' => ['angle' => 'sideways'],
        ])->assertUnprocessable();

        $this->withToken($token)->putJson(self::URL.'/'.$styleId.'/shots/'.$b, [
            'name' => 'Flat Lay Updated', 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Flat Lay Updated')->assertJsonPath('data.is_active', false);

        // Reorder đảo thứ tự.
        $this->withToken($token)->postJson(self::URL.'/'.$styleId.'/shots/reorder', ['ordered_ids' => [$b, $a]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $b)->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.1.id', $a)->assertJsonPath('data.1.sort_order', 2);
        // Reorder thiếu id bị reject.
        $this->withToken($token)->postJson(self::URL.'/'.$styleId.'/shots/reorder', ['ordered_ids' => [$a]])
            ->assertUnprocessable();

        $this->withToken($token)->deleteJson(self::URL.'/'.$styleId.'/shots/'.$a)->assertOk();
        $this->withToken($token)->getJson(self::URL.'/'.$styleId.'/shots')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_duplicate_copies_dna_and_shots_as_draft(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson(self::URL, ['name' => 'Source Style', 'dna' => $this->dna()])
            ->assertCreated()->json('data.id');
        $this->withToken($token)->postJson(self::URL.'/'.$id.'/activate')->assertOk();
        $this->withToken($token)->postJson(self::URL.'/'.$id.'/shots', ['code' => 'hero', 'name' => 'Hero'])->assertCreated();

        $copy = $this->withToken($token)->postJson(self::URL.'/'.$id.'/duplicate')
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.shots_count', 1)
            ->json('data');
        $this->assertSame('medium', $copy['dna_json']['composition']['framing']);
    }

    public function test_permission_and_ownership_denied(): void
    {
        $owner = $this->token();
        $id = $this->withToken($owner)->postJson(self::URL, ['name' => 'Private Style'])->assertCreated()->json('data.id');

        $other = $this->token();
        $this->withToken($other)->getJson(self::URL.'/'.$id)->assertForbidden();
        $this->withToken($other)->putJson(self::URL.'/'.$id, ['name' => 'Hack'])->assertForbidden();
        $this->withToken($other)->postJson(self::URL.'/'.$id.'/activate')->assertForbidden();
        $this->withToken($other)->deleteJson(self::URL.'/'.$id)->assertForbidden();

        $noPermission = $this->token(['posts.manage']);
        $this->withToken($noPermission)->getJson(self::URL)->assertForbidden();
        $this->withToken($noPermission)->postJson(self::URL, ['name' => 'X'])->assertForbidden();
    }
}
