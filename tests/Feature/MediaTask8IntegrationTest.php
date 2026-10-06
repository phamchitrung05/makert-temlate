<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử tích hợp media của Resource/Post/Version
 * =====================================================================
 *
 * Test khóa contract Task 8 ở HTTP boundary: mỗi domain dùng đúng field
 * riêng, replace/delete detach usage và version chỉ ready sau khi package
 * archive private đã scan clean. Mọi test dùng database SQLite cô lập.
 * =====================================================================
 */
class MediaTask8IntegrationTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
        config()->set('media-assets.temporary_disk', 'media_private');
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * Resource cover/preview được tạo, replace theo thứ tự mới và detach khi
     * resource bị xoá mềm; MediaAsset vẫn còn nguyên.
     */
    public function test_resource_media_fields_replace_and_delete_atomically(): void
    {
        $token = $this->token([
            'resources.create',
            'resources.update',
            'resources.delete',
            'media.attach',
        ]);
        $cover = MediaAsset::factory()->image()->create();
        $preview = MediaAsset::factory()->image()->create();
        $replacementCover = MediaAsset::factory()->image()->create();

        $created = $this->withToken($token)->postJson('/api/admin/resources', [
            'type' => ResourceType::Template->value,
            'title' => 'Task 8 Resource',
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
            'media' => [
                'cover_id' => $cover->id,
                'preview_ids' => [$preview->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.media.cover.id', $cover->id)
            ->assertJsonPath('data.media.preview.0.id', $preview->id);

        $resource = Resource::query()->findOrFail($created->json('data.id'));
        $this->assertDatabaseHas('media_asset_usages', [
            'linkable_type' => 'resource',
            'linkable_id' => $resource->id,
            'field' => MediaAssetField::ResourceCover->value,
            'media_asset_id' => $cover->id,
        ]);

        $this->withToken($token)->putJson("/api/admin/resources/{$resource->id}", [
            'media' => [
                'cover_id' => $replacementCover->id,
                'preview_ids' => [$replacementCover->id],
            ],
        ])->assertOk()
            ->assertJsonPath('data.media.cover.id', $replacementCover->id)
            ->assertJsonPath('data.media.preview.0.id', $replacementCover->id);

        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_id' => $resource->id,
            'media_asset_id' => $cover->id,
        ]);
        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_id' => $resource->id,
            'media_asset_id' => $preview->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/admin/resources/{$resource->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'resource',
            'linkable_id' => $resource->id,
        ]);
        $this->assertDatabaseHas('media_assets', ['id' => $replacementCover->id]);
    }

    /**
     * Nếu một media id sai, transaction Resource create rollback cả bản ghi
     * domain lẫn usage cover đã được ghi trước đó.
     */
    public function test_resource_media_sync_rolls_back_when_one_asset_is_invalid(): void
    {
        $token = $this->token(['resources.create', 'media.attach']);
        $cover = MediaAsset::factory()->image()->create();

        $this->withToken($token)->postJson('/api/admin/resources', [
            'type' => ResourceType::Template->value,
            'title' => 'Rollback Resource',
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
            'media' => [
                'cover_id' => $cover->id,
                'preview_ids' => [$cover->id, 999999],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('resources', 0);
        $this->assertDatabaseCount('media_asset_usages', 0);
    }

    /**
     * Resource Version documentation chỉ nhận document; package pending bị
     * chặn ở ready và package archive/private/clean mới được chuyển trạng thái.
     */
    public function test_resource_version_package_scan_gate_and_documentation_field(): void
    {
        $token = $this->token(['resource_versions.manage', 'media.attach']);
        $resource = Resource::factory()->create();
        $package = MediaAsset::factory()->archive()->create();
        $packageMedia = $this->mediaFor($package, 'package.zip', MediaScanStatus::Pending->value);
        $documentation = MediaAsset::factory()->document()->create();
        $wrongKind = MediaAsset::factory()->image()->create();
        $publicPackage = MediaAsset::factory()->archive()->create([
            'visibility' => MediaAssetVisibility::Public,
        ]);

        $this->withToken($token)->postJson('/api/admin/resource-versions', [
            'resource_id' => $resource->id,
            'version' => '1.0.0',
            'media' => [
                'package_id' => $package->id,
                'documentation_ids' => [$documentation->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.media.package.id', $package->id)
            ->assertJsonPath('data.media.documentation.0.id', $documentation->id);

        $version = ResourceVersion::query()->firstOrFail();

        $this->withToken($token)
            ->postJson("/api/admin/resource-versions/{$version->id}/ready")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $packageMedia->setCustomProperty('scan_status', MediaScanStatus::Clean->value)->save();

        $this->withToken($token)
            ->postJson("/api/admin/resource-versions/{$version->id}/ready")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $this->withToken($token)->postJson('/api/admin/resource-versions', [
            'resource_id' => $resource->id,
            'version' => '1.0.1',
            'media' => ['documentation_ids' => [$wrongKind->id]],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('resource_versions', ['version' => '1.0.1']);

        $this->withToken($token)->postJson('/api/admin/resource-versions', [
            'resource_id' => $resource->id,
            'version' => '1.0.2',
            'media' => ['package_id' => $publicPackage->id],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('resource_versions', ['version' => '1.0.2']);
    }

    /**
     * Post dùng thumbnail/gallery riêng; content lưu link, update thay usage và delete
     * không xoá asset dùng chung.
     */
    public function test_post_media_fields_are_independent_and_detach_on_delete(): void
    {
        $token = $this->token(['posts.manage', 'media.attach']);
        $thumbnail = MediaAsset::factory()->image()->create();
        $contentImage = MediaAsset::factory()->image()->create();
        $replacement = MediaAsset::factory()->image()->create();

        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Task 8 Post',
            'content' => $this->inlineHtml($contentImage),
            'media' => [
                'thumbnail_id' => $thumbnail->id,
                'gallery_image_ids' => [$contentImage->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.media.thumbnail.id', $thumbnail->id)
            ->assertJsonPath('data.media.gallery_images.0.id', $contentImage->id);

        $post = Post::query()->findOrFail($created->json('data.id'));

        $this->withToken($token)->putJson("/api/admin/posts/{$post->id}", [
            'media' => [
                'thumbnail_id' => $replacement->id,
                'gallery_image_ids' => [$replacement->id],
            ],
        ])->assertOk()
            ->assertJsonPath('data.media.thumbnail.id', $replacement->id);

        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'post',
            'linkable_id' => $post->id,
            'media_asset_id' => $thumbnail->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/admin/posts/{$post->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'post',
            'linkable_id' => $post->id,
        ]);
        $this->assertDatabaseHas('media_assets', ['id' => $replacement->id]);
    }

    /**
     * Tạo token admin có permission đúng với route và usage policy.
     */
    private function token(array $permissions): string
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $admin->createToken('task-8-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo HTML ảnh đúng contract inline thay cho gallery ngoài content.
     * =====================================================================
     * INPUT: Public image fixture.
     * OUTPUT: HTML ID/URL khớp metadata Spatie; không cần GD hoặc upload thực.
     * =====================================================================
     */
    private function inlineHtml(MediaAsset $asset): string
    {
        $media = $asset->media()->create([
            'collection_name' => 'library', 'name' => 'Inline fixture', 'file_name' => 'inline.png',
            'disk' => 'media_public', 'conversions_disk' => 'media_public', 'mime_type' => 'image/png',
            'size' => 100, 'manipulations' => [], 'custom_properties' => [],
            'generated_conversions' => [], 'responsive_images' => [], 'order_column' => 1,
        ]);

        return '<p>Content</p><img data-media-asset-id="'.$asset->id.'" src="'.e($media->getUrl()).'" alt="Inline">';
    }

    /**
     * Tạo file Spatie tối thiểu để ready gate đọc scan_status.
     */
    private function mediaFor(MediaAsset $asset, string $fileName, string $scanStatus): Media
    {
        return $asset->addMediaFromString('task-8-content')
            ->usingFileName($fileName)
            ->withCustomProperties(['scan_status' => $scanStatus])
            ->toMediaCollection('library', $asset->mediaDisk());
    }
}
