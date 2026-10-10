<?php

namespace Tests\Feature;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Providers\Adapters\DeterministicAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\ArticlePipelineFixture;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm provider registry/uploader và thumbnail qua baseline một lượt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - test_container_resolved_import_creates_source_thumbnail().
 * - test_disabled_thumbnail_does_not_download_or_create_media().
 * - createImport().
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures/requests admin, HTTP và Queue fake, database test cô lập.
 * - OUTPUT: assertions cho contract, snapshot, quyền và lỗi; không gọi AI thật.
 * - SIDE EFFECT: tạo/sửa dữ liệu trong database test; không chỉnh dữ liệu ứng dụng.
 * =====================================================================
 */
class AiImportThumbnailTest extends TestCase
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
        Queue::fake();
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
        config()->set('media-assets.temporary_disk', 'media_private');
        config()->set('ai.providers.connections.http-json.endpoint', 'https://provider.test/generate');
        config()->set('ai.providers.connections.http-json.key', 'test-key');
        config()->set('ai.providers.connections.thumbnail-test', [
            'enabled' => true,
            'driver' => 'http-json',
            'model' => 'test-model',
        ]);

        // The requested provider must be resolved by the registry instead of
        // silently using the legacy deterministic fallback from the contract.
        $this->app->instance(AiProviderContract::class, new DeterministicAiProvider);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/article' => Http::response(
                '<html><head><title>Source article</title><meta property="og:image" content="https://example.test/cover.png"></head><body><article><p>Source article content.</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://provider.test/generate' => fn ($request) => Http::response(ArticlePipelineFixture::httpOutput($request, [
                'title' => 'Rewritten article',
                'content_html' => '<p>Rewritten article content.</p>',
            ])),
            'https://example.test/cover.png' => Http::response(
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);
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
     * Container-resolved service creates a public MediaAsset and exposes its ID in the draft.
     * =====================================================================
     */
    public function test_container_resolved_import_creates_source_thumbnail(): void
    {
        $import = $this->createImport(generateThumbnail: true);

        $result = app(ArticleImportService::class)->run($import);

        $asset = MediaAsset::query()->sole();
        $media = $asset->getFirstMedia('library');
        $this->assertSame($asset->id, data_get($result, 'draft.thumbnail.media_asset_id'));
        $this->assertSame('https://example.test/cover.png', data_get($result, 'draft.thumbnail.source_url'));
        $this->assertSame('Source article', data_get($result, 'draft.thumbnail.alt_text'));
        $this->assertSame('Rewritten article', data_get($result, 'draft.title'));
        $this->assertSame('http-json', $result['provider']);
        $this->assertSame('test-model', $result['model']);
        $this->assertSame(MediaAssetKind::Image, $asset->kind);
        $this->assertSame(MediaAssetVisibility::Public, $asset->visibility);
        $this->assertSame($import->created_by, $asset->created_by);
        $this->assertSame('Source article', $asset->alt_text);
        $this->assertNotNull($media);
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertSame('media_public', $media->disk);
        Storage::disk('media_public')->assertExists($media->getPathRelativeToRoot());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/cover.png');
        Http::assertSentCount(5);
    }

    /**
     * =====================================================================
     * Explicitly disabling thumbnails leaves the source metadata without downloading/uploading an image.
     * =====================================================================
     */
    public function test_disabled_thumbnail_does_not_download_or_create_media(): void
    {
        $result = app(ArticleImportService::class)->run($this->createImport(generateThumbnail: false));

        $this->assertNull(data_get($result, 'draft.thumbnail.media_asset_id'));
        $this->assertSame('https://example.test/cover.png', data_get($result, 'draft.thumbnail.source_url'));
        $this->assertSame('Rewritten article', data_get($result, 'draft.title'));
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media', 0);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.test/cover.png');
        Http::assertSentCount(4);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo fixture dùng riêng trong ca kiểm thử.
     * INPUT: PHPUnit lifecycle hoặc tham số fixture của test.
     * OUTPUT: state/fixture phục vụ test, không gọi model thật.

     * =====================================================================
     */
    private function createImport(bool $generateThumbnail): AiImport
    {
        return AiImport::query()->create([
            'created_by' => User::factory()->create()->id,
            'source_url' => 'https://example.test/article',
            'status' => 'queued',
            'input_json' => [
                'source_type' => 'url',
                'provider' => 'thumbnail-test',
                'model' => 'test-model',
                'generate_thumbnail' => $generateThumbnail,
                'thumbnail_mode' => 'source',
                'fields' => ['title', 'content', 'thumbnail'],
            ],
        ]);
    }
}
