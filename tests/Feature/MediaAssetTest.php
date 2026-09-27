<?php

namespace Tests\Feature;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\MediaAsset;
use App\Models\User;
use App\Policies\MediaAssetPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử schema, cast, collection và activity của MediaAsset
 * =====================================================================
 *
 * Test xác nhận owner nghiệp vụ MediaAsset hoạt động độc lập với bảng `media`,
 * chọn đúng disk theo visibility và giữ soft-delete metadata.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate database cô lập và seed permission
 * - tearDown(): rollback database cô lập
 * - test_media_asset_casts_metadata_and_selects_visibility_disk(): kiểm tra cast/disk
 * - test_media_asset_registers_library_collection_and_image_conversions(): kiểm tra collection/conversion
 * - test_media_asset_soft_delete_preserves_metadata_and_activity_log(): kiểm tra soft delete/audit
 * - test_media_asset_policy_uses_granular_media_permissions(): kiểm tra policy permission
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata MediaAsset được tạo từ factory
 * - OUTPUT: assert schema, enum cast, collection, disk và soft delete
 * =====================================================================
 */
class MediaAssetTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị schema cô lập cho test MediaAsset
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite in-memory và seed permission media guard admin
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Config::set('media-library.asset_disks.public', 'media_public');
        Config::set('media-library.asset_disks.private', 'media_private');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn schema sau test MediaAsset
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Rollback SQLite in-memory và giải phóng connection
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra enum cast và disk theo visibility
     * =====================================================================
     *
     * OUTPUT:
     * - Asset image public dùng media_public; archive private dùng media_private
     */
    public function test_media_asset_casts_metadata_and_selects_visibility_disk(): void
    {
        $publicAsset = MediaAsset::factory()->image()->create();
        $privateAsset = MediaAsset::factory()->archive()->create();

        $this->assertSame(MediaAssetKind::Image, $publicAsset->kind);
        $this->assertSame(MediaAssetVisibility::Public, $publicAsset->visibility);
        $this->assertSame('media_public', $publicAsset->mediaDisk());
        $this->assertSame(MediaAssetKind::Archive, $privateAsset->kind);
        $this->assertSame(MediaAssetVisibility::Private, $privateAsset->visibility);
        $this->assertSame('media_private', $privateAsset->mediaDisk());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra collection library và conversion của image asset
     * =====================================================================
     *
     * OUTPUT:
     * - Image collection dùng media_public và có conversion thumb/web
     * - Archive collection dùng media_private nhưng không đăng ký conversion ảnh
     */
    public function test_media_asset_registers_library_collection_and_image_conversions(): void
    {
        $image = MediaAsset::factory()->image()->create();
        $archive = MediaAsset::factory()->archive()->create();

        $this->assertSame('library', $image->getMediaCollection('library')->name);
        $this->assertSame('media_public', $image->getMediaCollection('library')->diskName);
        $this->assertSame('media_private', $archive->getMediaCollection('library')->diskName);

        $image->registerAllMediaConversions();
        $conversions = $image->mediaConversions;
        $this->assertCount(2, $conversions);
        $this->assertSame(['thumb', 'web'], array_map(
            static fn ($conversion): string => $conversion->getName(),
            $conversions,
        ));

        $archive->registerAllMediaConversions();
        $this->assertCount(0, $archive->mediaConversions);

        Storage::fake('media_private');
        $archive->addMediaFromString('package-content')
            ->usingFileName('package.zip')
            ->toMediaCollection('library');

        $this->assertDatabaseHas('media', [
            'model_type' => 'media_asset',
            'model_id' => $archive->id,
            'collection_name' => 'library',
            'disk' => 'media_private',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra soft delete vẫn giữ metadata và ghi activity
     * =====================================================================
     *
     * OUTPUT:
     * - Asset bị soft delete, metadata còn truy vấn được qua withTrashed()
     * - activity_log có subject media_asset cho mutation
     */
    public function test_media_asset_soft_delete_preserves_metadata_and_activity_log(): void
    {
        $asset = MediaAsset::factory()->image()->create(['title' => 'Cover cần xóa']);
        $asset->delete();

        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
        $this->assertSame(
            'Cover cần xóa',
            MediaAsset::withTrashed()->findOrFail($asset->id)->title,
        );
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'media_asset',
            'subject_id' => $asset->id,
            'log_name' => 'media',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra policy dùng permission riêng cho từng thao tác
     * =====================================================================
     *
     * OUTPUT:
     * - Admin chỉ có media.view không được upload/delete/retry
     * - Admin có permission tương ứng được policy cho phép
     */
    public function test_media_asset_policy_uses_granular_media_permissions(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $asset = MediaAsset::factory()->create();
        $policy = new MediaAssetPolicy;

        $user->givePermissionTo('media.view');
        $this->flushPermissionCache();

        $this->assertTrue($policy->view($user, $asset));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->delete($user, $asset));

        $user->givePermissionTo(['media.upload', 'media.delete', 'media.retry']);
        $this->flushPermissionCache();

        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->delete($user, $asset));
        $this->assertTrue($policy->retry($user, $asset));
    }
}
