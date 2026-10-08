<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Từ chối Post đang chờ review và lưu lý do audit.
 * =====================================================================
 *
 * Action chỉ nhận Post pending_review; reason đã được FormRequest kiểm tra
 * trước khi vào đây. Audit giữ lý do và trạng thái nguồn để reviewer xử lý.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): chuyển pending_review sang rejected và ghi lý do.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Post, lý do và actor admin.
 * - OUTPUT: Post ở trạng thái rejected.
 * =====================================================================
 */
final class RejectPostAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối Post đang chờ review
     * =====================================================================
     *
     * INPUT:
     * - $post: Post pending_review.
     * - $reason: lý do đã validate.
     * - $actorId: id admin thực hiện thao tác.
     *
     * OUTPUT:
     * - Post: bản ghi sau khi chuyển rejected.
     *
     * SIDE EFFECT:
     * - UPDATE status/updated_by; ghi activity log `rejected` kèm reason.
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction với row lock.
     * - DomainException nếu Post chưa ở pending_review.
     */
    public function handle(Post $post, string $reason, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $reason, $actorId): Post {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PostStatus::PendingReview) {
                throw new \DomainException(
                    sprintf('Post [%d] chỉ có thể reject khi đang pending_review.', $locked->id),
                );
            }

            $locked->forceFill([
                'status' => PostStatus::Rejected,
                'updated_by' => $actorId,
            ])->save();

            activity()
                ->causedBy(User::find($actorId))
                ->performedOn($locked)
                ->event('rejected')
                ->useLog('posts')
                ->withProperties(['from_status' => PostStatus::PendingReview->value, 'reason' => trim($reason)])
                ->log('Post rejected.');

            return $locked;
        });
    }
}
