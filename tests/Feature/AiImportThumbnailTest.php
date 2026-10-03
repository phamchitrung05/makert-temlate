<?php

namespace Tests\Feature;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ai\ArticleImportService;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\DeterministicAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * Kiểm chứng container nối provider registry và uploader vào pipeline thumbnail.
 * HTTP, queue và storage được fake; database dùng SQLite in-memory cô lập.
 */
class AiImportThumbnailTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        Queue::fake();
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
        config()->set('media-assets.temporary_disk', 'media_private');
        config()->set('ai-providers.connections.http-json.endpoint', 'https://provider.test/generate');
        config()->set('ai-providers.connections.http-json.key', 'test-key');
        config()->set('ai-providers.connections.thumbnail-test', [
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
            'https://provider.test/generate' => Http::response([
                'title' => 'Rewritten article',
                'content_html' => '<p>Rewritten article content.</p>',
                'thumbnail_alt_text' => 'Article cover illustration',
            ]),
            'https://example.test/cover.png' => Http::response(
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Container-resolved service creates a public MediaAsset and exposes its ID in the draft. */
    public function test_container_resolved_import_creates_source_thumbnail(): void
    {
        $import = $this->createImport(generateThumbnail: true);

        $result = app(ArticleImportService::class)->run($import);

        $asset = MediaAsset::query()->sole();
        $media = $asset->getFirstMedia('library');
        $this->assertSame($asset->id, data_get($result, 'draft.thumbnail.media_asset_id'));
        $this->assertSame('https://example.test/cover.png', data_get($result, 'draft.thumbnail.source_url'));
        $this->assertSame('Article cover illustration', data_get($result, 'draft.thumbnail.alt_text'));
        $this->assertSame('Rewritten article', data_get($result, 'draft.title'));
        $this->assertSame('http-json', $result['provider']);
        $this->assertSame('test-model', $result['model']);
        $this->assertSame(MediaAssetKind::Image, $asset->kind);
        $this->assertSame(MediaAssetVisibility::Public, $asset->visibility);
        $this->assertSame($import->created_by, $asset->created_by);
        $this->assertSame('Article cover illustration', $asset->alt_text);
        $this->assertNotNull($media);
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertSame('media_public', $media->disk);
        Storage::disk('media_public')->assertExists($media->getPathRelativeToRoot());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/cover.png');
        Http::assertSentCount(3);
    }

    /** Explicitly disabling thumbnails leaves the source metadata without downloading/uploading an image. */
    public function test_disabled_thumbnail_does_not_download_or_create_media(): void
    {
        $result = app(ArticleImportService::class)->run($this->createImport(generateThumbnail: false));

        $this->assertNull(data_get($result, 'draft.thumbnail.media_asset_id'));
        $this->assertSame('https://example.test/cover.png', data_get($result, 'draft.thumbnail.source_url'));
        $this->assertSame('Rewritten article', data_get($result, 'draft.title'));
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media', 0);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.test/cover.png');
        Http::assertSentCount(2);
    }

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
