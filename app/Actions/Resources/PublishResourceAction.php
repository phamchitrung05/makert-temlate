<?php

namespace App\Actions\Resources;

use App\Enums\ResourceStatus;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển resource sang trạng thái published
 * =====================================================================
 *
 * Publish là thao tác nhạy cảm vì làm resource xuất hiện trên public catalog.
 * Action chỉ cho phép publish từ một danh sách trạng thái nguồn hợp lệ và tự
 * động ghi published_at. Việc kiểm tra quyền nằm ở permission middleware
 * `resources.publish`, không nằm trong action.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): kiểm tra trạng thái nguồn rồi chuyển sang published
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Resource cần publish, admin thực hiện
 * - OUTPUT: Resource sau khi chuyển trạng thái
 *
 * EXCEPTION/TRANSACTION:
 * - Mở transaction để ghi trạng thái và published_at cùng lúc
 * - Ném DomainException nếu trạng thái hiện tại không cho phép publish
 * =====================================================================
 */
class PublishResourceAction
{
    /**
     * Trạng thái nguồn được phép chuyển sang published.
     */
    private const ALLOWED_SOURCE_STATES = [
        ResourceStatus::Draft,
        ResourceStatus::PendingReview,
        ResourceStatus::Rejected,
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển resource sang trạng thái published
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần publish
     * - $actorId: id admin thực hiện thao tác
     *
     * OUTPUT:
     * - Resource: resource sau khi publish
     *
     * SIDE EFFECT:
     * - UPDATE status = published và published_at nếu chưa có
     * - Ghi activity log `published` qua log name `resources`
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction
     * - DomainException khi trạng thái nguồn không hợp lệ
     */
    public function handle(Resource $resource, ?int $actorId = null): Resource
    {
        $status = $resource->status;

        if (! in_array($status, self::ALLOWED_SOURCE_STATES, true)) {
            throw new \DomainException(
                sprintf('Resource [%d] không thể publish từ trạng thái [%s].', $resource->id, $status->value),
            );
        }

        return DB::transaction(function () use ($resource, $actorId, $status): Resource {
            $resource->status = ResourceStatus::Published;
            $resource->published_at ??= now();
            $resource->updated_by = $actorId;
            $resource->save();

            activity()
                ->causedBy($actorId ? User::find($actorId) : null)
                ->performedOn($resource)
                ->event('published')
                ->useLog('resources')
                ->withProperties(['from_status' => $status->value])
                ->log('Resource published.');

            return $resource;
        });
    }
}
