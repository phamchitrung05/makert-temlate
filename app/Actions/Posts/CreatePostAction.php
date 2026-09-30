<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\SeoMetadataService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo Post, slug và media trong transaction có retry deadlock.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), handle(), syncMedia(), syncSeo(), fresh().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): attributes/actor ID -> Post đã load relations.
 * =====================================================================
 */
class CreatePostAction
{
    /** Input: usage service. Output: dependency cho đồng bộ media. */
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
        private readonly SeoMetadataService $seoMetadataService,
    ) {}

    /** Input: validated attributes và admin ID. Output: Post; rollback/retry tối đa 5 lần deadlock. */
    public function handle(array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($attributes, $actorId): Post {
            $media = (array) ($attributes['media'] ?? []);
            $taxonomy = $this->extractTaxonomy($attributes);
            unset($attributes['media']);

            $post = Post::query()->create(array_merge(array_diff_key($attributes, $this->seoMetadataService->fields($attributes), array_flip(['category_ids', 'tag_ids'])), [
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]));

            $this->syncSeo($post, $attributes, $actorId);
            $this->syncTaxonomy($post, $taxonomy);
            $this->syncMedia($post, $media, $actorId);

            return $this->fresh($post);
        }, 5);
    }

    /** Input: Post mới và taxonomy ID. Output: pivot category/tag được đồng bộ. */
    private function syncTaxonomy(Post $post, array $taxonomy): void
    {
        if (array_key_exists('category_ids', $taxonomy)) {
            $post->categories()->sync($taxonomy['category_ids']);
        }
        if (array_key_exists('tag_ids', $taxonomy)) {
            $post->tags()->sync($taxonomy['tag_ids']);
        }
    }

    /** Input: payload create. Output: chỉ các trường taxonomy dạng mảng ID. */
    private function extractTaxonomy(array $attributes): array
    {
        return array_map(static fn ($ids): array => (array) $ids,
            array_intersect_key($attributes, array_flip(['category_ids', 'tag_ids'])));
    }

    /** Input: Post vừa tạo và payload SEO. Output: metadata SEO được tạo nếu có dữ liệu. */
    private function syncSeo(Post $post, array $attributes, int $actorId): void
    {
        $this->seoMetadataService->sync($post, $attributes, User::findOrFail($actorId));
    }

    /** Input: Post/media/admin. Output: ghi usage hoặc throw khi sai quyền/asset. */
    private function syncMedia(Post $post, array $media, int $actorId): void
    {
        if ($media === []) {
            return;
        }

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

    /** Input: Post đã lưu. Output: dữ liệu mới và quan hệ phục vụ API. */
    private function fresh(Post $post): Post
    {
        return $post->fresh([
            'slugs',
            'seoMetadata',
            'categories',
            'tags',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }
}
