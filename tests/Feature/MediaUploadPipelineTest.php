<?php

namespace Tests\Feature;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\MediaAssetKind;
use App\Enums\MediaConversionStatus;
use App\Enums\MediaScanStatus;
use App\Exceptions\MediaSecurityException;
use App\Jobs\Media\ProcessMediaConversionsJob;
use App\Jobs\Media\ScanMediaAssetJob;
use App\Models\MediaAsset;
use App\Services\Media\ArchiveSecurityScanner;
use App\Services\Media\MediaUploadValidator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;
use ZipArchive;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử validation, checksum, archive scan và upload queue
 * =====================================================================
 *
 * Test xác nhận file hợp lệ đi qua temporary/private disk trước khi attach,
 * file nguy hiểm bị từ chối, archive traversal/ZIP bomb bị chặn và custom
 * properties giữ checksum/scan/conversion status.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate database cô lập và fake storage/queue
 * - tearDown(): rollback database cô lập
 * - pngUpload(): tạo UploadedFile PNG tối thiểu hợp lệ
 * - zipUpload(): tạo UploadedFile ZIP từ entry tùy chọn
 * - zipUploadEntries(): tạo ZIP nhiều entry để kiểm tra giới hạn
 * - assertSecurityException(): xác nhận security exception của callback
 * - test_validator_accepts_real_image_and_rejects_fake_mime(): kiểm tra MIME/content
 * - test_archive_scanner_rejects_traversal_and_accepts_safe_zip(): kiểm tra archive
 * - test_validator_rejects_dangerous_filename_and_oversized_upload(): kiểm tra filename/size
 * - test_archive_scanner_rejects_symlink_entry(): kiểm tra symlink
 * - test_archive_scanner_rejects_configured_limits(): kiểm tra giới hạn archive
 * - test_upload_action_stores_checksum_status_and_dispatches_jobs(): kiểm tra pipeline upload
 * - test_archive_upload_is_private_and_preflight_rejects_dangerous_entry(): kiểm tra archive private/security
 * - test_queue_failures_persist_error_status_for_retry(): kiểm tra queue failure
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UploadedFile giả lập và MediaAsset upload action
 * - OUTPUT: assert rejection, media custom properties, disk và queued jobs
 * =====================================================================
 */
class MediaUploadPipelineTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database, queue và filesystem cô lập
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite, seed permission và fake media disks/queue
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-assets.temporary_disk', 'media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database cô lập sau pipeline test
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
     * CHỨC NĂNG: Tạo UploadedFile PNG 1x1 có bytes hợp lệ
     * =====================================================================
     *
     * OUTPUT:
     * - UploadedFile image/png dùng cho validator/action
     */
    private function pngUpload(string $name = 'cover.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-test-png-');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ));

        return new UploadedFile($path, $name, 'image/png', UPLOAD_ERR_OK, true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo UploadedFile ZIP với entry chỉ định
     * =====================================================================
     *
     * INPUT: $entryName và nội dung entry.
     * OUTPUT: UploadedFile application/zip dùng cho scanner/action
     */
    private function zipUpload(string $entryName, string $content = 'source'): UploadedFile
    {
        return $this->zipUploadEntries([$entryName => $content]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo ZIP từ nhiều entry để kiểm tra archive policy
     * =====================================================================
     *
     * INPUT: Map tên entry => nội dung entry cần ghi vào ZIP.
     * OUTPUT: UploadedFile application/zip dùng cho scanner.
     */
    private function zipUploadEntries(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-test-zip-');
        $zip = new ZipArchive;
        $zip->open($path);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', UPLOAD_ERR_OK, true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận callback bị chặn bởi security exception
     * =====================================================================
     *
     * INPUT: Callback thực hiện upload hoặc scan không an toàn.
     * OUTPUT: Không trả giá trị; test fail nếu callback không ném exception.
     */
    private function assertSecurityException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Security callback phải ném MediaSecurityException.');
        } catch (MediaSecurityException) {
            $this->assertTrue(true);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra validator phân biệt MIME giả và content thật
     * =====================================================================
     *
     * OUTPUT:
     * - PNG thật được nhận; bytes text giả image MIME bị từ chối
     */
    public function test_validator_accepts_real_image_and_rejects_fake_mime(): void
    {
        $validator = app(MediaUploadValidator::class);
        $metadata = $validator->validate($this->pngUpload(), MediaAssetKind::Image);

        $this->assertSame('image/png', $metadata['mime_type']);
        $this->assertSame('png', $metadata['extension']);

        $fakePath = tempnam(sys_get_temp_dir(), 'media-test-fake-');
        file_put_contents($fakePath, 'not an image');

        $this->expectException(MediaSecurityException::class);
        $validator->validate(
            new UploadedFile($fakePath, 'fake.png', 'image/png', UPLOAD_ERR_OK, true),
            MediaAssetKind::Image,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra archive scanner chặn traversal và nhận ZIP sạch
     * =====================================================================
     *
     * OUTPUT:
     * - ZIP safe trả thống kê; entry ../ bị MediaSecurityException
     */
    public function test_archive_scanner_rejects_traversal_and_accepts_safe_zip(): void
    {
        $scanner = app(ArchiveSecurityScanner::class);
        $safe = $this->zipUpload('source/index.php', 'source code');

        // PHP bên trong package không bị block như upload executable; package
        // chỉ cấm extension nằm trong policy blocklist nếu được cấu hình.
        config()->set('media-assets.blocked_extensions', ['exe', 'dll']);
        $safeStats = $scanner->scan($safe->getRealPath());
        $this->assertSame(1, $safeStats['entries']);

        $unsafe = $this->zipUpload('../escape.txt');
        $this->expectException(MediaSecurityException::class);
        $scanner->scan($unsafe->getRealPath());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối filename nguy hiểm và upload vượt kích thước
     * =====================================================================
     *
     * INPUT: UploadedFile có extension executable hoặc vượt max_size_kb.
     * OUTPUT: MediaSecurityException trước khi tạo MediaAsset.
     */
    public function test_validator_rejects_dangerous_filename_and_oversized_upload(): void
    {
        $validator = app(MediaUploadValidator::class);

        $this->assertSecurityException(
            fn (): array => $validator->validate(
                $this->pngUpload('cover.php.png'),
                MediaAssetKind::Image,
            ),
        );

        config()->set('media-assets.kinds.image.max_size_kb', 1);
        $path = tempnam(sys_get_temp_dir(), 'media-test-large-');
        file_put_contents($path, str_repeat('x', 2048));

        $this->assertSecurityException(
            fn (): array => $validator->validate(
                new UploadedFile($path, 'cover.png', 'image/png', UPLOAD_ERR_OK, true),
                MediaAssetKind::Image,
            ),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối symlink trong archive
     * =====================================================================
     *
     * INPUT: ZIP entry có Unix external attributes đánh dấu symlink.
     * OUTPUT: MediaSecurityException khi symlink không được phép.
     */
    public function test_archive_scanner_rejects_symlink_entry(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'media-test-symlink-');
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $zip->addFromString('link', 'target');

        if (! method_exists($zip, 'setExternalAttributesName')) {
            $zip->close();
            $this->markTestSkipped('PHP ZipArchive không hỗ trợ external attributes.');
        }

        $set = $zip->setExternalAttributesName(
            'link',
            ZipArchive::OPSYS_UNIX,
            (0120777 << 16),
        );
        $zip->close();

        if ($set !== true) {
            $this->markTestSkipped('Không thể tạo symlink metadata trong ZIP trên môi trường test.');
        }

        $this->assertSecurityException(
            fn (): array => app(ArchiveSecurityScanner::class)->scan($zipPath),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối archive vượt các giới hạn cấu hình
     * =====================================================================
     *
     * INPUT: ZIP vượt số entry, dung lượng giải nén hoặc compression ratio.
     * OUTPUT: MediaSecurityException cho từng policy violation.
     */
    public function test_archive_scanner_rejects_configured_limits(): void
    {
        $scanner = app(ArchiveSecurityScanner::class);

        config()->set('media-assets.archive.max_entries', 1);
        $this->assertSecurityException(
            fn (): array => $scanner->scan(
                $this->zipUploadEntries([
                    'one.txt' => 'one',
                    'two.txt' => 'two',
                ])->getRealPath(),
            ),
        );

        config()->set('media-assets.archive.max_entries', 10000);
        config()->set('media-assets.archive.max_uncompressed_bytes', 1);
        $this->assertSecurityException(
            fn (): array => $scanner->scan(
                $this->zipUpload('large.txt', 'larger than one byte')->getRealPath(),
            ),
        );

        config()->set('media-assets.archive.max_uncompressed_bytes', 1073741824);
        config()->set('media-assets.archive.max_compression_ratio', 1);
        $this->assertSecurityException(
            fn (): array => $scanner->scan(
                $this->zipUpload('repetitive.txt', str_repeat('a', 10000))->getRealPath(),
            ),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra upload image lưu checksum/status và dispatch conversion
     * =====================================================================
     *
     * OUTPUT:
     * - MediaAsset image ở public disk, checksum SHA-256 và conversion pending
     * - ProcessMediaConversionsJob được dispatch
     */
    public function test_upload_action_stores_checksum_status_and_dispatches_jobs(): void
    {
        $actor = \App\Models\User::factory()->create(['status' => 'active']);
        $asset = app(UploadMediaAssetAction::class)->handle(
            $this->pngUpload(),
            MediaAssetKind::Image,
            'Test cover',
            $actor->id,
        );

        $media = $asset->getFirstMedia('library');
        $this->assertNotNull($media);
        $this->assertSame('media_public', $media->disk);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $media->getCustomProperty('checksum_sha256'));
        $this->assertSame(MediaScanStatus::Clean->value, $media->getCustomProperty('scan_status'));
        $this->assertSame(MediaConversionStatus::Pending->value, $media->getCustomProperty('conversion_status'));
        Queue::assertPushed(ProcessMediaConversionsJob::class, fn (ProcessMediaConversionsJob $job): bool => $job->mediaId() === $media->id);
        Queue::assertNotPushed(ScanMediaAssetJob::class);
        $this->assertDatabaseMissing('media_assets', ['title' => '']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra archive private và preflight từ chối entry nguy hiểm
     * =====================================================================
     *
     * OUTPUT:
     * - ZIP sạch tạo media private và scan job
     * - ZIP có executable entry bị từ chối trước khi tạo MediaAsset
     */
    public function test_archive_upload_is_private_and_preflight_rejects_dangerous_entry(): void
    {
        $actor = \App\Models\User::factory()->create(['status' => 'active']);
        $asset = app(UploadMediaAssetAction::class)->handle(
            $this->zipUpload('source/readme.txt'),
            MediaAssetKind::Archive,
            'Test package',
            $actor->id,
        );

        $media = $asset->getFirstMedia('library');
        $this->assertSame('media_private', $media->disk);
        $this->assertSame(MediaScanStatus::Pending->value, $media->getCustomProperty('scan_status'));
        Queue::assertPushed(ScanMediaAssetJob::class, fn (ScanMediaAssetJob $job): bool => $job->mediaId === $media->id);

        (new ScanMediaAssetJob($media->id))->handle(app(ArchiveSecurityScanner::class));
        $this->assertSame(
            MediaScanStatus::Clean->value,
            $asset->fresh()->getFirstMedia('library')->getCustomProperty('scan_status'),
        );

        try {
            app(UploadMediaAssetAction::class)->handle(
                $this->zipUpload('payload.exe'),
                MediaAssetKind::Archive,
                'Dangerous package',
                $actor->id,
            );
            $this->fail('Archive có executable phải bị từ chối.');
        } catch (MediaSecurityException $exception) {
            $this->assertSame(0, MediaAsset::query()->where('title', 'Dangerous package')->count());
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu error status khi queue job gặp lỗi kỹ thuật
     * =====================================================================
     *
     * INPUT: Archive scanner và conversion manipulator ném RuntimeException.
     * OUTPUT: scan_status=error và conversion_status=failed để retry.
     */
    public function test_queue_failures_persist_error_status_for_retry(): void
    {
        $actor = \App\Models\User::factory()->create(['status' => 'active']);
        $archive = app(UploadMediaAssetAction::class)->handle(
            $this->zipUpload('source/readme.txt'),
            MediaAssetKind::Archive,
            'Queue failure package',
            $actor->id,
        );
        $archiveMedia = $archive->getFirstMedia('library');
        $scanner = \Mockery::mock(ArchiveSecurityScanner::class);
        $scanner->shouldReceive('scan')
            ->once()
            ->andThrow(new \RuntimeException('scanner unavailable'));

        try {
            (new ScanMediaAssetJob($archiveMedia->getKey()))->handle($scanner);
            $this->fail('Scanner error phải được ném lại để queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('scanner unavailable', $exception->getMessage());
        }

        $this->assertSame(
            MediaScanStatus::Error->value,
            $archive->fresh()->getFirstMedia('library')->getCustomProperty('scan_status'),
        );

        $image = app(UploadMediaAssetAction::class)->handle(
            $this->pngUpload(),
            MediaAssetKind::Image,
            'Queue failure image',
            $actor->id,
        );
        $imageMedia = $image->getFirstMedia('library');
        $manipulator = \Mockery::mock(FileManipulator::class);
        $manipulator->shouldReceive('performConversions')
            ->once()
            ->andThrow(new \RuntimeException('conversion unavailable'));

        try {
            (new ProcessMediaConversionsJob($imageMedia->getKey()))->handle($manipulator);
            $this->fail('Conversion error phải được ném lại để queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('conversion unavailable', $exception->getMessage());
        }

        $this->assertSame(
            MediaConversionStatus::Failed->value,
            $image->fresh()->getFirstMedia('library')->getCustomProperty('conversion_status'),
        );
    }
}
