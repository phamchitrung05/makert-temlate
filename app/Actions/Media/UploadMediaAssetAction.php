<?php

namespace App\Actions\Media;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaConversionStatus;
use App\Enums\MediaScanStatus;
use App\Jobs\Media\ScanMediaAssetJob;
use App\Models\MediaAsset;
use App\Services\Media\ArchiveSecurityScanner;
use App\Services\Media\MediaUploadValidator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Upload an toàn và tạo MediaAsset qua Spatie Media
 * =====================================================================
 *
 * Action lưu upload vào private temporary disk, validate bytes, scan archive,
 * tính checksum rồi mới tạo MediaAsset và copy file vào collection `library`.
 * Archive được preflight scan để file nguy hiểm bị từ chối trước khi tạo bản
 * ghi media; scan/conversion job vẫn chạy lại bất đồng bộ để cập nhật status.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): chạy toàn bộ upload pipeline và dispatch job xử lý
 * - temporaryAbsolutePath(): lấy absolute path local để scan/checksum
 * - cleanupTemporaryFile(): xóa file tạm sau khi attach hoặc thất bại
 * - defaultVisibility(): chọn visibility theo kind/config
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UploadedFile, kind, title, actor id và metadata tùy chọn
 * - OUTPUT: MediaAsset có Media Spatie và custom properties xử lý
 * - SIDE EFFECT: ghi temp/media, tạo DB rows, dispatch scan/conversion job
 * - EXCEPTION/TRANSACTION: validation/security exception rollback metadata và dọn temp
 * =====================================================================
 */
class UploadMediaAssetAction
{
    public function __construct(
        private readonly MediaUploadValidator $validator,
        private readonly ArchiveSecurityScanner $archiveScanner,
        private readonly CalculateMediaChecksumAction $checksumAction,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate, scan, checksum và attach file vào MediaAsset
     * =====================================================================
     *
     * INPUT:
     * - $file: UploadedFile đã qua FormRequest shape validation
     * - $kind/$title/$actorId: metadata asset
     * - $visibility: public/private; null dùng default theo kind
     * - $altText: alt text tùy chọn cho image
     *
     * OUTPUT:
     * - MediaAsset: owner có một media item trong collection library
     *
     * EXCEPTION/TRANSACTION:
     * - MediaSecurityException nếu file không an toàn
     * - Transaction chỉ bao quanh rows MediaAsset/Media; temp luôn được cleanup
     */
    public function handle(
        UploadedFile $file,
        MediaAssetKind $kind,
        string $title,
        int $actorId,
        ?MediaAssetVisibility $visibility = null,
        ?string $altText = null,
    ): MediaAsset {
        $metadata = $this->validator->validate($file, $kind);
        $visibility ??= $this->defaultVisibility($kind);

        if ($kind === MediaAssetKind::Archive && $visibility !== MediaAssetVisibility::Private) {
            throw new \App\Exceptions\MediaSecurityException('Archive/package bắt buộc private.');
        }

        $temporaryDisk = (string) config('media-assets.temporary_disk', 'media_private');
        $temporaryName = Str::uuid()->toString().'.'.$metadata['extension'];
        $temporaryPath = $file->storeAs(
            (string) config('media-assets.temporary_directory', 'media-assets/tmp'),
            $temporaryName,
            $temporaryDisk,
        );
        [$localPath, $copiedLocalPath] = $this->temporaryAbsolutePath($temporaryDisk, $temporaryPath);

        try {
            if ($kind === MediaAssetKind::Archive) {
                $this->archiveScanner->scan($localPath);
            }

            $checksum = $this->checksumAction->handle($localPath);
            $scanStatus = $kind === MediaAssetKind::Archive
                ? MediaScanStatus::Pending->value
                : MediaScanStatus::Clean->value;
            $conversionStatus = $kind === MediaAssetKind::Image
                ? MediaConversionStatus::Pending->value
                : MediaConversionStatus::Ready->value;

            $media = $this->database->transaction(function () use (
                $actorId,
                $altText,
                $checksum,
                $conversionStatus,
                $kind,
                $metadata,
                $scanStatus,
                $title,
                $visibility,
                $localPath,
            ) {
                $asset = MediaAsset::query()->create([
                    'kind' => $kind,
                    'title' => $title,
                    'alt_text' => $altText,
                    'visibility' => $visibility,
                    'created_by' => $actorId,
                ]);

                return $asset->addMedia($localPath)
                    ->usingName($title)
                    ->usingFileName($metadata['original_name'])
                    ->withCustomProperties([
                        'checksum_sha256' => $checksum,
                        'scan_status' => $scanStatus,
                        'conversion_status' => $conversionStatus,
                        'upload_metadata' => [
                            ...$metadata,
                            'uploaded_by' => $actorId,
                        ],
                    ])
                    ->toMediaCollection('library', $asset->mediaDisk());
            });

            if ($kind === MediaAssetKind::Archive) {
                ScanMediaAssetJob::dispatch($media->getKey());
            }

            return $media->model()->firstOrFail();
        } finally {
            $this->cleanupTemporaryFile($temporaryDisk, $temporaryPath, $copiedLocalPath);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đưa temporary file về absolute path local để xử lý
     * =====================================================================
     *
     * INPUT: disk và relative path của temporary file.
     * OUTPUT: absolute path đọc được bởi hash_file/ZipArchive.
     * EXCEPTION/TRANSACTION: RuntimeException nếu disk không đọc được.
     */
    private function temporaryAbsolutePath(string $diskName, string $path): array
    {
        $disk = Storage::disk($diskName);
        $absolutePath = method_exists($disk, 'path') ? $disk->path($path) : null;

        if (is_string($absolutePath) && is_file($absolutePath)) {
            return [$absolutePath, null];
        }

        $localPath = tempnam(sys_get_temp_dir(), 'media-upload-');
        if ($localPath === false || file_put_contents($localPath, $disk->get($path)) === false) {
            throw new \RuntimeException('Không thể tạo bản sao local của file upload.');
        }

        return [$localPath, $localPath];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa temporary path sau pipeline
     * =====================================================================
     *
     * INPUT: disk/path temporary và bản sao local nếu có.
     * OUTPUT: Không trả giá trị; filesystem được dọn best-effort.
     */
    private function cleanupTemporaryFile(string $diskName, string $path, ?string $localPath): void
    {
        Storage::disk($diskName)->delete($path);
        if ($localPath !== null && is_file($localPath)) {
            @unlink($localPath);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn visibility mặc định theo kind
     * =====================================================================
     *
     * INPUT: $kind enum MediaAssetKind.
     * OUTPUT: MediaAssetVisibility theo config.
     */
    private function defaultVisibility(MediaAssetKind $kind): MediaAssetVisibility
    {
        return MediaAssetVisibility::tryFrom(
            (string) config('media-assets.kinds.'.$kind->value.'.default_visibility', 'private'),
        ) ?? MediaAssetVisibility::Private;
    }
}
