<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;

/** Tạo Post và đồng bộ thumbnail/content images trong một transaction. */
class CreatePostAction
{
    public function __construct(private readonly MediaAssetUsageService $mediaAssetUsageService) {}

    public function handle(array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($attributes, $actorId): Post {
            $media = (array) ($attributes['media'] ?? []);
            unset($attributes['media']);

            $post = Post::query()->create(array_merge($attributes, [
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]));

            $this->syncMedia($post, $media, $actorId);

            return $this->fresh($post);
        });
    }

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

    private function fresh(Post $post): Post
    {
        return $post->fresh([
            'slugs',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }
}
