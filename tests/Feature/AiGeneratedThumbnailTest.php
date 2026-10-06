<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImageGenerationJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Content\AiContentReviewService;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Registries\ProviderRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * CHỨC NĂNG FILE: Regression thumbnail AI từ create đến lưu/duyệt Post.
 * HÀM: setUp/tearDown, createRun/imageJob/versions/approve và các test luồng.
 * INPUT/OUTPUT: API + workers với HTTP/queue fake -> canonical asset/provenance.
 * SIDE EFFECT: SQLite in-memory/storage fake; không gọi AI hoặc DB ứng dụng.
 */
final class AiGeneratedThumbnailTest extends TestCase
{
    use UsesIsolatedDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private User $actor;

    private string $token;

    private AiModel $imageModel;

    private \Closure $imageResponse;

    /** INPUT: lifecycle. OUTPUT: fixture quyền/catalog và I/O cô lập, không mạng thật. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database', 'media-library.asset_disks.public' => 'media_public',
            'media-library.asset_disks.private' => 'media_private', 'media-assets.temporary_disk' => 'media_private']);
        Queue::fake();
        Storage::fake('media_public');
        Storage::fake('media_private');
        Http::preventStrayRequests();
        $this->actor = User::factory()->create(['status' => 'active']);
        $this->actor->givePermissionTo(['posts.manage', 'media.upload', 'media.attach']);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->token = $this->actor->createToken('thumbnail-test', ['admin'])->plainTextToken;
        $provider = AiProvider::query()->create(['key' => 'fixture-images', 'name' => 'Images', 'kind' => 'gateway',
            'driver' => 'openai-compatible', 'base_url' => 'https://images.example/v1', 'api_key' => 'fixture-secret', 'is_active' => true]);
        $this->imageModel = $provider->models()->create(['remote_model_id' => 'fixture-image', 'label' => 'Image',
            'capabilities' => ['image_generation'], 'is_enabled' => true, 'is_available' => true]);
        $this->imageResponse = fn () => Http::response(['data' => [['b64_json' => self::PNG]]]);
        Http::fake(['https://images.example/v1/images/generations' => fn ($request) => ($this->imageResponse)($request)]);
    }

    /** INPUT: test hoàn tất. OUTPUT: dọn database cô lập, không đổi dữ liệu ứng dụng. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** INPUT: override request. OUTPUT: content ready, child thumbnail queued; queue/provider ảnh chưa chạy. */
    private function createRun(array $overrides = []): AiImport
    {
        $id = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', array_replace([
            'text' => 'Nguồn bài viết hướng dẫn cấu hình ứng dụng. Luôn kiểm tra cấu hình trước khi đưa ứng dụng vào sử dụng.',
            'provider' => 'deterministic', 'requested_outputs' => ['title', 'content', 'thumbnail'],
            'thumbnail_mode' => 'generate', 'image_model_id' => $this->imageModel->id,
            'thumbnail_prompt' => 'Minh họa bài viết, không có chữ.',
        ], $overrides))->assertStatus(202)->json('data.job_id');
        (new ProcessAiImportJob($id))->handle(app(ArticleImportService::class));

        return AiImport::findOrFail($id);
    }

    /** INPUT: parent. OUTPUT: chạy worker ảnh qua transport/uploader thật với HTTP fixture. */
    private function imageJob(AiImport $parent): AiImport
    {
        $image = AiImport::findOrFail(data_get($parent->fresh()->result_json, 'image_job_id'));
        (new ProcessAiImageGenerationJob($image->id))->handle(app(AiImageGenerationService::class), app(ProviderRegistry::class));

        return $image->fresh();
    }

    /** INPUT: run. OUTPUT: hash draft/review hiện hành, không mutation. */
    private function versions(AiImport $run): array
    {
        return ['expected_version' => hash('sha256', json_encode(data_get($run->result_json, 'draft', []))),
            'expected_review_version' => AiContentReviewService::version($run)];
    }

    /** INPUT: run và versions tùy chọn. OUTPUT: response duyệt với thumbnail, không publish. */
    private function approve(AiImport $run, ?array $versions = null)
    {
        return $this->withToken($this->token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            ...($versions ?? $this->versions($run->fresh())), 'fields' => ['title', 'content', 'thumbnail'],
        ]);
    }

    /** INPUT: queue giao lại ảnh khi lock worker còn hiệu lực. OUTPUT: hoãn job, không làm mất message hoặc gọi trùng provider. */
    public function test_busy_image_worker_releases_delivery_instead_of_leaving_a_queued_run_without_job(): void
    {
        $run = $this->createRun();
        $image = AiImport::findOrFail(data_get($run->result_json, 'image_job_id'));
        $job = new ProcessAiImageGenerationJob($image->id);
        $lock = Cache::lock('ai-image-process-'.$image->id, $job->timeout + 60);
        $this->assertTrue($lock->get());
        $delivery = \Mockery::mock(Job::class);
        $delivery->shouldReceive('release')->once()->with($job->timeout + 60);
        $job->setJob($delivery);
        try {
            $job->handle(app(AiImageGenerationService::class), app(ProviderRegistry::class));
            $this->assertSame('queued', $image->fresh()->status);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    /** INPUT: image trả về sau edit. OUTPUT: ref canonical/list/review/Post đúng, provenance dùng model ảnh; stale version bị chặn. */
    public function test_generated_thumbnail_flows_through_summary_review_and_post_with_image_provenance(): void
    {
        $run = $this->createRun();
        $this->assertSame('ready', $run->status);
        $this->assertSame('queued', data_get($run->result_json, 'thumbnail_generation.status'));
        Queue::assertPushed(ProcessAiImageGenerationJob::class, 1);
        $this->approve($run)->assertConflict();
        $stale = $this->versions($run);
        $this->imageResponse = function ($request) use ($run) {
            $this->assertSame('Minh họa bài viết, không có chữ.', $request['prompt']);
            $this->assertSame('fixture-image', $request['model']);
            $current = $run->fresh()->result_json;
            $current['draft']['title'] = 'Tiêu đề sửa khi chờ ảnh';
            $current['draft']['content_html'] = '<p>Nội dung sửa thủ công.</p>';
            $run->update(['result_json' => $current]);

            return Http::response(['data' => [['b64_json' => self::PNG]]]);
        };
        $image = $this->imageJob($run);
        $asset = MediaAsset::query()->sole();
        $run->refresh();
        $this->assertSame('ready', $image->status);
        $this->assertSame($asset->id, data_get($run->result_json, 'draft.thumbnail.media_asset_id'));
        $this->assertSame($image->id, data_get($run->result_json, 'draft.thumbnail.image_run_id'));
        $this->assertSame('<p>Nội dung sửa thủ công.</p>', data_get($run->result_json, 'draft.content_html'));
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions')->assertOk()
            ->assertJsonPath('data.0.thumbnail.id', $asset->id)->assertJsonPath('data.0.thumbnail_generation.status', 'ready');
        $detail = $this->withToken($this->token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')->assertOk()
            ->assertJsonPath('data.thumbnail.id', $asset->id)->assertJsonPath('data.can_approve', true);
        $this->assertStringNotContainsString('fixture-secret', $detail->getContent());
        $this->approve($run, $stale)->assertConflict();
        $postId = $this->approve($run)->assertOk()->json('data.post_id');
        $this->assertDatabaseHas('posts', ['id' => $postId, 'status' => 'draft', 'title' => 'Tiêu đề sửa khi chờ ảnh']);
        $this->assertDatabaseHas('media_asset_usages', ['media_asset_id' => $asset->id, 'field' => 'post.thumbnail']);
        $this->assertDatabaseHas('ai_provenances', ['target_id' => $postId, 'field' => 'thumbnail', 'run_id' => $image->id, 'provider' => 'fixture-images', 'model' => 'fixture-image']);
        $this->assertDatabaseHas('ai_provenances', ['target_id' => $postId, 'field' => 'title', 'run_id' => $run->id, 'provider' => 'deterministic']);
        $this->assertContains('thumbnail', $run->fresh()->applied_fields);
        $this->travel(3)->days();
        $this->artisan('ai-import:cleanup')->assertSuccessful();
        $this->assertNotNull(MediaAsset::find($asset->id));
        $this->assertNotNull(Post::find($postId));
        Http::assertSentCount(1);
    }

    /** INPUT: lỗi provider rồi retry thủ công. OUTPUT: giữ bài ready, chỉ tạo lại ảnh cùng UUID, chặn retry trùng. */
    public function test_image_failure_can_retry_without_regenerating_article(): void
    {
        $run = $this->createRun();
        $draft = data_get($run->result_json, 'draft');
        $this->imageResponse = fn () => Http::response(['error' => ['message' => 'fixture-secret']], 401);
        $image = $this->imageJob($run);
        $this->assertSame('failed', $image->status);
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame($draft, data_get($run->fresh()->result_json, 'draft'));
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk()
            ->assertJsonPath('data.thumbnail_generation.status', 'failed')->assertJsonPath('data.status', 'ready');
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$image->id.'/retry')->assertOk();
        $this->assertSame('queued', data_get($run->fresh()->result_json, 'thumbnail_generation.status'));
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$image->id.'/retry')->assertConflict();
        $this->imageResponse = fn () => Http::response(['data' => [['b64_json' => self::PNG]]]);
        $this->imageJob($run);
        $this->assertDatabaseCount('ai_imports', 2);
        $this->assertSame(data_get($draft, 'content_html'), data_get($run->fresh()->result_json, 'draft.content_html'));
        $this->approve($run)->assertOk();
        Http::assertSentCount(2);
    }

    /** INPUT: từ chối trong request ảnh chưa hoàn thành. OUTPUT: response trễ không đổi parent và retry bị chặn. */
    public function test_rejected_candidate_does_not_receive_late_image_or_allow_retry(): void
    {
        $run = $this->createRun();
        $this->imageResponse = function () use ($run) {
            $this->withToken($this->token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/reject', [
                ...$this->versions($run->fresh()), 'reason' => 'Không phù hợp',
            ])->assertOk();

            return Http::response(['data' => [['b64_json' => self::PNG]]]);
        };
        $image = $this->imageJob($run);
        $this->assertNull(data_get($run->fresh()->result_json, 'draft.thumbnail.media_asset_id'));
        $image->update(['status' => 'failed']);
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$image->id.'/retry')->assertConflict();
        $this->assertSame('rejected', AiContentReviewService::state($run->fresh())['status']);
        $this->assertDatabaseCount('posts', 0);
    }

    /** INPUT: hủy child, sau đó duyệt bài không ảnh. OUTPUT: trạng thái terminal của ảnh đồng bộ và không bị worker hồi sinh. */
    public function test_cancelled_thumbnail_allows_approval_without_image_and_late_worker_is_ignored(): void
    {
        $run = $this->createRun();
        $imageId = data_get($run->result_json, 'image_job_id');
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$imageId.'/cancel')->assertOk();
        $this->assertSame('cancelled', data_get($run->fresh()->result_json, 'thumbnail_generation.status'));
        $this->imageJob($run);
        Http::assertNothingSent();
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            ...$this->versions($run->fresh()), 'fields' => ['title', 'content'],
        ])->assertOk();
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$imageId.'/retry')->assertConflict();
    }

    /** INPUT: tạo lại riêng thumbnail trên nguồn text. OUTPUT: bài mới giữ text đã sửa, model ảnh override, không request model text. */
    public function test_thumbnail_only_regeneration_can_switch_to_ai_and_inherit_generated_image_lineage(): void
    {
        $run = $this->createRun(['thumbnail_mode' => 'source', 'requested_outputs' => ['title', 'content']]);
        $original = data_get($run->result_json, 'draft.content_html');
        $id = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', [
            'fields' => ['thumbnail'], 'thumbnail_mode' => 'generate', 'image_model_id' => $this->imageModel->id,
        ])->assertStatus(202)->json('data.job_id');
        (new ProcessAiImportJob($id))->handle(app(ArticleImportService::class));
        $child = AiImport::findOrFail($id);
        $this->assertSame($original, data_get($child->result_json, 'draft.content_html'));
        $image = $this->imageJob($child);
        // Tạo lại chỉ tiêu đề giữ thumbnail đã tạo và lineage ảnh gốc.
        $nextId = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$id.'/regenerate', [
            'fields' => ['title'],
        ])->assertStatus(202)->json('data.job_id');
        (new ProcessAiImportJob($nextId))->handle(app(ArticleImportService::class));
        $next = AiImport::findOrFail($nextId);
        $this->assertSame(data_get($child->fresh()->result_json, 'draft.thumbnail.media_asset_id'), data_get($next->result_json, 'draft.thumbnail.media_asset_id'));
        $postId = $this->approve($next)->assertOk()->json('data.post_id');
        $this->assertDatabaseHas('ai_provenances', ['target_id' => $postId, 'field' => 'thumbnail', 'run_id' => $image->id]);
        $allId = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', [
            'fields' => [], 'thumbnail_mode' => 'generate', 'image_model_id' => $this->imageModel->id,
        ])->assertStatus(202)->json('data.job_id');
        (new ProcessAiImportJob($allId))->handle(app(ArticleImportService::class));
        $this->assertNotEmpty(data_get(AiImport::findOrFail($allId)->result_json, 'image_job_id'));
        $other = $this->imageModel->provider->models()->create(['remote_model_id' => 'second-image', 'label' => 'Second image',
            'capabilities' => ['image_generation'], 'is_enabled' => true, 'is_available' => true]);
        $pairId = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$child->id.'/regenerate', [
            'fields' => ['thumbnail'], 'image_provider' => 'fixture-images', 'image_model' => 'second-image',
        ])->assertStatus(202)->json('data.job_id');
        $this->assertSame($other->id, data_get(AiImport::findOrFail($pairId)->input_json, 'image_connection.model_id'));
        Http::assertSentCount(1);
    }

    /** INPUT: sai model/quyền. OUTPUT: 422/403 trước khi lưu/dispatch run; không silent fallback. */
    public function test_explicit_image_selection_requires_upload_permission_and_usable_image_model(): void
    {
        $payload = ['text' => 'Source', 'provider' => 'deterministic', 'thumbnail_mode' => 'generate',
            'requested_outputs' => ['title', 'content', 'thumbnail'], 'image_model_id' => $this->imageModel->id];
        $this->imageModel->update(['is_enabled' => false]);
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $payload)->assertUnprocessable()->assertJsonValidationErrors('image_model_id');
        $this->actor->revokePermissionTo('media.upload');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $payload)->assertForbidden();
        $this->assertDatabaseCount('ai_imports', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
