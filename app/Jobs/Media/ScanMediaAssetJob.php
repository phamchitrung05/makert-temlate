<?php

namespace App\Jobs\Media;

use App\Enums\MediaScanStatus;
use App\Exceptions\MediaSecurityException;
use App\Models\MediaAsset;
use App\Services\Media\ArchiveSecurityScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Scan lại archive MediaAsset trong queue
 * =====================================================================
 *
 * Job xác nhận archive đã attach vào private disk vẫn sạch sau upload. File vi
 * phạm policy chuyển `rejected` và không retry; lỗi kỹ thuật chuyển `error`,
 * ném exception để queue retry theo backoff.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận media id cần scan
 * - handle(): chạy archive scanner và cập nhật scan_status
 * - failed(): ghi error status sau khi hết retry
 * - markStatus(): lưu status custom property
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : media id của archive trong bảng Spatie media
 * - OUTPUT: scan_status clean/rejected/error trong custom properties
 * - SIDE EFFECT: đọc private file, cập nhật bảng media
 * =====================================================================
 */
class ScanMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $mediaId) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Scan archive và cập nhật status
     * =====================================================================
     *
     * INPUT: ArchiveSecurityScanner từ container.
     * OUTPUT: Không trả giá trị; media được đánh dấu clean hoặc rejected.
     * EXCEPTION/TRANSACTION: lỗi kỹ thuật được ném để queue retry.
     */
    public function handle(ArchiveSecurityScanner $scanner): void
    {
        $media = Media::query()->findOrFail($this->mediaId);
        $this->markStatus($media, MediaScanStatus::Pending);

        try {
            $scanner->scan($media->getPath());
            $this->markStatus($media, MediaScanStatus::Clean);
        } catch (MediaSecurityException $exception) {
            $this->markStatus($media, MediaScanStatus::Rejected);
        } catch (Throwable $exception) {
            $this->markStatus($media, MediaScanStatus::Error);
            throw $exception;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đánh dấu archive lỗi sau khi queue hết retry
     * =====================================================================
     *
     * INPUT: Throwable từ queue worker.
     * OUTPUT: Không trả giá trị; status giữ error để API retry xử lý.
     */
    public function failed(Throwable $exception): void
    {
        $media = Media::query()->find($this->mediaId);
        if ($media !== null) {
            $this->markStatus($media, MediaScanStatus::Error);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi scan status vào custom properties
     * =====================================================================
     *
     * INPUT: Media và MediaScanStatus cần lưu.
     * OUTPUT: Không trả giá trị; media row được cập nhật.
     */
    private function markStatus(Media $media, MediaScanStatus $status): void
    {
        $media->setCustomProperty('scan_status', $status->value)->save();
    }
}
