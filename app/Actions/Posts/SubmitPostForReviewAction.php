<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đưa Post nháp hoặc bị từ chối vào hàng chờ review.
 * =====================================================================
 *
 * Action khóa bản ghi trước khi đổi trạng thái để hai reviewer không thể
 * đồng thời ghi đè lifecycle. Quyền `posts.review` được kiểm tra ở route.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): chuyển draft/rejected sang pending_review và ghi audit.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Post và actor admin.
 * - OUTPUT: Post sau khi submit review.
 * =====================================================================
 */
final class SubmitPostForReviewAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi Post vào hàng chờ review
     * =====================================================================
     *
     * INPUT:
     * - $post: Post cần chuyển trạng thái.
     * - $actorId: id admin thực hiện thao tác.
     *
     * OUTPUT:
     * - Post: bản ghi sau khi chuyển sang pending_review.
     *
     * SIDE EFFECT:
     * - UPDATE status và updated_by; ghi activity log `review_submitted`.
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction với row lock.
     * - DomainException nếu Post không ở draft hoặc rejected.
     */
    public function handle(Post $post, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $actorId): Post {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if (! in_array($previous, [PostStatus::Draft, PostStatus::Rejected], true)) {
                throw new \DomainException(
                    sprintf('Post [%d] không thể gửi review từ trạng thái [%s].', $locked->id, $previous->value),
                );
            }

            $locked->forceFill([
                'status' => PostStatus::PendingReview,
                'updated_by' => $actorId,
            ])->save();

            activity()
                ->causedBy(User::find($actorId))
                ->performedOn($locked)
                ->event('review_submitted')
                ->useLog('posts')
                ->withProperties(['from_status' => $previous->value])
                ->log('Post submitted for review.');

            return $locked;
        });
    }
}
