<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Jobs\Resources\ProcessTemplateVersionJob;
use App\Models\MediaAsset;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\Resources\TemplateVersionProcessingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm queue và artifact lifecycle Resource Template TPL-04
 * =====================================================================
 *
 * Test kiểm enqueue after commit, tracker dùng chung, processing fixture
 * normalized, artifact private, idempotency, stale token và cancellation.
 * Không gọi provider hoặc mở public disk cho package/template artifact.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): database, queue và storage cô lập.
 * - test_enqueue_registers_tracker_and_dispatches_after_commit(): kiểm queue.
 * - test_job_processes_fixture_and_is_idempotent(): kiểm ready/artifact/retry.
 * - test_stale_token_cannot_overwrite_new_processing_run(): kiểm worker cũ.
 * - test_template_task_is_visible_in_shared_queue_and_can_cancel(): kiểm popup API contract.
 * - actor()/actorToken(): tạo admin permission.
 * - versionWithPackage(): tạo template version và attach archive fixture.
 * - archiveFromFixture(): thêm ZIP clean vào MediaAsset private.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : normalized ResourceTemplate fixture, ResourceVersion và queue service.
 * - OUTPUT: assertions lifecycle DB, private artifact và AiTaskRun projection.
 * - SIDE EFFECT: SQLite/storage/queue test cô lập; không sửa fixture gốc.
 * - EXCEPTION/TRANSACTION: lỗi queue/parser truyền ra PHPUnit; test không nuốt lỗi.
 * =====================================================================
 */
