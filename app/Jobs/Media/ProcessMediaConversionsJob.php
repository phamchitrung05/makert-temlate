<?php

namespace App\Jobs\Media;

use App\Enums\MediaConversionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chạy conversion ảnh và ghi trạng thái queue
 * =====================================================================
 *
 * Job dùng FileManipulator của Spatie nhưng tự điều phối qua queue của ứng
 * dụng để custom property `conversion_status` phản ánh pending/processing/
 * ready/failed. Job được Spatie FileManipulator dispatch với ConversionCollection;
 * mode nhận media id vẫn được giữ để retry thủ công ở task API.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận ConversionCollection + Media của Spatie hoặc media id retry
 * - mediaId(): trả id media cho cả hai mode khởi tạo
 * - handle(): chạy conversions còn thiếu và đánh dấu ready
 * - failed(): đánh dấu failed sau khi hết retry
 * - markStatus(): lưu conversion status
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ConversionCollection/Media từ Spatie hoặc media id image
 * - OUTPUT: conversion files và conversion_status trong custom properties
 * - SIDE EFFECT: ghi derived files lên conversion disk
 * =====================================================================
 */
class ProcessMediaConversionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly ConversionCollection|int $conversionsOrMediaId,
        public readonly ?Media $queuedMedia = null,
        public readonly bool $onlyMissing = false,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy media id của job ở cả mode Spatie và mode retry
     * =====================================================================
     *
     * OUTPUT:
     * - int: id media được conversion
     */
    public function mediaId(): int
    {
        if ($this->queuedMedia !== null) {
            return (int) $this->queuedMedia->getKey();
        }

        if ($this->conversionsOrMediaId instanceof ConversionCollection) {
            throw new \LogicException('ConversionCollection job thiếu media.');
        }

        return $this->conversionsOrMediaId;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy conversion ảnh còn thiếu
     * =====================================================================
     *
     * INPUT: FileManipulator từ container.
     * OUTPUT: Không trả giá trị; media được đánh dấu ready khi thành công.
     * EXCEPTION/TRANSACTION: lỗi conversion được đánh dấu failed và ném lại để retry.
     */
    public function handle(FileManipulator $fileManipulator): void
    {
        $media = $this->queuedMedia ?? Media::query()->findOrFail($this->mediaId());
        $this->markStatus($media, MediaConversionStatus::Processing);

        try {
            $conversions = $this->conversionsOrMediaId instanceof ConversionCollection
                ? $this->conversionsOrMediaId->getConversions($media->collection_name)
                : ConversionCollection::createForMedia($media)->getConversions($media->collection_name);

            $fileManipulator->performConversions($conversions, $media, $this->onlyMissing);
            $this->markStatus($media, MediaConversionStatus::Ready);
        } catch (Throwable $exception) {
            $this->markStatus($media, MediaConversionStatus::Failed);
            throw $exception;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đánh dấu conversion failed sau khi queue hết retry
     * =====================================================================
     *
     * INPUT: Throwable từ queue worker.
     * OUTPUT: Không trả giá trị; status giữ failed để API retry xử lý.
     */
    public function failed(Throwable $exception): void
    {
        $media = Media::query()->find($this->mediaId());
        if ($media !== null) {
            $this->markStatus($media, MediaConversionStatus::Failed);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi conversion status vào custom properties
     * =====================================================================
     *
     * INPUT: Media và MediaConversionStatus cần lưu.
     * OUTPUT: Không trả giá trị; media row được cập nhật.
     */
    private function markStatus(Media $media, MediaConversionStatus $status): void
    {
        $media->setCustomProperty('conversion_status', $status->value)->save();
    }
}
