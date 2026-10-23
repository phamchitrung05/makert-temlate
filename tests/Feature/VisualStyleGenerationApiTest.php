<?php

namespace Tests\Feature;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Jobs\GenerateVisualStyleImagesJob;
use App\Models\AiImageProfile;
use App\Models\AiImport;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\VisualStyleShot;
use App\Services\Ai\Contracts\AiImageProviderContract;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Providers\Transport\AiConnection;
use App\Services\Ai\VisualStyle\VisualStyleGenerationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Feature test cho API sinh ảnh Visual Style (VSE-5).
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - token()/imageModel()/style()/shot(): helper tạo actor/provider/style/recipe.
 * - fakeImageService(): bind AiImageProviderContract offline và uploader mock.
 * - test_generate_creates_import_and_dispatches_job: POST /generate tạo AiImport
 *   queued và dispatch GenerateVisualStyleImagesJob.
 * - test_generate_rejects_invalid_style_and_recipe: style chưa sẵn sàng (draft /
 *   analyzed nhưng tắt is_enabled), recipe không thuộc style, quá shot_count → 422.
 * - test_generate_accepts_analyzed_enabled_style: style đã phân tích + bật dùng
 *   được chấp nhận (không bắt buộc status='active').
 * - test_process_builds_prompt_and_uploads_per_recipe: service gọi
 *   VisualPromptBuilder đúng recipe và lưu media + prompt_used per-shot.
 * - test_process_marks_failed_shot_and_continues: shot lỗi ghi failed riêng,
 *   các shot khác vẫn ready, status tổng = ready.
 * - test_show_returns_status_and_outputs: GET /generations/{id} trả status +
 *   outputs flatten.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : HTTP request admin có Sanctum token + ai_settings.manage.
 * - OUTPUT: JSON BaseResponse envelope; DB/queue/provider fake.
 * =====================================================================
 */
final class VisualStyleGenerationApiTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    private function token(array $permissions = ['ai_settings.manage']): string
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor->createToken('vse-generation-test', ['admin'])->plainTextToken;
    }

    private function imageModel(): int
    {
        $provider = AiProvider::create([
            'key' => 'img-'.Str::random(6), 'name' => 'Image Provider', 'kind' => 'gateway',
            'driver' => 'openai-compatible', 'base_url' => 'https://images.example/v1',
            'api_key' => 'test-key', 'is_active' => true,
        ]);

        return $provider->models()->create([
            'remote_model_id' => 'image-model', 'label' => 'Image',
            'capabilities' => [AiCapability::Image->value],
            'is_enabled' => true, 'is_available' => true,
        ])->id;
    }

    private function style(array $overrides = []): AiImageProfile
    {
        return AiImageProfile::create(array_merge([
            'created_by' => User::factory()->create()->id,
            'name' => 'Test Style', 'category' => 'product',
            'status' => 'analyzed', 'is_enabled' => true, 'version' => 3,
            'reference_path' => 'ref/'.Str::random(8).'.png',
            'reference_mime' => 'image/png',
            'reference_hash' => hash('sha256', 'ref'),
            'connection_snapshot_json' => ['provider' => 'none', 'model' => 'none'],
            'analysis_instructions' => 'instructions',
            'dna_json' => [
                'composition' => ['framing' => 'medium'],
                'camera' => ['angle' => 'eye_level'],
            ],
        ], $overrides));
    }

    private function shot(AiImageProfile $style, array $overrides = []): VisualStyleShot
    {
        return VisualStyleShot::create(array_merge([
            'visual_style_id' => $style->id,
            'code' => 'hero_'.Str::random(4), 'name' => 'Hero',
            'is_active' => true, 'sort_order' => 1,
        ], $overrides));
    }

    private function fakeImageService(): void
    {
        $provider = new class(base64_decode(self::PNG)) implements AiImageProviderContract
        {
            public function __construct(private readonly string $bytes) {}

            public function generate(AiConnection $connection, string $prompt): string
            {
                return $this->bytes;
            }
        };
        $uploader = Mockery::mock(UploadMediaAssetAction::class);
        $uploader->shouldReceive('handle')->andReturnUsing(fn () => MediaAsset::factory()->create());
        $this->app->instance(AiImageProviderContract::class, $provider);
        $this->app->instance(UploadMediaAssetAction::class, $uploader);
    }

    public function test_generate_creates_import_and_dispatches_job(): void
    {
        $token = $this->token();
        $modelId = $this->imageModel();
        $style = $this->style();
        $shot1 = $this->shot($style, ['code' => 'front']);
        $shot2 = $this->shot($style, ['code' => 'detail', 'sort_order' => 2]);

        $response = $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $style->id,
            'shot_recipe_ids' => [$shot1->id, $shot2->id],
            'product' => ['media_ids' => [11, 12], 'description' => 'Áo thun cotton', 'category' => 'fashion'],
            'aspect_ratio' => '4:5',
            'image_count' => 2,
            'instructions' => ['Nền sáng', 'Giữ logo'],
            'model_id' => $modelId,
        ]);
        $response->assertAccepted()->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.visual_style_id', $style->id)
            ->assertJsonPath('data.image_count', 2);
        Queue::assertPushed(GenerateVisualStyleImagesJob::class);
        $import = AiImport::findOrFail($response->json('data.id'));
        $this->assertSame('visual_style_generate', $import->operation);
        $this->assertSame([$shot1->id, $shot2->id], $import->input_json['shot_recipe_ids']);
        $this->assertSame('4:5', $import->input_json['aspect_ratio']);
        $this->assertSame([11, 12], $import->input_json['product']['media_ids']);
    }

    public function test_generate_accepts_analyzed_enabled_style(): void
    {
        $token = $this->token();
        $modelId = $this->imageModel();
        // Style chỉ ở 'analyzed' (không qua activate) + is_enabled = đủ điều kiện.
        $style = $this->style(['status' => 'analyzed', 'is_enabled' => true]);
        $shot = $this->shot($style);

        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $style->id, 'shot_recipe_ids' => [$shot->id],
            'image_count' => 1, 'model_id' => $modelId,
        ])->assertAccepted()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(GenerateVisualStyleImagesJob::class);
    }

    public function test_generate_rejects_invalid_input(): void
    {
        $token = $this->token();
        $modelId = $this->imageModel();
        $style = $this->style();
        $shot = $this->shot($style);
        $otherStyle = $this->style(['status' => 'draft', 'is_enabled' => false]);
        $otherShot = $this->shot($otherStyle);
        $disabledStyle = $this->style(['status' => 'analyzed', 'is_enabled' => false]);
        $disabledShot = $this->shot($disabledStyle);

        // Style còn draft (chưa có DNA) bị reject.
        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $otherStyle->id, 'shot_recipe_ids' => [$otherShot->id], 'model_id' => $modelId,
        ])->assertUnprocessable();
        // Style đã phân tích nhưng chưa bật dùng bị reject.
        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $disabledStyle->id, 'shot_recipe_ids' => [$disabledShot->id], 'model_id' => $modelId,
        ])->assertUnprocessable();
        // Recipe không thuộc style.
        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $style->id, 'shot_recipe_ids' => [$otherShot->id], 'model_id' => $modelId,
        ])->assertUnprocessable();
        // Quá 4 shot.
        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $style->id, 'shot_recipe_ids' => [$shot->id, $shot->id, $shot->id, $shot->id, $shot->id],
            'model_id' => $modelId,
        ])->assertUnprocessable();
        // image_count vượt max.
        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $style->id, 'shot_recipe_ids' => [$shot->id], 'image_count' => 99, 'model_id' => $modelId,
        ])->assertUnprocessable();
        // aspect_ratio sai định dạng.
        $this->withToken($token)->postJson('/api/admin/ai/visual-styles/generate', [
            'style_id' => $style->id, 'shot_recipe_ids' => [$shot->id], 'aspect_ratio' => 'wide', 'model_id' => $modelId,
        ])->assertUnprocessable();
    }

    public function test_process_builds_prompt_and_uploads_per_recipe(): void
    {
        $this->fakeImageService();
        $style = $this->style();
        $shot = $this->shot($style, ['code' => 'hero']);
        $modelId = $this->imageModel();
        $snapshot = app(\App\Services\Ai\Providers\Catalog\ModelResolver::class)
            ->resolve(AiCapability::Image, ['model_id' => $modelId]);
        $import = AiImport::create([
            'created_by' => $style->created_by, 'source_url' => '',
            'source_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => 'queued', 'operation' => 'visual_style_generate',
            'input_json' => [
                'visual_style_id' => $style->id, 'shot_recipe_ids' => [$shot->id],
                'product' => ['description' => 'Áo thun', 'category' => 'fashion'],
                'aspect_ratio' => '1:1', 'image_count' => 2, 'user_instructions' => [],
                'ai_connection' => $snapshot,
            ],
            'provider' => $snapshot['provider'],
        ]);

        app(VisualStyleGenerationService::class)->process((string) $import->id);
        $import->refresh();
        $this->assertSame('ready', $import->status);
        $outputs = $import->result_json['outputs'];
        $this->assertArrayHasKey('hero', $outputs);
        $this->assertSame('ready', $outputs['hero']['status']);
        $this->assertCount(2, $outputs['hero']['images']);
        $this->assertNotEmpty($outputs['hero']['images'][0]['prompt_used']);
        $this->assertNotEmpty($outputs['hero']['images'][0]['media_asset_id']);
        $this->assertSame(2, $import->result_json['summary']['succeeded']);
        $this->assertSame(0, $import->result_json['summary']['failed']);
    }

    public function test_process_marks_failed_shot_and_continues(): void
    {
        $failProvider = new class(base64_decode(self::PNG)) implements AiImageProviderContract
        {
            public int $calls = 0;

            public function __construct(private readonly string $bytes) {}

            public function generate(AiConnection $connection, string $prompt): string
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new AiImportException('Provider fail', 'AI_PROVIDER_FAIL');
                }

                return $this->bytes;
            }
        };
        $uploader = Mockery::mock(UploadMediaAssetAction::class);
        $uploader->shouldReceive('handle')->andReturnUsing(fn () => MediaAsset::factory()->create());
        $this->app->instance(AiImageProviderContract::class, $failProvider);
        $this->app->instance(UploadMediaAssetAction::class, $uploader);

        $style = $this->style();
        $shot1 = $this->shot($style, ['code' => 'a']);
        $shot2 = $this->shot($style, ['code' => 'b', 'sort_order' => 2]);
        $modelId = $this->imageModel();
        $snapshot = app(\App\Services\Ai\Providers\Catalog\ModelResolver::class)
            ->resolve(AiCapability::Image, ['model_id' => $modelId]);
        $import = AiImport::create([
            'created_by' => $style->created_by, 'source_url' => '',
            'source_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => 'queued', 'operation' => 'visual_style_generate',
            'input_json' => [
                'visual_style_id' => $style->id, 'shot_recipe_ids' => [$shot1->id, $shot2->id],
                'aspect_ratio' => '1:1', 'image_count' => 1, 'user_instructions' => [],
                'ai_connection' => $snapshot,
            ],
            'provider' => $snapshot['provider'],
        ]);

        app(VisualStyleGenerationService::class)->process((string) $import->id);
        $import->refresh();
        $this->assertSame('ready', $import->status);
        $outputs = $import->result_json['outputs'];
        $this->assertSame('failed', $outputs['a']['status']);
        $this->assertSame('ready', $outputs['b']['status']);
        $this->assertSame(1, $import->result_json['summary']['failed']);
        $this->assertSame(1, $import->result_json['summary']['succeeded']);
    }

    public function test_show_returns_status_and_outputs(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $owner->givePermissionTo(['ai_settings.manage']);
        $this->flushPermissionCache();
        $token = $owner->createToken('vse-generation-test', ['admin'])->plainTextToken;
        $style = $this->style(['created_by' => $owner->id]);
        $shot = $this->shot($style);
        $import = AiImport::create([
            'created_by' => $owner->id, 'source_url' => '',
            'source_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => 'ready', 'operation' => 'visual_style_generate',
            'input_json' => ['visual_style_id' => $style->id, 'shot_recipe_ids' => [$shot->id]],
            'result_json' => ['outputs' => ['hero' => ['recipe_code' => 'hero', 'status' => 'ready', 'images' => []]],
                'summary' => ['succeeded' => 1, 'failed' => 0]],
        ]);

        $this->withToken($token)->getJson('/api/admin/ai/visual-styles/generations/'.$import->id)
            ->assertOk()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.outputs.hero.recipe_code', 'hero');
    }

    public function test_show_forbidden_for_other_owner_or_operation(): void
    {
        $token = $this->token();
        $otherToken = $this->token();
        $import = AiImport::create([
            'created_by' => User::factory()->create()->id, 'source_url' => '',
            'source_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => 'ready', 'operation' => 'visual_style_generate',
        ]);
        // Owner khác bị chặn.
        $this->withToken($otherToken)->getJson('/api/admin/ai/visual-styles/generations/'.$import->id)
            ->assertForbidden();
        // Operation khác bị 404.
        $article = AiImport::create([
            'created_by' => $import->created_by, 'source_url' => '',
            'source_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => 'ready', 'operation' => 'article',
        ]);
        $this->withToken($token)->getJson('/api/admin/ai/visual-styles/generations/'.$article->id)
            ->assertNotFound();
    }
}
