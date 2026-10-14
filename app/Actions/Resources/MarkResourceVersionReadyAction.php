<?php

namespace App\Actions\Resources;

use App\Enums\ResourceVersionStatus;
use App\Models\ResourceVersion;
use App\Services\ResourceVersionPackageGuard;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển Resource Version sang ready sau khi kiểm tra package
 * =====================================================================
 *
 * Package archive phải private và security scan clean trước khi version được
 * public cho luồng download. Kiểm tra chạy trong transaction và khóa version.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận guard package dùng chung.
 * - handle(): kiểm tra package archive và chuyển version sang ready.
 */
class MarkResourceVersionReadyAction
{
    /**
     * Nhận guard dùng chung cho ready, process và preview.
     * Input: ResourceVersionPackageGuard từ container.
     * Output: action sẵn sàng xử lý; chưa mở transaction.
     */
    public function __construct(
        private readonly ResourceVersionPackageGuard $packageGuard,
    ) {}

    /**
     * Chuyển version sang ready sau khi kiểm tra đúng một package archive sạch.
     *
     * Input: ResourceVersion và actor id.
     * Output: ResourceVersion fresh; ném ValidationException và rollback nếu gate fail.
     */
    public function handle(ResourceVersion $version, int $actorId): ResourceVersion
    {
        return DB::transaction(function () use ($version, $actorId): ResourceVersion {
            $version = ResourceVersion::query()
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->packageGuard->cleanPackageFor($version);

            $version->status = ResourceVersionStatus::Ready;
            $version->released_at ??= now();
            $version->created_by ??= $actorId;
            $version->save();

            return $version->fresh([
                'resource',
                'mediaAssetUsages.mediaAsset.media',
            ]);
        });
    }
}
