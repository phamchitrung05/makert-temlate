<?php

namespace App\Actions\Resources;

use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xóa mềm ResourceVersion qua guard retention và usage
 * =====================================================================
 *
 * Action được controller gọi sau middleware resource_versions.manage. Version
 * được đọc lại và khóa trong transaction; MediaAssetUsageService chặn default,
 * ready hoặc version có lịch sử download trước khi tháo media và snapshot.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận service quản lý media usage.
 * - handle(): khóa version, tháo usage và xóa mềm atomically.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion và actor id đã được xác thực/phân quyền.
 * - OUTPUT: void; version mutable được xóa mềm, MediaAsset/file vẫn được giữ.
 * - SIDE EFFECT: Xóa usage/snapshot, activity log và cập nhật deleted_at.
 * - EXCEPTION/TRANSACTION: Rollback toàn bộ khi guard retention từ chối.
 * =====================================================================
 */
class DeleteResourceVersionAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận service quản lý usage của version
     * =====================================================================
     * INPUT: MediaAssetUsageService từ container.
     * OUTPUT: Instance action sẵn sàng thực thi.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly MediaAssetUsageService $mediaAssetUsageService) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa version mutable và tháo media trong cùng transaction
     * =====================================================================
     * INPUT: Version có thể cũ và actor id đã qua quyền resource_versions.manage.
     * OUTPUT: void; version được xóa mềm khi guard service cho phép.
     * SIDE EFFECT: Khóa row version, xóa usage/screen snapshot và ghi audit.
     * EXCEPTION/TRANSACTION: ModelNotFoundException nếu version/actor đã mất;
     * ValidationException nếu default/ready/có download; rollback toàn bộ.
     * =====================================================================
     */
    public function handle(ResourceVersion $version, int $actorId): void
    {
        DB::transaction(function () use ($version, $actorId): void {
            $version = ResourceVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actorId);

            $this->mediaAssetUsageService->detachAll($actor, $version);
            $version->delete();
        });
    }
}
