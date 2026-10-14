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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm ready/promote/rollback/archive lifecycle TPL-05
 * =====================================================================
 *
 * Test giữ package private/clean và template artifact synthetic để kiểm gate
 * lifecycle không cần chạy lại ZIP 9 MiB. Các action vẫn đi qua route thật,
 * lock/resource owner và activity log như production.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): database, permission và private disk cô lập.
 * - test_template_ready_requires_processed_snapshot_and_artifact(): ready gate.
 * - test_promote_and_rollback_keep_single_default(): promote/rollback transaction.
 * - test_archive_rejects_default_and_download_retention(): archive policy.
 * - test_resource_version_response_exposes_template_state(): resource response.
 * - actor()/actorToken(): tạo admin có quyền version lifecycle.
 * - makeVersion()/attachPackage()/markTemplateReady(): tạo fixture package/template.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion template, package clean và template snapshot.
 * - OUTPUT: HTTP lifecycle response, DB default/status/audit assertions.
 * - SIDE EFFECT: SQLite/storage/permission test cô lập; không provider/network.
 * - EXCEPTION/TRANSACTION: validation HTTP rollback được PHPUnit kiểm tra.
 * =====================================================================
 */
final class ResourceTemplateLifecycleTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn ready cho template chưa process và cho phép snapshot đủ gate
     * =====================================================================
     * INPUT: Draft template có package clean, sau đó thêm metadata ready/artifact.
     * OUTPUT: Lượt đầu 422; lượt đủ gate 200/ready và activity version.ready.
     * SIDE EFFECT: Ghi resource_versions/template/activity_log.
     * EXCEPTION/TRANSACTION: Ready action rollback khi processing gate thiếu.
     * =====================================================================
     */
    public function test_template_ready_requires_processed_snapshot_and_artifact(): void
    {
        [$version, $package, $actor] = $this->makeVersion();
        $token = $this->actorToken($actor);

        $this->withToken($token)
            ->postJson('/api/admin/resource-versions/'.$version->id.'/ready')
            ->assertUnprocessable();

        $template = $this->markTemplateReady($version, $package);

        $this->withToken($token)
            ->postJson('/api/admin/resource-versions/'.$version->id.'/ready')
            ->assertOk()
            ->assertJsonPath('data.status', ResourceVersionStatus::Ready->value)
            ->assertJsonPath('data.template.processing_status', ResourceVersionTemplateProcessingStatus::Ready->value)
            ->assertJsonPath('data.template.preview_available', true);

        $this->assertSame(ResourceVersionStatus::Ready, $version->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $version->id,
            'event' => 'version.ready',
        ]);
        $this->assertNotNull($template->fresh()->processed_at);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Promote version mới và rollback version ready cũ
     * =====================================================================
     * INPUT: Hai version template ready cùng Resource, v1 đang default.
     * OUTPUT: Promote v2 rồi rollback v1, mỗi thời điểm chỉ một default.
     * SIDE EFFECT: Ghi is_default, activity promoted/rolled_back và cache forget.
     * EXCEPTION/TRANSACTION: Resource lock serialize mutation cùng resource.
     * =====================================================================
     */
    public function test_promote_and_rollback_keep_single_default(): void
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create(['type' => ResourceType::Template]);
        [$first, $firstPackage] = $this->makeVersion($resource, '1.0.0', $actor);
        [$second, $secondPackage] = $this->makeVersion($resource, '2.0.0', $actor);
        $this->markTemplateReady($first, $firstPackage);
        $this->markTemplateReady($second, $secondPackage);
        $first->forceFill(['status' => ResourceVersionStatus::Ready, 'is_default' => true])->save();
        $second->forceFill(['status' => ResourceVersionStatus::Ready, 'is_default' => false])->save();
        $token = $this->actorToken($actor);

        $this->withToken($token)
            ->postJson('/api/admin/resource-versions/'.$second->id.'/promote')
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertSame(1, ResourceVersion::query()->where('resource_id', $resource->id)->where('is_default', true)->count());
        $this->assertFalse($first->fresh()->is_default);

        $this->withToken($token)
            ->postJson('/api/admin/resource-versions/'.$first->id.'/rollback')
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertSame(1, ResourceVersion::query()->where('resource_id', $resource->id)->where('is_default', true)->count());
        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $second->id, 'event' => 'version.promoted']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $first->id, 'event' => 'version.rolled_back']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn archive default/download và archive version hợp lệ
     * =====================================================================
     * INPUT: Default version, version có download history, rồi version không retention.
     * OUTPUT: Hai request 422; version cuối chuyển archived và usage còn nguyên.
     * SIDE EFFECT: Ghi status/audit, không detach MediaAsset package.
     * EXCEPTION/TRANSACTION: Resource lock và validation rollback.
     * =====================================================================
     */
    public function test_archive_rejects_default_and_download_retention(): void
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create(['type' => ResourceType::Template]);
        [$default, $defaultPackage] = $this->makeVersion($resource, '1.0.0', $actor);
        [$downloaded, $downloadedPackage] = $this->makeVersion($resource, '2.0.0', $actor);
        $this->markTemplateReady($default, $defaultPackage);
        $this->markTemplateReady($downloaded, $downloadedPackage);
        $default->forceFill(['status' => ResourceVersionStatus::Ready, 'is_default' => true])->save();
        $downloaded->forceFill(['status' => ResourceVersionStatus::Ready])->save();
        $media = $downloadedPackage->getFirstMedia('library');
        DB::table('downloads')->insert([
            'resource_id' => $resource->id,
            'resource_version_id' => $downloaded->id,
            'media_id' => $media->id,
            'status' => 'completed',
            'downloaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $this->actorToken($actor);

        $this->withToken($token)->postJson('/api/admin/resource-versions/'.$default->id.'/archive')->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/resource-versions/'.$downloaded->id.'/archive')->assertUnprocessable();

        DB::table('downloads')->where('resource_version_id', $downloaded->id)->delete();
        $this->withToken($token)
            ->postJson('/api/admin/resource-versions/'.$downloaded->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', ResourceVersionStatus::Archived->value);

        $this->assertDatabaseHas('media_asset_usages', [
            'linkable_id' => $downloaded->id,
            'media_asset_id' => $downloadedPackage->id,
            'field' => MediaAssetField::ResourceVersionPackage->value,
        ]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $downloaded->id, 'event' => 'version.archived']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả processing/template state bounded trong version response
     * =====================================================================
     * INPUT: Version template có snapshot ready và screen đã eager-load.
     * OUTPUT: GET detail có template status/entry/screens, không có preview path.
     * SIDE EFFECT: Chỉ đọc API; không mutate lifecycle.
     * EXCEPTION/TRANSACTION: Permission middleware xử lý auth.
     * =====================================================================
     */
    public function test_resource_version_response_exposes_template_state(): void
    {
        [$version, $package, $actor] = $this->makeVersion();
        $this->markTemplateReady($version, $package);

        $this->withToken($this->actorToken($actor))
            ->getJson('/api/admin/resource-versions/'.$version->id)
            ->assertOk()
            ->assertJsonPath('data.template.processing_status', ResourceVersionTemplateProcessingStatus::Ready->value)
            ->assertJsonPath('data.template.entry_screen_slug', 'home')
            ->assertJsonPath('data.template.screens_count', 1)
            ->assertJsonMissingPath('data.template.preview_path');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo actor có quyền version lifecycle
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: User active có resource_versions.manage/media.attach.
     * SIDE EFFECT: Ghi user/permission test.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function actor(): User
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['resource_versions.manage', 'media.attach']);
        $this->flushPermissionCache();

        return $actor;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo token Sanctum cho actor
     * =====================================================================
     * INPUT: User đã có permission.
     * OUTPUT: plain text admin token.
     * SIDE EFFECT: Ghi personal access token.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function actorToken(User $actor): string
    {
        Auth::forgetGuards();

        return $actor->createToken('tpl-05-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo ResourceVersion template draft và attach package clean
     * =====================================================================
     * INPUT: Resource tùy chọn, version string và actor.
     * OUTPUT: [ResourceVersion, MediaAsset] đã có usage package.
     * SIDE EFFECT: Ghi resource/version/media/usage test.
     * EXCEPTION/TRANSACTION: Usage service validation truyền ra PHPUnit.
     * =====================================================================
     */
    private function makeVersion(?Resource $resource = null, string $versionNumber = '1.0.0', ?User $actor = null): array
    {
        $actor ??= $this->actor();
        $resource ??= Resource::factory()->create(['type' => ResourceType::Template]);
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'version' => $versionNumber,
            'created_by' => $actor->id,
        ]);
        $package = $this->attachPackage($version, $actor);

        return [$version->fresh(), $package, $actor];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo archive private/clean và attach đúng usage field
     * =====================================================================
     * INPUT: ResourceVersion và actor.
     * OUTPUT: MediaAsset archive có checksum SHA-256.
     * SIDE EFFECT: Ghi file Spatie vào fake media_private và usage pivot.
     * EXCEPTION/TRANSACTION: Không gọi upload HTTP/provider.
     * =====================================================================
     */
    private function attachPackage(ResourceVersion $version, User $actor): MediaAsset
    {
        $bytes = 'TPL-05 package '.$version->id.' '.Str::uuid();
        $checksum = hash('sha256', $bytes);
        $package = MediaAsset::factory()->archive()->create([
            'visibility' => MediaAssetVisibility::Private,
            'created_by' => $actor->id,
        ]);
        $package->addMediaFromString($bytes)
            ->usingFileName('template.zip')
            ->withCustomProperties([
                'scan_status' => MediaScanStatus::Clean->value,
                'checksum_sha256' => $checksum,
            ])
            ->toMediaCollection('library', $package->mediaDisk());
        app(MediaAssetUsageService::class)->attach($actor, $version, $package, MediaAssetField::ResourceVersionPackage);

        return $package->fresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo processing-ready snapshot và private artifact synthetic
     * =====================================================================
     * INPUT: Version và package archive clean.
     * OUTPUT: ResourceVersionTemplate ready có entry/screen/artifact.
     * SIDE EFFECT: Ghi template/screen và directory fake private.
     * EXCEPTION/TRANSACTION: Không xử lý ZIP; parser đã được kiểm ở TPL-04.
     * =====================================================================
     */
    private function markTemplateReady(ResourceVersion $version, MediaAsset $package): ResourceVersionTemplate
    {
        $token = (string) Str::uuid();
        $media = $package->getFirstMedia('library');
        $previewPath = 'template-previews/'.$version->id.'/'.$token;
        File::ensureDirectoryExists(Storage::disk('media_private')->path($previewPath));
        Storage::disk('media_private')->put($previewPath.'/index.html', '<!doctype html><title>Home</title>');
        $template = ResourceVersionTemplate::factory()->ready()->create([
            'resource_version_id' => $version->id,
            'source_media_id' => $package->id,
            'package_sha256' => (string) $media->getCustomProperty('checksum_sha256'),
            'processing_token' => $token,
            'source_root' => null,
            'entry_screen_slug' => 'home',
            'manifest_json' => [
                'manifest' => ['schema_version' => 1, 'entry_screen' => 'home'],
                'requires_entry_selection' => false,
                'files' => ['index.html'],
            ],
            'preview_path' => $previewPath,
        ]);
        ResourceVersionTemplateScreen::factory()->create([
            'resource_version_template_id' => $template->id,
            'slug' => 'home',
            'title' => 'Home',
            'source_path' => 'index.html',
            'sort_order' => 0,
        ]);

        return $template->fresh(['screens']);
    }
}
