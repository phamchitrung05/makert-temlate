<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Resource;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử attach/detach/reorder MediaAsset usage
 * =====================================================================
 *
 * Test khóa invariant của usage service: field phải đúng model/kind, asset đã
 * xóa không thể attach, permission phải tồn tại và mutation nhiều bảng được
 * ghi trong transaction.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate database cô lập, seed permission và tạo actor
 * - tearDown(): rollback database cô lập
 * - actor(): tạo admin có permission tùy chọn
 * - test_attach_populates_polymorphic_usage_and_relations(): kiểm tra attach/relation
 * - test_attach_rejects_wrong_kind_model_deleted_asset_and_missing_permission(): kiểm tra invariant
 * - test_single_field_rejects_duplicate_and_replace_is_atomic(): kiểm tra single/replace
 * - test_multiple_field_can_reorder_and_detach(): kiểm tra multiple/reorder/detach
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Resource, MediaAsset, actor và MediaAssetField
 * - OUTPUT: usage rows đúng contract hoặc validation/authorization exception
 * =====================================================================
 */
class MediaAssetUsageTest extends TestCase
{
    use UsesIsolatedDatabase;

    private MediaAssetUsageService $service;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database và service usage cho test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite in-memory và seed permission admin
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        $this->service = app(MediaAssetUsageService::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database cô lập sau test usage
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Rollback schema và giải phóng connection
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo admin actor với permission media tùy chọn
     * =====================================================================
     *
     * INPUT:
     * - $permissions: danh sách permission cần cấp
     *
     * OUTPUT:
     * - User: admin active dùng để gọi usage service
     */
    private function actor(array $permissions = ['media.attach']): User
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();

        return $actor;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra attach tạo usage polymorphic và relation hai chiều
     * =====================================================================
     *
     * OUTPUT:
     * - Usage có resource alias, field cover và asset relation đúng
     * - Activity log ghi event media_asset.attached
     */
    public function test_attach_populates_polymorphic_usage_and_relations(): void
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create();
        $asset = MediaAsset::factory()->image()->create();

        $usage = $this->service->attach(
            $actor,
            $resource,
            $asset,
            MediaAssetField::ResourceCover,
        );

        $this->assertInstanceOf(MediaAssetUsage::class, $usage);
        $this->assertSame('resource', $usage->linkable_type);
        $this->assertSame($resource->id, $usage->linkable_id);
        $this->assertSame(MediaAssetField::ResourceCover, $usage->field);
        $this->assertSame($asset->id, $resource->mediaAssetsForField(
            MediaAssetField::ResourceCover,
        )->first()->id);
        $this->assertSame($resource->id, $asset->usages()->first()->linkable_id);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'media_asset',
            'subject_id' => $asset->id,
            'description' => 'media_asset.attached',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra service từ chối asset/model/permission sai
     * =====================================================================
     *
     * OUTPUT:
     * - ValidationException hoặc AuthorizationException đúng boundary
     * - Không có usage rác sau mỗi lần bị từ chối
     */
    public function test_attach_rejects_wrong_kind_model_deleted_asset_and_missing_permission(): void
    {
        $resource = Resource::factory()->create();
        $image = MediaAsset::factory()->image()->create();
        $archive = MediaAsset::factory()->archive()->create();
        $noPermissionActor = User::factory()->create(['status' => 'active']);

        try {
            $this->service->attach(
                $noPermissionActor,
                $resource,
                $image,
                MediaAssetField::ResourceCover,
            );
            $this->fail('Actor thiếu media.attach phải bị từ chối.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            $this->assertInstanceOf(\Illuminate\Auth\Access\AuthorizationException::class, $exception);
        }

        $actor = $this->actor();

        try {
            $this->service->attach($actor, $resource, $archive, MediaAssetField::ResourceCover);
            $this->fail('Archive phải bị từ chối ở field image.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset_id', $exception->errors());
        }

        try {
            $this->service->attach($actor, $resource, $archive, MediaAssetField::ResourceVersionPackage);
            $this->fail('Field của ResourceVersion không được dùng cho Resource.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('field', $exception->errors());
        }

        $deleted = MediaAsset::factory()->image()->create();
        $deleted->delete();

        try {
            $this->service->attach($actor, $resource, $deleted, MediaAssetField::ResourceCover);
            $this->fail('Asset soft-deleted phải bị từ chối.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset_id', $exception->errors());
        }

        $this->assertDatabaseCount('media_asset_usages', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra single field và replace transaction
     * =====================================================================
     *
     * OUTPUT:
     * - cover không nhận usage thứ hai; replace thay asset cũ bằng asset mới
     * - Replace sai kind không xóa usage cũ vì transaction rollback
     */
    public function test_single_field_rejects_duplicate_and_replace_is_atomic(): void
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create();
        $first = MediaAsset::factory()->image()->create();
        $second = MediaAsset::factory()->image()->create();
        $archive = MediaAsset::factory()->archive()->create();

        $this->service->attach($actor, $resource, $first, MediaAssetField::ResourceCover);

        try {
            $this->service->attach($actor, $resource, $second, MediaAssetField::ResourceCover);
            $this->fail('Single field không được attach asset thứ hai.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('field', $exception->errors());
        }

        try {
            $this->service->replace($actor, $resource, MediaAssetField::ResourceCover, [$archive]);
            $this->fail('Replace sai kind phải bị rollback.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset_id', $exception->errors());
        }

        $this->assertSame($first->id, $resource->mediaAssetsForField(
            MediaAssetField::ResourceCover,
        )->first()->id);

        $this->service->replace($actor, $resource, MediaAssetField::ResourceCover, [$second]);
        $this->assertSame($second->id, $resource->mediaAssetsForField(
            MediaAssetField::ResourceCover,
        )->first()->id);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra multiple field reorder và detach
     * =====================================================================
     *
     * OUTPUT:
     * - preview giữ thứ tự mới; detach xóa usage nhưng giữ MediaAsset
     */
    public function test_multiple_field_can_reorder_and_detach(): void
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create();
        $first = MediaAsset::factory()->image()->create();
        $second = MediaAsset::factory()->image()->create();

        $firstUsage = $this->service->attach(
            $actor,
            $resource,
            $first,
            MediaAssetField::ResourcePreview,
        );
        $secondUsage = $this->service->attach(
            $actor,
            $resource,
            $second,
            MediaAssetField::ResourcePreview,
        );

        $this->service->reorder(
            $actor,
            $resource,
            MediaAssetField::ResourcePreview,
            [$secondUsage->id, $firstUsage->id],
        );

        $this->assertSame(
            [$second->id, $first->id],
            $resource->mediaAssetsForField(MediaAssetField::ResourcePreview)
                ->orderByPivot('sort_order')
                ->pluck('media_assets.id')
                ->all(),
        );

        $this->service->detach($actor, $secondUsage->fresh());
        $this->assertDatabaseMissing('media_asset_usages', ['id' => $secondUsage->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
    }
}
