<?php

namespace Tests\Feature;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Jobs\ProcessAiImageGenerationJob;
use App\Models\AiImport;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ai\Contracts\AiImageProviderContract;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Providers\Transport\AiConnection;
use App\Services\Ai\Registries\ProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa lifecycle ảnh và giữ chỉnh sửa candidate parent khi job hoàn tất.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): database cô lập, không gọi provider/upload thật.
 * - parentRun()/imageRun()/service(): fixtures và callback mô phỏng request đến trễ.
 * - test_image_completion_preserves_parent_edits_and_merges_under_lock(): giữ draft mới khi merge ảnh.
 * - test_cancelled_image_run_cannot_be_revived_by_a_late_provider_result(): giữ trạng thái hủy.
 * - test_expired_image_run_is_not_revived_or_overwritten_by_failed_callback(): giữ expired.
 * - test_provider_failure_after_cancellation_preserves_terminal_state(): không retry sau hủy.
 * - Adapter fixture ẩn danh trong service(): __construct(), generate() trả PNG offline.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: run/asset fixtures, callback ngay trong provider/upload giả.
 * - OUTPUT: lifecycle/metadata/cleanup đúng, không cần GD hoặc API có phí.
 * =====================================================================
 */
final class AiImageJobLifecycleTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: schema SQLite riêng và capability ảnh dùng fixture.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    /**
     * =====================================================================
     * INPUT: Database test đã dùng.
     * OUTPUT: dọn schema riêng, không đụng development.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * INPUT: Draft tùy chọn.
     * OUTPUT: content parent ready còn hạn.
     * =====================================================================
     */
    private function parentRun(array $draft = ['title' => 'Ban đầu', 'content_html' => '<p>Nội dung ban đầu.</p>']): AiImport
    {
        return AiImport::query()->create([
            'created_by' => User::factory()->create()->id, 'source_url' => '',
            'source_hash' => hash('sha256', uniqid()), 'status' => 'ready',
            'expires_at' => now()->addDay(), 'result_json' => ['draft' => $draft],
        ]);
    }

    /**
     * =====================================================================
     * INPUT: Parent tùy chọn.
     * OUTPUT: image run queued có connection server-side hợp lệ.
     * =====================================================================
     */
    private function imageRun(?AiImport $parent = null): AiImport
    {
        $provider = AiProvider::query()->create([
            'key' => 'fixture-images', 'name' => 'Fixture images', 'kind' => 'gateway',
            'driver' => 'openai-compatible', 'base_url' => 'https://images.example/v1',
            'api_key' => 'fixture-only-key', 'is_active' => true,
        ]);
        $model = AiModel::query()->create([
            'ai_provider_id' => $provider->id, 'remote_model_id' => 'fixture-image-model',
            'label' => 'Fixture image', 'capabilities' => [AiCapability::Image->value],
            'is_enabled' => true, 'is_available' => true,
        ]);

        return AiImport::query()->create([
            'created_by' => $parent?->created_by ?? User::factory()->create()->id,
            'source_url' => '', 'source_hash' => hash('sha256', uniqid()),
            'status' => 'queued', 'operation' => 'image', 'parent_id' => $parent?->id,
            'expires_at' => now()->addDay(), 'input_json' => [
                'prompt' => 'Fixture illustration', 'title' => 'Fixture image',
                'ai_connection' => [
                    'provider_id' => $provider->id, 'provider' => $provider->key,
                    'driver' => $provider->driver, 'base_url' => $provider->base_url,
                    'model_id' => $model->id, 'model' => $model->remote_model_id,
                    'timeout' => 10, 'capabilities' => [AiCapability::Image->value],
                ],
            ],
        ]);
    }

    /**
     * =====================================================================
     * INPUT: Asset, callback trong provider, kỳ vọng upload.
     * OUTPUT: service giả không dùng HTTP/GD.
     * =====================================================================
     */
    private function service(MediaAsset $asset, ?\Closure $duringGeneration = null, bool $uploadExpected = true): AiImageGenerationService
    {
        $uploader = Mockery::mock(UploadMediaAssetAction::class);
        if ($uploadExpected) {
            $uploader->shouldReceive('handle')->once()->andReturn($asset);
        } else {
            $uploader->shouldNotReceive('handle');
        }
        $provider = new class($duringGeneration, base64_decode(self::PNG)) implements AiImageProviderContract
        {
            /**
             * =====================================================================
             * INPUT: callback và PNG fixture.
             * OUTPUT: adapter offline.
             * =====================================================================
             */
            public function __construct(private readonly ?\Closure $callback, private readonly string $bytes) {}

            /**
             * =====================================================================
             * INPUT: connection/prompt.
             * OUTPUT: PNG fixture hoặc lỗi do callback; không gọi mạng.
             * =====================================================================
             */
            public function generate(AiConnection $connection, string $prompt): string
            {
                if ($this->callback) {
                    ($this->callback)();
                }

                return $this->bytes;
            }
        };

        return new AiImageGenerationService($provider, $uploader);
    }

    /**
     * =====================================================================
     * INPUT: Parent được người dùng sửa trong khi provider chưa trả ảnh.
     * OUTPUT: metadata ảnh được merge dưới lock, title/content/refs mới vẫn còn.
     * =====================================================================
     */
    public function test_image_completion_preserves_parent_edits_and_merges_under_lock(): void
    {
        $parent = $this->parentRun();
        $run = $this->imageRun($parent);
        $asset = MediaAsset::factory()->image()->create();
        $edited = ['title' => 'Đã sửa thủ công', 'content_html' => '<p>Đã sửa.</p><img data-media-asset-id="42" src="/storage/42.webp">', 'category_ids' => [7], 'taxonomy_origin' => 'manual'];
        $lockedParentQueries = [];
        $scopes = AiImport::getAllGlobalScopes();
        AiImport::addGlobalScope('observe_parent_image_merge_lock', function (Builder $query) use ($parent, &$lockedParentQueries): void {
            if ($query->getQuery()->lock === true && in_array($parent->id, array_column($query->getQuery()->wheres ?? [], 'value'), true)) {
                $lockedParentQueries[] = DB::transactionLevel();
            }
        });
        try {
            $service = $this->service($asset, function () use ($parent, $edited): void {
                $parent->update(['result_json' => ['draft' => $edited, 'editor_metadata' => 'Giữ metadata khác']]);
            });
            (new ProcessAiImageGenerationJob($run->id))->handle($service, app(ProviderRegistry::class));
            $this->assertSame('ready', $run->fresh()->status);
            $this->assertSame($edited, data_get($parent->fresh()->result_json, 'draft'));
            $this->assertSame('Giữ metadata khác', data_get($parent->fresh()->result_json, 'editor_metadata'));
            $this->assertSame($asset->id, data_get($parent->fresh()->result_json, 'image.media_asset_id'));
            $this->assertSame($run->id, data_get($parent->fresh()->result_json, 'image_job_id'));
            $this->assertCount(1, $lockedParentQueries);
            $this->assertGreaterThan(0, $lockedParentQueries[0]);
        } finally {
            AiImport::setAllGlobalScopes($scopes);
        }
    }

    /**
     * =====================================================================
     * INPUT: Hủy trong provider callback.
     * OUTPUT: giữ cancelled, không ghi ảnh parent và dọn orphan.
     * =====================================================================
     */
    public function test_cancelled_image_run_cannot_be_revived_by_a_late_provider_result(): void
    {
        $parent = $this->parentRun();
        $run = $this->imageRun($parent);
        $asset = MediaAsset::factory()->image()->create();
        $service = $this->service($asset, fn () => $run->update(['status' => 'cancelled']));
        (new ProcessAiImageGenerationJob($run->id))->handle($service, app(ProviderRegistry::class));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->fresh()->result_json);
        $this->assertNull(data_get($parent->fresh()->result_json, 'image'));
        $this->assertNull(MediaAsset::find($asset->id));
    }

    /**
     * =====================================================================
     * INPUT: Hết hạn trong provider callback.
     * OUTPUT: giữ expired/mã lỗi và dọn ảnh không dùng.
     * =====================================================================
     */
    public function test_expired_image_run_is_not_revived_or_overwritten_by_failed_callback(): void
    {
        $run = $this->imageRun();
        $asset = MediaAsset::factory()->image()->create();
        $service = $this->service($asset, fn () => $run->update(['status' => 'expired', 'error_code' => 'EXPIRED']));
        $job = new ProcessAiImageGenerationJob($run->id);
        $job->handle($service, app(ProviderRegistry::class));
        $job->failed(new \RuntimeException('Worker stopped'));

        $this->assertSame('expired', $run->fresh()->status);
        $this->assertSame('EXPIRED', $run->fresh()->error_code);
        $this->assertNull($run->fresh()->result_json);
        $this->assertNull(MediaAsset::find($asset->id));
    }

    /**
     * =====================================================================
     * INPUT: Hủy rồi provider trả lỗi retryable.
     * OUTPUT: không overwrite lỗi/status và không retry tự động.
     * =====================================================================
     */
    public function test_provider_failure_after_cancellation_preserves_terminal_state(): void
    {
        $run = $this->imageRun();
        $asset = MediaAsset::factory()->image()->create();
        $service = $this->service($asset, function () use ($run): void {
            $run->update(['status' => 'cancelled']);
            throw new AiImportException('Fixture quota error', 'AI_PROVIDER_HTTP_429', true);
        }, uploadExpected: false);
        (new ProcessAiImageGenerationJob($run->id))->handle($service, app(ProviderRegistry::class));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->fresh()->error_code);
        $this->assertNull($run->fresh()->result_json);
    }
}
