<?php

namespace App\Actions\Resources;

use App\Enums\ResourceVersionStatus;
use App\Models\ResourceVersion;
use App\Services\Resources\ResourceVersionCacheInvalidator;
use App\Services\Resources\ResourceVersionReadyGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển Resource Version sang ready sau khi kiểm tra package
 * =====================================================================
 *
 * Package archive phải private và security scan clean trước khi version được
 * public cho luồng download. Template còn phải qua processing gate và có
 * artifact/screen/entry hợp lệ. Kiểm tra chạy trong transaction và khóa version.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận ready gate và cache invalidator dùng chung.
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
        private readonly ResourceVersionReadyGate $readyGate,
        private readonly ResourceVersionCacheInvalidator $cacheInvalidator,
    ) {}

    /**
     * Chuyển version sang ready sau khi kiểm tra đúng một package archive sạch.
     *
     * Input: ResourceVersion và actor id.
     * Output: ResourceVersion fresh; ném ValidationException và rollback nếu gate fail.
     */
    public function handle(ResourceVersion $version, int $actorId): ResourceVersion
    {
        $ready = DB::transaction(function () use ($version, $actorId): ResourceVersion {
            $version = ResourceVersion::query()
                ->with('resource')
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($version->status === ResourceVersionStatus::Archived) {
                throw ValidationException::withMessages([
                    'version' => 'Không thể ready version đã archive.',
                ]);
            }

            $this->readyGate->assert($version);
            $fromStatus = $version->status;

            $version->status = ResourceVersionStatus::Ready;
            $version->released_at ??= now();
            $version->created_by ??= $actorId;
            $version->save();

            activity('resources')
                ->causedBy($actorId ? \App\Models\User::find($actorId) : null)
                ->performedOn($version)
                ->event('version.ready')
                ->withProperties(['from_status' => $fromStatus->value])
                ->log('Resource version ready.');

            return $version->fresh([
                'resource',
                'template.screens',
                'mediaAssetUsages.mediaAsset.media',
            ]);
        });

        $this->cacheInvalidator->invalidateForVersion((int) $ready->resource_id, (int) $ready->getKey());

        return $ready;
    }
}
