<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xuất bản Post sau khi đã qua boundary publish.
 * =====================================================================
 *
 * Publish là thao tác làm nội dung công khai. Action giữ quyền ở middleware,
 * kiểm trạng thái nguồn trong transaction và tự ghi `published_at` từ server.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): chuyển draft/pending_review/rejected sang published.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Post cần publish và actor admin.
 * - OUTPUT: Post đã published cùng thời điểm publish.
 * =====================================================================
 */
final class PublishPostAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển Post sang published
     * =====================================================================
     *
     * INPUT:
     * - $post: Post cần xuất bản.
     * - $actorId: id admin thực hiện thao tác.
     *
     * OUTPUT:
     * - Post: bản ghi sau khi publish.
     *
     * SIDE EFFECT:
     * - UPDATE status, published_at và updated_by; ghi activity log `published`.
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction với row lock.
     * - DomainException nếu trạng thái nguồn không hợp lệ.
     */
    public function handle(Post $post, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $actorId): Post {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if (! in_array($previous, [PostStatus::Draft, PostStatus::PendingReview, PostStatus::Rejected], true)) {
                throw new \DomainException(
                    sprintf('Post [%d] không thể publish từ trạng thái [%s].', $locked->id, $previous->value),
                );
            }

            $locked->forceFill([
                'status' => PostStatus::Published,
                'published_at' => $locked->published_at ?? now(),
                'updated_by' => $actorId,
            ])->save();

            activity()
                ->causedBy(User::find($actorId))
                ->performedOn($locked)
                ->event('published')
                ->useLog('posts')
                ->withProperties(['from_status' => $previous->value])
                ->log('Post published.');

            return $locked;
        });
    }
}
