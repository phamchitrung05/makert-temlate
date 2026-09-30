<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\SeoMetadataService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cập nhật Post/slug/SEO/media nguyên tử, retry deadlock.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), handle(), syncSeo().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Post/attributes/actor -> Post mới đã load quan hệ.
 * =====================================================================
 */
class UpdatePostAction
{
    /** Input: usage service. Output: dependency để replace media. */
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
        private readonly SeoMetadataService $seoMetadataService,
    ) {}

    /** Input: Post, attributes hợp lệ, admin ID. Output: Post; lock row và rollback khi lỗi. */
    public function handle(Post $post, array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $attributes, $actorId): Post {
            // Input: ID Post. Output: instance mới mỗi retry; tránh dirty/original
            // của lần save đã rollback làm mất event đổi title và slug.
            $post = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $media = (array) ($attributes['media'] ?? []);
            $taxonomy = array_map(static fn ($ids): array => (array) $ids,
                array_intersect_key($attributes, array_flip(['category_ids', 'tag_ids'])));
            unset($attributes['media']);
            $attributes['updated_by'] = $actorId;

            $post->fill(array_diff_key($attributes, $this->seoMetadataService->fields($attributes), array_flip(['category_ids', 'tag_ids'])));
            $post->save();
            $this->syncSeo($post, $attributes, $actorId);

            if (array_key_exists('category_ids', $taxonomy)) {
                $post->categories()->sync($taxonomy['category_ids']);
            }
            if (array_key_exists('tag_ids', $taxonomy)) {
                $post->tags()->sync($taxonomy['tag_ids']);
            }

            if ($media !== []) {
                $actor = User::query()->findOrFail($actorId);
                $fields = [];

                if (array_key_exists('thumbnail_id', $media)) {
                    $fields['post.thumbnail'] = $media['thumbnail_id'] === null ? [] : [$media['thumbnail_id']];
                }

                if (array_key_exists('content_image_ids', $media)) {
                    $fields['post.content_images'] = (array) ($media['content_image_ids'] ?? []);
                }

                $this->mediaAssetUsageService->syncFields($actor, $post, $fields);
            }

            return $post->fresh([
                'slugs',
                'seoMetadata',
                'categories',
                'tags',
                'mediaAssetUsages.mediaAsset.media',
            ]);
        }, 5);
    }

    /** Input: Post đang sửa và payload SEO partial. Output: metadata được cập nhật nếu có field SEO. */
    private function syncSeo(Post $post, array $attributes, int $actorId): void
    {
        $this->seoMetadataService->sync($post, $attributes, User::findOrFail($actorId));
    }
}
