<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;

/** Cập nhật Post và replace media fields trong một transaction. */
class UpdatePostAction
{
    public function __construct(private readonly MediaAssetUsageService $mediaAssetUsageService) {}

    public function handle(Post $post, array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $attributes, $actorId): Post {
            $media = (array) ($attributes['media'] ?? []);
            unset($attributes['media']);
            $attributes['updated_by'] = $actorId;

            $post->fill($attributes);
            $post->save();

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
                'mediaAssetUsages.mediaAsset.media',
            ]);
        });
    }
}
