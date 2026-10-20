<?php

namespace Tests\Feature;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\AiCapability;
use App\Enums\MediaAssetField;
use App\Jobs\ProcessAiImportJob;
use App\Jobs\ProcessSocialPostCaptionJob;
use App\Jobs\ProcessSocialPostImageJob;
use App\Jobs\ProcessSocialPostImagePromptJob;
use App\Models\AiImport;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiTaskRun;
use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Ai\Contracts\AiImageProviderContract;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskResponse;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Providers\Transport\AiConnection;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Marketing\SocialPostAiWorkerService;
use App\Services\Marketing\SocialPostGenerationService;
use App\Services\Marketing\SocialPostGenerationStateService;
use App\Services\Marketing\SocialPostVersionService;
use App\Services\MediaAssetUsageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/** MP-03 boundary/worker regression tests: isolated DB, fake queue/providers, no paid API. */
final class SocialAiContentIntegrationTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private string $token;

    private AiModel $imageModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create(['status' => 'active']);
        $this->actor->givePermissionTo(['social_posts.create', 'social_posts.view', 'social_posts.update', 'media.attach', 'media.upload']);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->token = $this->actor->createToken('social-mp03', ['admin'])->plainTextToken;
        $provider = AiProvider::create([
            'key' => 'fixture-image', 'name' => 'Image fixture', 'kind' => 'gateway', 'driver' => 'openai-compatible',
            'base_url' => 'https://images.example/v1', 'api_key' => 'private-fixture-key', 'is_active' => true,
        ]);
        $this->imageModel = AiModel::create([
            'ai_provider_id' => $provider->id, 'remote_model_id' => 'fixture-image', 'label' => 'Fixture',
            'capabilities' => [AiCapability::Image->value], 'is_enabled' => true, 'is_available' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'target_type' => 'social_post', 'text' => 'Một buổi sáng bên vườn hoa.',
            'provider' => 'deterministic', 'model' => 'deterministic', 'language' => 'en',
            'requested_outputs' => ['social_content', 'social_gallery'], 'image_count' => 2,
            'image_model_id' => $this->imageModel->id, 'length' => 'short',
            'writing_brief' => ['audience' => 'Người yêu hoa'], 'instructions' => 'Giọng nhẹ nhàng.',
        ], $extra);
    }

    private function create(array $extra = []): array
    {
        return $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $this->payload($extra))
            ->assertAccepted()->json('data');
    }

    public function test_manual_entry_uses_social_queues_context_and_owner_scoped_deduplication(): void
    {
        $result = $this->create();
        $duplicate = $this->create();
        $this->assertSame($result['social_post_id'], $duplicate['social_post_id']);
        $this->assertCount(2, $result['jobs']);
        $this->assertDatabaseCount('social_posts', 1);
        $this->assertDatabaseCount('ai_imports', 2);
        $this->assertDatabaseCount('posts', 0);
        Queue::assertNotPushed(ProcessAiImportJob::class);
        Queue::assertPushed(ProcessSocialPostCaptionJob::class, 1);
        Queue::assertPushed(ProcessSocialPostImagePromptJob::class, 1);
        $run = AiImport::findOrFail($result['job_id']);
        $context = data_get($run->input_json, 'context');
        $this->assertSame('Một buổi sáng bên vườn hoa.', $context['source_text']);
        $this->assertSame('en', $context['language']);
        $this->assertSame('Người yêu hoa', $context['writing_brief']['audience']);
        $this->assertSame(config('ai.content.length_options.short'), $context['length']);
        $this->assertStringNotContainsString('private-fixture-key', AiImport::all()->toJson());
        $metadata = AiTaskRun::all()->pluck('metadata_json')->toJson();
        $this->assertStringNotContainsString('source_text', $metadata);
        $this->assertStringNotContainsString('platform_caption', $metadata);
        $this->withToken($this->token)->getJson('/api/admin/social-posts/'.$result['social_post_id'])
            ->assertOk()->assertJsonCount(2, 'data.generation_jobs')->assertJsonPath('data.facebook_variant.image_count', 2);
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions')->assertOk()->assertJsonCount(0, 'data');
        $other = User::factory()->create(['status' => 'active']);
        $other->givePermissionTo('social_posts.create');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $otherToken = $other->createToken('other-social', ['admin'])->plainTextToken;
        $otherId = $this->withToken($otherToken)->postJson('/api/admin/ai-agent/sessions', $this->payload())
            ->assertAccepted()->json('data.social_post_id');
        $this->assertNotSame($result['social_post_id'], $otherId);
    }

    public function test_permissions_queue_and_invalid_models_do_not_leave_empty_drafts(): void
    {
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $this->payload(['image_model_id' => 999999]))->assertUnprocessable();
        $this->assertDatabaseCount('social_posts', 0);
        $this->assertDatabaseCount('ai_imports', 0);
        config(['queue.default' => 'sync']);
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $this->payload())->assertUnprocessable();
        config(['queue.default' => 'database']);
        $this->actor->revokePermissionTo('social_posts.create');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('social_posts', 0);
    }

    public function test_selected_outputs_are_independent_and_regeneration_retains_source(): void
    {
        $result = $this->create(['requested_outputs' => ['social_content']]);
        $run = AiImport::findOrFail($result['job_id']);
        app(SocialPostAiWorkerService::class)->processCaption($run);
        $post = SocialPost::findOrFail($result['social_post_id']);
        $this->assertStringContainsString('vườn hoa', $post->caption);
        $this->assertSame('partial', $post->generation_status->value);
        Queue::assertNotPushed(ProcessSocialPostImagePromptJob::class);
        $queued = app(SocialPostGenerationService::class)->queue($post, $this->actor->id, ['component' => 'image', 'image_model_id' => $this->imageModel->id]);
        $imagePrompt = AiImport::findOrFail($queued['image_prompt_job_id']);
        $this->assertNull($queued['caption_job_id']);
        $this->assertSame(data_get($run->input_json, 'context.source_text'), data_get($imagePrompt->input_json, 'context.source_text'));
        $this->assertSame('en', data_get($imagePrompt->input_json, 'context.language'));
        $this->assertSame($post->caption, $post->fresh()->caption);
        $this->assertSame(1, $post->variants()->firstOrFail()->current_version_no);
    }

    public function test_cancelled_and_duplicate_prompt_jobs_cannot_fan_out_again(): void
    {
        $result = $this->create();
        $run = AiImport::where('operation', 'social_image_prompt')->firstOrFail();
        $worker = app(SocialPostAiWorkerService::class);
        $worker->processImagePrompt($run);
        $worker->processImagePrompt($run);
        $this->assertSame(2, AiImport::where('parent_id', $run->id)->count());
        Queue::assertPushed(ProcessSocialPostImageJob::class, 2);
        $caption = AiImport::findOrFail($result['job_id']);
        $caption->update(['status' => 'cancelled']);
        $worker->processCaption($caption);
        $this->assertNull(SocialPost::findOrFail($result['social_post_id'])->caption);
        $this->assertSame('cancelled', $caption->fresh()->status);
    }

    public function test_gallery_attaches_in_order_and_retry_only_replaces_the_selected_slot(): void
    {
        $result = $this->create();
        $worker = app(SocialPostAiWorkerService::class);
        $worker->processCaption(AiImport::findOrFail($result['job_id']));
        $worker->processImagePrompt(AiImport::where('operation', 'social_image_prompt')->firstOrFail());
        $runs = AiImport::where('operation', 'social_image')->get()->sortBy(fn ($run) => data_get($run->input_json, 'slot_no'))->values();
        $second = $this->renderImage($runs[1]);
        $post = SocialPost::findOrFail($result['social_post_id']);
        $this->assertSame('partial', $post->generation_status->value);
        $first = $this->renderImage($runs[0]);
        $post->refresh();
        $this->assertTrue(app(SocialPostGenerationStateService::class)->isReady($post));
        $this->assertSame([$first->id, $second->id], $this->assetIds($post));
        $caption = $post->caption;
        $attempt = (int) $runs[0]->generation_no;
        $this->withToken($this->token)->postJson('/api/admin/social-posts/'.$post->id.'/variants/facebook/images/0/regenerate')
            ->assertAccepted()->assertJsonPath('data.slot_no', 0);
        $this->assertSame([$first->id, $second->id], $this->assetIds($post));
        $this->assertSame('ready', $runs[1]->fresh()->status);
        (new ProcessSocialPostImageJob($runs[0]->id, $attempt))->failed(new \RuntimeException('Old attempt'));
        $this->assertSame('queued', $runs[0]->fresh()->status);
        $replacement = $this->renderImage($runs[0]->fresh());
        $this->assertSame([$replacement->id, $second->id], $this->assetIds($post));
        $this->assertSame($caption, $post->fresh()->caption);
        $this->assertFalse($first->fresh()->trashed()); // Old versions retain their assets.
    }

    public function test_late_cancelled_image_is_cleaned_up_and_cannot_revive_the_run(): void
    {
        $result = $this->create(['image_count' => 1]);
        app(SocialPostAiWorkerService::class)->processImagePrompt(AiImport::where('operation', 'social_image_prompt')->firstOrFail());
        $run = AiImport::where('operation', 'social_image')->firstOrFail();
        $asset = $this->renderImage($run, fn () => AiImport::whereKey($run->id)->update(['status' => 'cancelled']));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertTrue($asset->fresh()->trashed());
        $this->assertSame([], $this->assetIds(SocialPost::findOrFail($result['social_post_id'])));
    }

    public function test_failed_slot_keeps_ready_content_and_images_and_can_be_retried(): void
    {
        $result = $this->create();
        $worker = app(SocialPostAiWorkerService::class);
        $worker->processCaption(AiImport::findOrFail($result['job_id']));
        $worker->processImagePrompt(AiImport::where('operation', 'social_image_prompt')->firstOrFail());
        $runs = AiImport::where('operation', 'social_image')->get()->sortBy(fn ($run) => data_get($run->input_json, 'slot_no'))->values();
        $first = $this->renderImage($runs[0]);
        $worker->fail($runs[1], 'AI_IMAGE_PROVIDER_FAILED', 'Fixture failure');
        $post = SocialPost::findOrFail($result['social_post_id']);
        $this->assertSame('partial', $post->generation_status->value);
        $this->assertNotEmpty($post->caption);
        $this->assertSame([$first->id], $this->assetIds($post));
        $this->withToken($this->token)->postJson('/api/admin/social-posts/'.$post->id.'/variants/facebook/images/1/regenerate')->assertAccepted();
        $second = $this->renderImage($runs[1]->fresh());
        $this->assertSame([$first->id, $second->id], $this->assetIds($post));
        $this->assertSame('ready', $post->fresh()->generation_status->value);
    }

    public function test_late_image_from_an_older_generation_is_discarded(): void
    {
        $result = $this->create(['image_count' => 1]);
        $worker = app(SocialPostAiWorkerService::class);
        $worker->processImagePrompt(AiImport::where('operation', 'social_image_prompt')->firstOrFail());
        $post = SocialPost::findOrFail($result['social_post_id']);
        $run = AiImport::where('operation', 'social_image')->firstOrFail();
        $asset = $this->renderImage($run, fn () => app(SocialPostGenerationService::class)->queue($post, $this->actor->id, ['component' => 'caption']));
        $this->assertTrue($asset->fresh()->trashed());
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('AI_STALE_GENERATION', $run->fresh()->error_code);
        $this->assertSame(2, $post->fresh()->generation_no);
        $this->assertSame([], $this->assetIds($post));
        $worker->processCaption(AiImport::findOrFail($result['job_id']));
        $this->assertNull($post->fresh()->caption);
    }

    public function test_schema_rejects_invented_fact_fields_and_late_prompt_cannot_spawn_images(): void
    {
        $result = $this->create();
        $caption = AiImport::findOrFail($result['job_id']);
        $this->fakeText($caption, fn () => ['body' => 'Invalid', 'fact_ids' => [123], 'platform_caption' => 'Forbidden']);
        app(SocialPostAiWorkerService::class)->processCaption($caption);
        $this->assertSame('AI_PROVIDER_SCHEMA', $caption->fresh()->error_code);
        $run = AiImport::where('operation', 'social_image_prompt')->firstOrFail();
        $this->fakeText($run, function () use ($run): array {
            AiImport::whereKey($run->id)->update(['status' => 'cancelled']);

            return ['image_prompt' => 'Garden photo, natural light', 'negative_prompt' => 'text', 'aspect_ratio' => '4:5', 'slot_count' => 2];
        });
        app(SocialPostAiWorkerService::class)->processImagePrompt($run);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, AiImport::where('operation', 'social_image')->count());
    }

    private function fakeText(AiImport $run, \Closure $response): void
    {
        $provider = Mockery::mock(AiProviderContract::class);
        $provider->shouldReceive('execute')->once()->andReturnUsing(fn ($request) => new AiTaskResponse($response($request)));
        $this->app->instance($provider::class, $provider);
        config(['ai.providers.connections.social_fixture' => ['enabled' => true, 'driver' => 'social_fixture', 'model' => 'fake'],
            'ai.providers.internal.social_fixture' => ['adapter' => $provider::class]]);
        $input = $run->input_json;
        $input['ai_connection'] = ['provider' => 'social_fixture', 'driver' => 'social_fixture', 'model' => 'fake'];
        $run->update(['provider' => 'social_fixture', 'input_json' => $input]);
    }

    private function assetIds(SocialPost $post): array
    {
        return $post->variants()->firstOrFail()->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)
            ->orderBy('sort_order')->pluck('media_asset_id')->all();
    }

    private function renderImage(AiImport $run, ?\Closure $during = null): MediaAsset
    {
        $asset = MediaAsset::factory()->image()->create(['created_by' => $this->actor->id]);
        $uploader = Mockery::mock(UploadMediaAssetAction::class);
        $uploader->shouldReceive('handle')->once()->andReturn($asset);
        $provider = new class($during) implements AiImageProviderContract
        {
            public function __construct(private readonly ?\Closure $during) {}

            public function generate(AiConnection $connection, string $prompt): string
            {
                if ($this->during) {
                    ($this->during)();
                }

                return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
            }
        };
        (new ProcessSocialPostImageJob($run->id, (int) $run->generation_no))->handle(
            new AiImageGenerationService($provider, $uploader), app(ProviderRegistry::class), app(MediaAssetUsageService::class),
            app(SocialPostVersionService::class), app(SocialPostGenerationStateService::class), app(SocialPostAiWorkerService::class),
        );

        return $asset;
    }
}
