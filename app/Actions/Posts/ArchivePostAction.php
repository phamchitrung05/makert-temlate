<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đưa Post ra khỏi vòng đời hoạt động bằng archive.
 * =====================================================================
 *
 * Archive giữ lại bản ghi, published_at và audit để lịch sử không mất. Post
 * archived không thể publish lại trực tiếp trong P-01; cần workflow khôi phục
 * ở task sau nếu sản phẩm yêu cầu.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): chuyển mọi trạng thái chưa archived sang archived.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Post và actor admin.
 * - OUTPUT: Post đã archived.
 * =====================================================================
 */
final class ArchivePostAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển Post sang archived
     * =====================================================================
     *
     * INPUT:
     * - $post: Post cần archive.
     * - $actorId: id admin thực hiện thao tác.
     *
     * OUTPUT:
     * - Post: bản ghi sau khi archive.
     *
     * SIDE EFFECT:
     * - UPDATE status/updated_by; ghi activity log `archived`.
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction với row lock.
     * - DomainException nếu Post đã archived.
     */
    public function handle(Post $post, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $actorId): Post {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if ($previous === PostStatus::Archived) {
                throw new \DomainException(sprintf('Post [%d] đã được archive trước đó.', $locked->id));
            }

            $locked->forceFill([
                'status' => PostStatus::Archived,
                'updated_by' => $actorId,
            ])->save();

            activity()
                ->causedBy(User::find($actorId))
                ->performedOn($locked)
                ->event('archived')
                ->useLog('posts')
                ->withProperties(['from_status' => $previous->value])
                ->log('Post archived.');

            return $locked;
        });
    }
}
