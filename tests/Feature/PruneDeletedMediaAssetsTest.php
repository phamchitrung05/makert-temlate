<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Post;
use App\Models\User;
use App\Services\Settings\ProjectScheduleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm dọn media hết retention trên storage giả lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): cô lập schema và disk public/private.
 * - test_prunes_originals_and_conversions_on_both_disks(): kiểm file bị dọn.
 * - test_dry_run_and_retention_preserve_recent_and_active_assets(): kiểm thời hạn.
 * - test_retained_usage_inline_content_and_ai_snapshot_keep_files(): kiểm tham chiếu.
 * - test_invalid_days_does_not_delete_and_prune_is_scheduled_weekly(): kiểm cấu hình.
 * - asset(): dựng file gốc và conversion không chạy xử lý ảnh thật.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): options Artisan/fixture -> assertion DB/file giả lập.
 * =====================================================================
 */
final class PruneDeletedMediaAssetsTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: schema và hai disk giả lập độc lập. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
    }

    /** Input: schema test. Output: giải phóng database. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: media public/private đã xóa 31 ngày. Output: file gốc, thumb và DB đều được dọn. */
    public function test_prunes_originals_and_conversions_on_both_disks(): void
    {
        $fixtures = [$this->asset('public'), $this->asset('private')];

        $this->artisan('media:prune-deleted')->expectsOutput('Đã dọn 2 media hết thời hạn giữ lại.')->assertSuccessful();

        foreach ($fixtures as [$asset, $media]) {
            $this->assertNull(MediaAsset::withTrashed()->find($asset->id));
            $this->assertDatabaseMissing('media', ['id' => $media->id]);
            Storage::disk($media->disk)->assertMissing($media->getPathRelativeToRoot());
            Storage::disk($media->disk)->assertMissing($media->getPathRelativeToRoot('thumb'));
        }
        $this->artisan('media:prune-deleted')->expectsOutput('Đã dọn 0 media hết thời hạn giữ lại.')->assertSuccessful();
    }

    /** Input: old/recent/active media và dry-run. Output: chỉ old bị xóa khi thực thi thật. */
    public function test_dry_run_and_retention_preserve_recent_and_active_assets(): void
    {
        [$old] = $this->asset();
        $recent = $this->asset('public', 29);
        $active = $this->asset('private', null);
        $this->artisan('media:prune-deleted', ['--dry-run' => true])
            ->expectsOutput('Có 1 media đủ điều kiện dọn.')->assertSuccessful();
        $this->assertNotNull(MediaAsset::withTrashed()->find($old->id));

        $this->artisan('media:prune-deleted')->assertSuccessful();
        $this->assertNull(MediaAsset::withTrashed()->find($old->id));
        foreach ([$recent, $active] as [$asset, $media]) {
            $this->assertNotNull(MediaAsset::withTrashed()->find($asset->id));
            Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());
        }
    }

    /** Input: media xóa mềm còn usage, HTML hoặc snapshot AI. Output: không xóa các file còn giữ. */
    public function test_retained_usage_inline_content_and_ai_snapshot_keep_files(): void
    {
        [$used, $usedMedia] = $this->asset();
        [$inline, $inlineMedia] = $this->asset();
        [$snapshot, $snapshotMedia] = $this->asset();
        $post = Post::factory()->create([
            'content' => '<img src="'.$inlineMedia->getUrl().'" alt="Inline">',
        ]);
        MediaAssetUsage::query()->create([
            'media_asset_id' => $used->id, 'linkable_type' => 'post', 'linkable_id' => $post->id,
            'field' => MediaAssetField::PostThumbnail, 'sort_order' => 0,
        ]);
        AiImport::query()->create([
            'created_by' => User::factory()->create()->id, 'source_url' => 'https://example.test/article',
            'source_hash' => hash('sha256', 'retained-media'), 'status' => 'ready', 'expires_at' => now()->addDay(),
            'result_json' => ['draft' => ['thumbnail' => ['media_asset_id' => $snapshot->id]]],
        ]);

        $this->artisan('media:prune-deleted')->expectsOutput('Đã dọn 0 media hết thời hạn giữ lại.')->assertSuccessful();

        foreach ([[$used, $usedMedia], [$inline, $inlineMedia], [$snapshot, $snapshotMedia]] as [$asset, $media]) {
            $this->assertNotNull(MediaAsset::withTrashed()->find($asset->id));
            Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());
        }
    }

    /** Input: số ngày không hợp lệ và schedule đăng ký hai lần. Output: không xóa và một lịch hằng tuần. */
    public function test_invalid_days_does_not_delete_and_prune_is_scheduled_weekly(): void
    {
        [$asset] = $this->asset();
        foreach (['0', '-1', 'abc'] as $days) {
            $this->artisan('media:prune-deleted', ['--days' => $days])->assertExitCode(2);
        }
        $this->assertNotNull(MediaAsset::withTrashed()->find($asset->id));
        $schedule = app(Schedule::class);
        $registry = app(ProjectScheduleRegistry::class);
        $registry->register($schedule);
        $registry->register($schedule);
        $events = collect($schedule->events())->filter(fn ($event) => str_contains($event->command ?? '', 'media:prune-deleted'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * 0', $events->first()->expression);
    }

    /** Input: visibility và số ngày đã xóa (null để giữ active). Output: asset/media có file gốc và thumb giả. */
    private function asset(string $visibility = 'public', ?int $deletedDays = 31): array
    {
        $asset = MediaAsset::factory()->image()->create(['visibility' => $visibility]);
        $media = $asset->media()->create([
            'collection_name' => 'library', 'name' => 'Photo', 'file_name' => 'photo.jpg',
            'disk' => $asset->mediaDisk(), 'conversions_disk' => $asset->mediaDisk(),
            'mime_type' => 'image/jpeg', 'size' => 100, 'manipulations' => [],
            'custom_properties' => [], 'generated_conversions' => ['thumb' => true], 'responsive_images' => [],
        ]);
        Storage::disk($media->disk)->put($media->getPathRelativeToRoot(), 'original fixture');
        Storage::disk($media->disk)->put($media->getPathRelativeToRoot('thumb'), 'thumb fixture');
        if ($deletedDays !== null) {
            $asset->delete();
            $asset->forceFill(['deleted_at' => now()->subDays($deletedDays)])->save();
        }

        return [$asset, $media];
    }
}