final class ResourceTemplateProcessingTest extends TestCase
{
    use UsesIsolatedDatabase;

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
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Enqueue template tạo tracker và job sau commit
     * =====================================================================
     * INPUT: version template draft có package private/clean.
     * OUTPUT: tracker queued và ProcessTemplateVersionJob với token ổn định.
     * SIDE EFFECT: ghi template/ai_task_runs và queue fake.
     * EXCEPTION/TRANSACTION: transaction enqueue commit trước dispatch.
     * =====================================================================
     */
    public function test_enqueue_registers_tracker_and_dispatches_after_commit(): void
    {
        [$version, $package] = $this->versionWithPackage();

        $task = app(TemplateVersionProcessingService::class)->enqueue($version);
        $template = $version->fresh()->template;

        $this->assertSame('resource_template_processing', $task->task_type);
        $this->assertSame('resource_template', $task->source);
        $this->assertSame('queued', $task->status);
        $this->assertSame(ResourceVersionTemplateProcessingStatus::Pending, $template->processing_status);
        $this->assertSame($package->id, $template->source_media_id);
        $this->assertNotEmpty($template->processing_token);

        Queue::assertPushed(ProcessTemplateVersionJob::class, function (ProcessTemplateVersionJob $job) use ($version, $package, $template): bool {
            return $job->versionId === $version->id
                && $job->sourceMediaId === $package->id
                && $job->processingToken === $template->processing_token
                && hash_equals($job->checksum, (string) $template->package_sha256);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Process normalized fixture và chạy lại không tạo artifact mới
     * =====================================================================
     * INPUT: job identity của version vừa enqueue.
     * OUTPUT: template ready, 18 screens, pointer private và tracker ready.
     * SIDE EFFECT: extract/move artifact vào media_private.
     * EXCEPTION/TRANSACTION: Không DB transaction ngoài service lifecycle.
     * =====================================================================
     */
    public function test_job_processes_fixture_and_is_idempotent(): void
    {
        [$version, $package] = $this->versionWithPackage();
        $service = app(TemplateVersionProcessingService::class);
        $task = $service->enqueue($version);
        $template = $version->fresh()->template;

        $service->process($version->id, $package->id, $template->package_sha256, $template->processing_token, 'tpl-test-job');

        $ready = $template->fresh(['screens']);
        $this->assertSame(ResourceVersionTemplateProcessingStatus::Ready, $ready->processing_status);
        $this->assertCount(18, $ready->screens);
        $this->assertNotNull($ready->preview_path);
        $this->assertTrue(is_dir(Storage::disk('media_private')->path($ready->preview_path)));
        $this->assertSame('ready', $task->fresh()->status);
        $this->assertSame(18, count((array) data_get($ready->manifest_json, 'manifest.screens')));

        $service->process($version->id, $package->id, $ready->package_sha256, $ready->processing_token, 'tpl-test-job-retry');

        $this->assertSame($ready->preview_path, $ready->fresh()->preview_path);
        $this->assertDatabaseCount('resource_version_template_screens', 18);
        $this->assertDatabaseCount('ai_task_runs', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Worker token cũ không overwrite run mới
     * =====================================================================
     * INPUT: token/checksum cũ sau khi template đã nhận token mới.
     * OUTPUT: process cũ bỏ qua, template vẫn giữ token mới.
     * SIDE EFFECT: chỉ đọc/lock metadata; không tạo screens từ run cũ.
     * EXCEPTION/TRANSACTION: Không ném lỗi cho stale worker.
     * =====================================================================
     */
    public function test_stale_token_cannot_overwrite_new_processing_run(): void
    {
        [$version, $package] = $this->versionWithPackage();
        $service = app(TemplateVersionProcessingService::class);
        $service->enqueue($version);
        $old = $version->fresh()->template;
        $old->forceFill(['processing_status' => ResourceVersionTemplateProcessingStatus::Failed])->save();
        $service->enqueue($version->fresh());
        $new = $version->fresh()->template;

        $this->assertNotSame($old->processing_token, $new->processing_token);
        $service->process($version->id, $package->id, $old->package_sha256, $old->processing_token, 'stale-job');

        $this->assertSame($new->processing_token, $version->fresh()->template->processing_token);
        $this->assertSame(ResourceVersionTemplateProcessingStatus::Pending, $version->fresh()->template->processing_status);
        $this->assertDatabaseCount('resource_version_template_screens', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hiển thị task template trong tracker API và hủy an toàn
     * =====================================================================
     * INPUT: task queued thuộc actor có resource_versions.manage.
     * OUTPUT: API trả task_type/resource_version_id; cancel chuyển cancelled.
     * SIDE EFFECT: cập nhật template và dọn artifact private.
     * EXCEPTION/TRANSACTION: owner/quyền do tracker service scope.
     * =====================================================================
     */
    public function test_template_task_is_visible_in_shared_queue_and_can_cancel(): void
    {
        [$version] = $this->versionWithPackage();
        $task = app(TemplateVersionProcessingService::class)->enqueue($version);
        $token = (string) $version->fresh()->template->processing_token;
        $processingPath = Storage::disk('media_private')->path(config('media-assets.template_processing_directory').'/'.$version->id.'/'.$token);
        $artifactPath = Storage::disk('media_private')->path(config('media-assets.template_artifact_directory').'/'.$version->id.'/'.$token);
        File::ensureDirectoryExists($processingPath);
        File::ensureDirectoryExists($artifactPath);

        $this->withToken($this->actorToken())->getJson('/api/admin/ai/tasks')->assertOk()
            ->assertJsonPath('data.0.task_type', 'resource_template_processing')
            ->assertJsonPath('data.0.source', 'resource_template')
            ->assertJsonPath('data.0.resource_version_id', $version->id);

        $this->withToken($this->actorToken())->postJson('/api/admin/ai/tasks/'.$task->id.'/cancel')
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(ResourceVersionTemplateProcessingStatus::Failed, $version->fresh()->template->processing_status);
        $this->assertFalse(is_dir($processingPath));
        $this->assertFalse(is_dir($artifactPath));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo actor test có quyền dùng resource/template queue
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: User active có media/version/resource permissions.
     * SIDE EFFECT: Ghi user và permission trong DB test.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function actor(): User
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['media.attach', 'resource_versions.manage', 'resources.create']);
        $this->flushPermissionCache();

        return $actor;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo resource/version template và attach package fixture
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: [ResourceVersion, MediaAsset archive].
     * SIDE EFFECT: Ghi usage/media metadata và file vào fake private disk.
     * EXCEPTION/TRANSACTION: Usage service ném ValidationException nếu contract sai.
     * =====================================================================
     */
    private function versionWithPackage(): array
    {
        $actor = $this->actor();
        $resource = Resource::factory()->create(['type' => ResourceType::Template]);
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'created_by' => $actor->id,
        ]);
        $package = $this->archiveFromFixture();
        app(MediaAssetUsageService::class)->attach($actor, $version, $package, MediaAssetField::ResourceVersionPackage);

        return [$version->fresh(), $package];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo archive asset private từ normalized fixture ZIP
     * =====================================================================
     * INPUT: tests/Fixtures/ResourceTemplate/normalized/package.zip.
     * OUTPUT: MediaAsset archive có scan clean/checksum.
     * SIDE EFFECT: copy fixture vào fake media_private disk.
     * EXCEPTION/TRANSACTION: Không gọi upload HTTP/provider.
     * =====================================================================
     */
    private function archiveFromFixture(): MediaAsset
    {
        $path = base_path('tests/Fixtures/ResourceTemplate/normalized/package.zip');
        $asset = MediaAsset::factory()->archive()->create([
            'visibility' => MediaAssetVisibility::Private,
            'created_by' => User::query()->latest('id')->value('id'),
        ]);
        // Spatie's addMedia() moves its source file; add the bytes so the
        // committed fixture remains available for the next isolated test.
        $asset->addMediaFromString(file_get_contents($path))
            ->usingFileName('template.zip')
            ->withCustomProperties([
                'scan_status' => MediaScanStatus::Clean->value,
                'checksum_sha256' => hash_file('sha256', $path),
            ])
            ->toMediaCollection('library', $asset->mediaDisk());

        return $asset->fresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo token Sanctum của actor cuối cùng
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: plain text admin token.
     * SIDE EFFECT: Ghi personal access token.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function actorToken(): string
    {
        Auth::forgetGuards();

        return User::query()->latest('id')->firstOrFail()->createToken('template-queue', ['admin'])->plainTextToken;
    }
}
