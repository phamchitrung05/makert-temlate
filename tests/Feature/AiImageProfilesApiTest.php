<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAiImageProfileJob;
use App\Models\AiImageProfile;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\Images\ImageProfileService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

final class AiImageProfilesApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    private const URL = '/api/admin/ai/image-profiles';

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

    private function token(array $permissions = ['ai_settings.manage', 'posts.manage']): string
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor->createToken('image-profile-test', ['admin'])->plainTextToken;
    }

    private function model(string $driver = 'openai-compatible', array $capabilities = ['text_generation', 'vision', 'structured_output']): int
    {
        $provider = AiProvider::create([
            'key' => 'vision-'.AiProvider::count(), 'name' => 'Offline Vision', 'driver' => $driver,
            'kind' => 'gateway', 'base_url' => 'https://gateway.example/v1', 'api_key' => 'offline-secret', 'is_active' => true,
        ]);

        return $provider->models()->create(['remote_model_id' => 'vision-model', 'label' => 'Offline Vision', 'capabilities' => $capabilities])->id;
    }

    private function upload(string $token, int $model): int
    {
        return $this->withToken($token)->postJson(self::URL, [
            'name' => 'Ánh sáng dịu', 'model_id' => $model, 'image' => UploadedFile::fake()->image('reference.png', 24, 24),
        ])->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonMissingPath('data.reference_path')
            ->assertJsonMissingPath('data.connection_snapshot_json')->json('data.id');
    }

    public function test_upload_analysis_save_reuse_and_delete_with_real_multimodal_payload(): void
    {
        $token = $this->token();
        $id = $this->upload($token, $this->model());
        Queue::assertPushed(AnalyzeAiImageProfileJob::class, fn ($job) => $job->profileId === $id);
        Http::assertNothingSent();
        $profile = AiImageProfile::findOrFail($id);
        Storage::disk('local')->assertExists($profile->reference_path);
        $output = ['summary' => 'Ánh sáng dịu, bố cục tối giản.', 'prompt' => 'A softly lit photograph of {subject}, warm palette, minimal background.', 'negative_prompt' => 'watermark, text'];
        Http::fake(['gateway.example/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($output)]]]])]);
        app(ImageProfileService::class)->process($id);
        Http::assertSent(function ($request) use ($profile): bool {
            $content = $request['messages'][1]['content'];

            return $content[0]['type'] === 'text' && $content[1]['type'] === 'image_url'
                && $content[1]['image_url']['url'] === 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($profile->reference_path));
        });
        $this->withToken($token)->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.prompt', $output['prompt']);
        $this->withToken($token)->getJson(self::URL.'/options')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->putJson(self::URL.'/'.$id, ['name' => 'Reusable', 'prompt' => $output['prompt'], 'negative_prompt' => $output['negative_prompt'], 'is_enabled' => true])->assertOk();
        $this->withToken($token)->getJson(self::URL.'/options')->assertOk()->assertJsonPath('data.0.id', $id);
        $snapshot = app(ImageProfileService::class)->snapshot($id, $profile->created_by);
        $this->withToken($token)->deleteJson(self::URL.'/'.$id)->assertOk();
        Storage::disk('local')->assertMissing($profile->reference_path);
        $this->assertSame($output['prompt'], $snapshot['prompt']);
    }

    public function test_gemini_receives_inline_image_bytes_and_failed_output_is_not_enabled(): void
    {
        $id = $this->upload($this->token(), $this->model('gemini'));
        Http::fake(['gateway.example/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"summary":"Only a summary"}']]]]]])]);
        (new AnalyzeAiImageProfileJob($id))->handle(app(ImageProfileService::class));
        Http::assertSent(fn ($request) => data_get($request->data(), 'contents.0.parts.1.inlineData.mimeType') === 'image/png'
            && filled(data_get($request->data(), 'contents.0.parts.1.inlineData.data')));
        $this->assertSame('failed', AiImageProfile::findOrFail($id)->status);
        $this->assertFalse(AiImageProfile::findOrFail($id)->is_enabled);
    }

    public function test_permissions_ownership_file_and_vision_validation(): void
    {
        $owner = $this->token();
        $id = $this->upload($owner, $this->model());
        $this->withToken($owner)->deleteJson(self::URL.'/'.$id)->assertStatus(409);
        $this->withToken($owner)->postJson(self::URL, ['name' => 'No vision', 'model_id' => $this->model(capabilities: ['text_generation']), 'image' => UploadedFile::fake()->image('ref.png')])->assertUnprocessable();
        $this->withToken($owner)->postJson(self::URL, ['name' => 'Bad file', 'model_id' => 1, 'image' => UploadedFile::fake()->create('ref.svg', 10, 'image/svg+xml')])->assertUnprocessable();
        $other = $this->token();
        $this->withToken($other)->getJson(self::URL.'/'.$id)->assertForbidden();
        $this->withToken($other)->putJson(self::URL.'/'.$id, [])->assertForbidden();
        $this->withToken($other)->deleteJson(self::URL.'/'.$id)->assertForbidden();
        $writer = $this->token(['posts.manage']);
        $this->withToken($writer)->postJson(self::URL, [])->assertForbidden();
        $this->withToken($writer)->getJson(self::URL.'/options')->assertOk();
        Http::assertNothingSent();
    }
}
