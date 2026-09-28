<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Jobs\Media\ScanMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\Resource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử Media API và authorization
 * =====================================================================
 *
 * Test bao phủ HTTP boundary của Task 5: auth/permission, list filter và
 * pagination, upload/update, usage attach/detach/reorder, delete lock, retry
 * queue và private download không lộ storage path.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate schema, seed permission, fake storage/queue
 * - tearDown(): rollback schema cô lập
 * - actorToken(): tạo admin và cấp permission
 * - pngUpload(): tạo UploadedFile PNG thật
 * - mediaFor(): thêm file Spatie vào asset theo disk
 * - test_media_api_requires_auth_and_permission(): kiểm tra 401/403
 * - test_list_filters_and_paginates_assets(): kiểm tra filter/pagination
 * - test_upload_show_and_update_metadata(): kiểm tra upload/detail/update
 * - test_usage_endpoints_and_delete_lock(): kiểm tra attach/reorder/detach/delete
 * - test_retry_and_private_download(): kiểm tra retry và stream private
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request admin và MediaAsset fixture
 * - OUTPUT: status/envelope JSON, usage rows, queue job và stream download
 * =====================================================================
 */
class MediaApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $testActor;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database, storage và queue cô lập
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite, seed permission và fake hai disk media
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
        config()->set('media-assets.temporary_disk', 'media_private');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database sau mỗi test Media API
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Rollback schema SQLite và purge connection isolated_test
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo admin và token với permission chỉ định
     * =====================================================================
     *
     * INPUT: $permissions là danh sách permission guard admin.
     * OUTPUT: string plain text Sanctum token.
     */
    private function actorToken(array $permissions): string
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->testActor = $actor;

        return $actor->createToken('media-api-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo UploadedFile PNG có nội dung ảnh thật
     * =====================================================================
     *
     * OUTPUT: UploadedFile image/png dùng cho endpoint upload.
     */
    private function pngUpload(string $name = 'cover.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-api-png-');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ));

        return new UploadedFile($path, $name, 'image/png', UPLOAD_ERR_OK, true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo một media item cho fixture asset
     * =====================================================================
     *
     * INPUT: asset, filename và custom properties tùy chọn.
     * OUTPUT: Media Spatie đã ghi vào disk fake tương ứng.
     */
    private function mediaFor(MediaAsset $asset, string $fileName, array $properties = []): \Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        return $asset->addMediaFromString('media-api-content')
            ->usingFileName($fileName)
            ->withCustomProperties($properties)
            ->toMediaCollection('library', $asset->mediaDisk());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra API yêu cầu auth và permission media.view
     * =====================================================================
     *
     * OUTPUT: HTTP 401 khi thiếu token, 403 khi token thiếu permission.
     */
    public function test_media_api_requires_auth_and_permission(): void
    {
        $this->getJson('/api/admin/media-assets')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $token = $this->actorToken([]);
        $this->withToken($token)
            ->getJson('/api/admin/media-assets')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $viewToken = $this->actorToken(['media.view']);
        $this->assertTrue($this->testActor->fresh()->can('media.view'));
        $this->withToken($viewToken)
            ->getJson('/api/admin/media-assets/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $privateAsset = MediaAsset::factory()->archive()->create(['title' => 'Private support asset']);
        $this->withToken($viewToken)
            ->getJson("/api/admin/media-assets/{$privateAsset->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
        $this->withToken($viewToken)
            ->getJson('/api/admin/media-assets')
            ->assertOk()
            ->assertJsonPath('data.itemsLength', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra list filter field/kind và pagination server-side
     * =====================================================================
     *
     * OUTPUT: Chỉ asset đúng filter, dataTable có items/itemsLength/meta.
     */
    public function test_list_filters_and_paginates_assets(): void
    {
        $token = $this->actorToken(['media.view', 'media.upload', 'media.attach']);
        $resource = Resource::factory()->create();
        $cover = MediaAsset::factory()->image()->create(['title' => 'Cover alpha']);
        $preview = MediaAsset::factory()->image()->create(['title' => 'Preview beta']);
        $package = MediaAsset::factory()->archive()->create(['title' => 'Package gamma']);
        $this->mediaFor($package, 'package.zip', [
            'scan_status' => MediaScanStatus::Error->value,
            'conversion_status' => 'ready',
        ]);

        app(\App\Services\MediaAssetUsageService::class)->attach(
            $this->testActor,
            $resource,
            $cover,
            MediaAssetField::ResourceCover,
        );

        $response = $this->withToken($token)
            ->getJson('/api/admin/media-assets?kind=image&per_page=1&sort=title&direction=asc')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.itemsLength', 2)
            ->assertJsonCount(1, 'data.items');

        $this->assertSame('Cover alpha', $response->json('data.items.0.title'));

        $fieldResponse = $this->withToken($token)
            ->getJson('/api/admin/media-assets?field=resource.cover')
            ->assertOk()
            ->assertJsonPath('data.itemsLength', 2)
            ->assertJsonCount(2, 'data.items');

        $fieldAssetIds = collect($fieldResponse->json('data.items'))
            ->pluck('id')
            ->all();

        $this->assertContains($cover->id, $fieldAssetIds);
        $this->assertContains($preview->id, $fieldAssetIds);

        $this->withToken($token)
            ->getJson('/api/admin/media-assets?kind=archive&field=resource.cover')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->withToken($token)
            ->getJson('/api/admin/media-assets?scan_status=error')
            ->assertOk()
            ->assertJsonPath('data.itemsLength', 1)
            ->assertJsonPath('data.items.0.id', $package->id);

        $this->assertNotSame($cover->id, $preview->id);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra upload, detail và update metadata
     * =====================================================================
     *
     * OUTPUT: HTTP 201/200, asset được tạo và title/alt_text được cập nhật.
     */
    public function test_upload_show_and_update_metadata(): void
    {
        $token = $this->actorToken(['media.view', 'media.upload']);
        $upload = $this->withToken($token)
            ->post('/api/admin/media-assets', [
                'file' => $this->pngUpload(),
                'kind' => MediaAssetKind::Image->value,
                'title' => 'API cover',
                'alt_text' => 'Ảnh cover API',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $assetId = $upload->json('data.id');

        $this->withToken($token)
            ->getJson("/api/admin/media-assets/{$assetId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'API cover')
            ->assertJsonMissingPath('data.file.path');

        $this->withToken($token)
            ->patchJson("/api/admin/media-assets/{$assetId}", [
                'title' => 'API cover updated',
                'alt_text' => 'Alt updated',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'API cover updated')
            ->assertJsonPath('data.alt_text', 'Alt updated');

        $this->withToken($token)
            ->patchJson("/api/admin/media-assets/{$assetId}", [
                'visibility' => MediaAssetVisibility::Private->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.visibility', MediaAssetVisibility::Private->value)
            ->assertJsonPath('data.file.url', null);

        $this->assertDatabaseHas('media', [
            'model_id' => $assetId,
            'disk' => 'media_private',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra attach/reorder/detach và delete lock
     * =====================================================================
     *
     * OUTPUT: usage API đúng field, reorder thành công, delete bị khóa khi còn usage.
     */
    public function test_usage_endpoints_and_delete_lock(): void
    {
        $token = $this->actorToken(['media.view', 'media.attach', 'media.delete']);
        $resource = Resource::factory()->create();
        $first = MediaAsset::factory()->image()->create();
        $second = MediaAsset::factory()->image()->create();

        $firstUsage = $this->withToken($token)
            ->postJson("/api/admin/media-assets/{$first->id}/usages", [
                'field' => MediaAssetField::ResourcePreview->value,
                'linkable_type' => 'resource',
                'linkable_id' => $resource->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.field', MediaAssetField::ResourcePreview->value);
        $firstUsageId = $firstUsage->json('data.id');

        $secondUsage = $this->withToken($token)
            ->postJson("/api/admin/media-assets/{$second->id}/usages", [
                'field' => MediaAssetField::ResourcePreview->value,
                'linkable_type' => 'resource',
                'linkable_id' => $resource->id,
            ])
            ->assertCreated();
        $secondUsageId = $secondUsage->json('data.id');

        $this->withToken($token)
            ->postJson('/api/admin/media-assets/usages/reorder', [
                'field' => MediaAssetField::ResourcePreview->value,
                'linkable_type' => 'resource',
                'linkable_id' => $resource->id,
                'usage_ids' => [$secondUsageId, $firstUsageId],
            ])
            ->assertOk();

        $this->withToken($token)
            ->deleteJson("/api/admin/media-assets/{$first->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->withToken($token)
            ->deleteJson("/api/admin/media-assets/{$first->id}/usages/{$firstUsageId}")
            ->assertNoContent();

        $this->assertDatabaseHas('media_asset_usages', ['id' => $secondUsageId]);
        $this->assertDatabaseMissing('media_asset_usages', ['id' => $firstUsageId]);

    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra retry queue và private download
     * =====================================================================
     *
     * OUTPUT: retry trả 202, scan job được dispatch và private download stream không lộ path.
     */
    public function test_retry_and_private_download(): void
    {
        $token = $this->actorToken(['media.view', 'media.upload', 'media.retry']);
        $asset = MediaAsset::factory()->archive()->create([
            'visibility' => MediaAssetVisibility::Private,
        ]);
        $media = $this->mediaFor($asset, 'package.zip', [
            'scan_status' => MediaScanStatus::Error->value,
            'conversion_status' => 'ready',
            'upload_metadata' => [
                'original_name' => 'package.zip',
                'mime_type' => 'application/zip',
                'extension' => 'zip',
                'size' => 18,
            ],
        ]);

        $this->withToken($token)
            ->postJson("/api/admin/media-assets/{$asset->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.file.scan_status', MediaScanStatus::Pending->value);
        Queue::assertPushed(ScanMediaAssetJob::class, fn (ScanMediaAssetJob $job): bool => $job->mediaId === $media->id);

        $this->withToken($token)
            ->get("/api/admin/media-assets/{$asset->id}/download")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $media->setCustomProperty('scan_status', MediaScanStatus::Clean->value)->save();

        $download = $this->withToken($token)
            ->get("/api/admin/media-assets/{$asset->id}/download");
        $download->assertOk();

        if ($download->headers->get('Content-Type') === 'application/json') {
            $download->assertJsonPath('success', true)
                ->assertJsonPath('data.expires_at', fn (mixed $expiresAt): bool => is_string($expiresAt));
        } else {
            $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
            $this->assertStringNotContainsString(storage_path(), (string) $download->getContent());
        }
    }
}
