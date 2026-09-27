<?php

namespace App\Actions\Resources;

use App\Enums\ResourceStatus;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển resource sang trạng thái archived
 * =====================================================================
 *
 * Archive là hành vi mặc định thay cho xóa cứng: bản ghi vẫn còn để giữ
 * lịch sử download và thống kê, chỉ bị ẩn khỏi mọi truy vấn công khai. Bảng
 * `downloads` khai báo restrictOnDelete tới resources, nên xóa cứng resource
 * đã có lượt tải sẽ bị database chặn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): chuyển resource sang archived
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Resource cần archive, admin thực hiện
 * - OUTPUT: Resource sau khi archive
 *
 * EXCEPTION/TRANSACTION:
 * - Mở transaction
 * - Ném DomainException nếu resource đã archive trước đó
 * =====================================================================
 */
class ArchiveResourceAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển resource sang trạng thái archived
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần archive
     * - $actorId: id admin thực hiện thao tác
     *
     * OUTPUT:
     * - Resource: resource sau khi archive
     *
     * SIDE EFFECT:
     * - UPDATE status = archived
     * - Ghi activity log `archived` qua log name `resources`
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction
     * - DomainException nếu resource đã ở trạng thái archived
     */
    public function handle(Resource $resource, ?int $actorId = null): Resource
    {
        if ($resource->status === ResourceStatus::Archived) {
            throw new \DomainException(
                sprintf('Resource [%d] đã được archive trước đó.', $resource->id),
            );
        }

        return DB::transaction(function () use ($resource, $actorId): Resource {
            $previous = $resource->status;

            $resource->status = ResourceStatus::Archived;
            $resource->updated_by = $actorId;
            $resource->save();

            activity()
                ->causedBy($actorId ? User::find($actorId) : null)
                ->performedOn($resource)
                ->event('archived')
                ->useLog('resources')
                ->withProperties(['from_status' => $previous->value])
                ->log('Resource archived.');

            return $resource;
        });
    }
}
