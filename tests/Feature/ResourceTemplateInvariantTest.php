<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\MediaAsset;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Models\ResourceVersionTemplateScreen;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\ResourceVersionPackageGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm invariant package và lifecycle của TPL-02
 * =====================================================================
 *
 * Test bảo đảm package đi qua MediaAssetUsageService, archive public bị
 * chặn, scan được đọc lại ở gate dùng chung, version ready/default không bị
 * thay hoặc xóa và draft mutation làm mất snapshot preview stale.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): database và storage cô lập
 * - test_draft_package_replace_and_detach_invalidate_template_snapshot():
 *   kiểm one-to-one và invalidation
 * - test_package_guard_rechecks_current_scan_status(): kiểm scan gate
 * - test_ready_package_is_immutable_through_usage_service(): kiểm guard ready
 * - test_destroy_rejects_default_and_ready_versions(): kiểm delete guard
 * - test_destroy_preserves_version_with_download_history(): kiểm retention
 * - test_client_cannot_set_version_lifecycle_fields(): kiểm request/action
 * - actor(): tạo admin có quyền media attach và version manage
 * - archive(): tạo archive asset kèm scan metadata tùy chọn
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : factory Resource/ResourceVersion/MediaAsset và admin API payload.
 * - OUTPUT: assertions về usage, processing metadata, scan gate và HTTP 422.
 * - SIDE EFFECT: migrate/rollback SQLite cô lập, fake private/public storage.
 * =====================================================================
 */
class ResourceTemplateInvariantTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * Chuẩn bị schema, quyền và disk fake cho test package.
     * Input: không có.
     * Output: database/config sẵn sàng.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
    }

    /**
     * Dọn schema SQLite và config test sau mỗi ca.
     * Input: database isolated_test từ setUp().
     * Output: connection được reset.
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * Package draft chỉ có một usage; thay hoặc tháo package xóa snapshot cũ.
     * Input: version template draft, hai archive và template ready có screen.
     * Output: usage mới đúng một asset, template pending và screen rỗng.
     */
    public function test_draft_package_replace_and_detach_invalidate_template_snapshot(): void
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create(['type' => ResourceType::Template]);
        $version = ResourceVersion::factory()->create(['resource_id' => $resource->id]);
        $template = ResourceVersionTemplate::factory()->ready()->create([
            'resource_version_id' => $version->id,
        ]);
        $screen = ResourceVersionTemplateScreen::factory()->create([
            'resource_version_template_id' => $template->id,
        ]);
        $first = $this->archive();
        $replacement = $this->archive();
        $preview = MediaAsset::factory()->image()->create();
        $service = app(MediaAssetUsageService::class);

        $service->attach($actor, $screen, $preview, MediaAssetField::TemplateScreenPreview);
        $service->attach($actor, $version, $first, MediaAssetField::ResourceVersionPackage);
        $service->replace($actor, $version, MediaAssetField::ResourceVersionPackage, [$replacement]);

        $this->assertSame(
            [$replacement->id],
            $version->fresh()->mediaAssetUsagesForField(MediaAssetField::ResourceVersionPackage)
                ->pluck('media_asset_id')->all(),
        );
        $this->assertSame(
            ResourceVersionTemplateProcessingStatus::Pending,
            $template->fresh()->processing_status,
        );
        $this->assertNull($template->fresh()->manifest_json);
        $this->assertNull($template->fresh()->preview_path);
        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'template_screen',
            'linkable_id' => $screen->id,
        ]);
        $this->assertDatabaseCount('resource_version_template_screens', 0);

        $service->detach($actor, $version->fresh()->mediaAssetUsagesForField(
            MediaAssetField::ResourceVersionPackage,
        )->firstOrFail());

        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'resource_version',
            'linkable_id' => $version->id,
            'field' => MediaAssetField::ResourceVersionPackage->value,
        ]);
    }

    /**
     * Guard luôn đọc scan_status hiện tại thay vì tin trạng thái upload cũ.
     * Input: package pending rồi chuyển clean.
     * Output: pending bị từ chối; clean trả về đúng asset.
     */
    public function test_package_guard_rechecks_current_scan_status(): void
    {
        $version = ResourceVersion::factory()->create();
        $asset = $this->archive(MediaScanStatus::Pending->value);
        app(MediaAssetUsageService::class)->replace(
            $this->actor(),
            $version,
            MediaAssetField::ResourceVersionPackage,
            [$asset],
        );

        try {
            app(ResourceVersionPackageGuard::class)->cleanPackageFor($version);
            $this->fail('Package pending phải bị scan gate chặn.');
        } catch (ValidationException) {
            $media = $asset->getFirstMedia('library');
            $media->setCustomProperty('scan_status', MediaScanStatus::Clean->value)->save();
        }

        $this->assertTrue(
            app(ResourceVersionPackageGuard::class)->cleanPackageFor($version)->is($asset),
        );
    }

    /**
     * Package ready không thể replace/detach qua usage service.
     * Input: version ready có package private.
     * Output: cả hai mutation ném ValidationException và usage còn nguyên.
     */
    public function test_ready_package_is_immutable_through_usage_service(): void
    {
        $actor = $this->actor();
        $version = ResourceVersion::factory()->create(['status' => ResourceVersionStatus::Ready]);
        $package = $this->archive();
        $replacement = $this->archive();
        $service = app(MediaAssetUsageService::class);

        $version->status = ResourceVersionStatus::Draft;
        $version->save();
        $service->attach($actor, $version, $package, MediaAssetField::ResourceVersionPackage);
        $version->forceFill(['status' => ResourceVersionStatus::Ready])->save();

        try {
            $service->replace($actor, $version, MediaAssetField::ResourceVersionPackage, [$replacement]);
            $this->fail('Ready package phải immutable khi replace.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('media_asset_usages', [
                'linkable_id' => $version->id,
                'media_asset_id' => $package->id,
                'field' => MediaAssetField::ResourceVersionPackage->value,
            ]);
        }

        try {
            $service->detach($actor, $version->fresh()->mediaAssetUsagesForField(
                MediaAssetField::ResourceVersionPackage,
            )->firstOrFail());
            $this->fail('Ready package phải immutable khi detach.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('media_asset_usages', [
                'linkable_id' => $version->id,
                'media_asset_id' => $package->id,
            ]);
        }
    }

    /**
     * Destroy route giữ default và ready để bảo toàn lịch sử download.
     * Input: hai version lần lượt default draft và ready.
     * Output: HTTP 422, row và usage vẫn tồn tại.
     */
    public function test_destroy_rejects_default_and_ready_versions(): void
    {
        $token = $this->actorToken();
        $resource = Resource::factory()->create();
        $default = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'is_default' => true,
        ]);
        $ready = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'status' => ResourceVersionStatus::Ready,
        ]);

        $this->withToken($token)->deleteJson('/api/admin/resource-versions/'.$default->id)
            ->assertUnprocessable();
        $this->withToken($token)->deleteJson('/api/admin/resource-versions/'.$ready->id)
            ->assertUnprocessable();

        $this->assertDatabaseHas('resource_versions', ['id' => $default->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('resource_versions', ['id' => $ready->id, 'deleted_at' => null]);
    }

    /**
     * Download history giữ version lại dù version vẫn đang draft.
     * Input: version draft, package media và một download record.
     * Output: HTTP 422; version/usage chưa bị tháo hoặc xóa mềm.
     */
    public function test_destroy_preserves_version_with_download_history(): void
    {
        $token = $this->actorToken();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create(['resource_id' => $resource->id]);
        $package = $this->archive(MediaScanStatus::Clean->value);
        $media = $package->getFirstMedia('library');
        app(MediaAssetUsageService::class)->attach(
            $this->actor(),
            $version,
            $package,
            MediaAssetField::ResourceVersionPackage,
        );

        DB::table('downloads')->insert([
            'resource_id' => $resource->id,
            'resource_version_id' => $version->id,
            'media_id' => $media->id,
            'status' => 'completed',
            'downloaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->deleteJson('/api/admin/resource-versions/'.$version->id)
            ->assertUnprocessable();

        $this->assertDatabaseHas('resource_versions', ['id' => $version->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('media_asset_usages', [
            'linkable_id' => $version->id,
            'media_asset_id' => $package->id,
            'field' => MediaAssetField::ResourceVersionPackage->value,
        ]);
    }

    /**
     * Client không thể set status ready hoặc is_default ở create/update.
     * Input: payload có lifecycle fields giả.
     * Output: version vẫn draft và không phải default.
     */
    public function test_client_cannot_set_version_lifecycle_fields(): void
    {
        $token = $this->actorToken();
        $resource = Resource::factory()->create();

        $created = $this->withToken($token)->postJson('/api/admin/resource-versions', [
            'resource_id' => $resource->id,
            'version' => '9.9.9',
            'status' => ResourceVersionStatus::Ready->value,
            'is_default' => true,
        ])->assertCreated();

        $version = ResourceVersion::query()->findOrFail($created->json('data.id'));
        $this->assertSame(ResourceVersionStatus::Draft, $version->status);
        $this->assertFalse($version->is_default);

        $this->withToken($token)->putJson('/api/admin/resource-versions/'.$version->id, [
            'status' => ResourceVersionStatus::Ready->value,
            'is_default' => true,
        ])->assertOk();

        $this->assertSame(ResourceVersionStatus::Draft, $version->fresh()->status);
        $this->assertFalse($version->fresh()->is_default);
    }

    /**
     * Tạo actor có permission cho usage và resource version API.
     * Input: không có.
     * Output: User active; permission cache được làm mới.
     */
    private function actor(): User
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['media.attach', 'resource_versions.manage']);
        $this->flushPermissionCache();

        return $actor;
    }

    /**
     * Tạo token Sanctum cho actor quản trị.
     * Input: không có.
     * Output: plain text token dùng ở HTTP test.
     */
    private function actorToken(): string
    {
        Auth::forgetGuards();

        return $this->actor()->createToken('tpl-02-test', ['admin'])->plainTextToken;
    }

    /**
     * Tạo archive private và media item có scan status tùy chọn.
     * Input: custom property scan_status hoặc null để chỉ kiểm attach.
     * Output: MediaAsset archive đã lưu.
     */
    private function archive(?string $scanStatus = null): MediaAsset
    {
        $asset = MediaAsset::factory()->archive()->create([
            'visibility' => MediaAssetVisibility::Private,
        ]);

        if ($scanStatus !== null) {
            $asset->addMediaFromString('tpl-02-archive')
                ->usingFileName('template.zip')
                ->withCustomProperties(['scan_status' => $scanStatus])
                ->toMediaCollection('library', $asset->mediaDisk());
        }

        return $asset;
    }
}
