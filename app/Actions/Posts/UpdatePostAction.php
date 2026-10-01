<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\Ai\AiProvenanceService;
use App\Services\MediaAssetUsageService;
use App\Services\SeoMetadataService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cập nhật Post/slug/SEO/media nguyên tử, retry deadlock.
 * =====================================================================
 * Action khóa Post trước khi fill/save và giữ đồng bộ SEO, taxonomy, media
 * trong transaction. Slug được model/domain service quyết định sau save.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận service media và SEO.
 * - handle(): khóa, cập nhật và eager load Post sau transaction.
 * - syncSeo(): ghi metadata SEO partial qua service domain.
 * - provenanceValues()/recordProvenance(): ghi lineage AI theo field đã chọn.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Post hiện tại, attributes đã validate và actor ID.
 * - OUTPUT: Post đã cập nhật cùng relations; exception làm rollback transaction.
 * =====================================================================
 */
class UpdatePostAction
{
    /**
     * Nhận service domain cho media và SEO.
     *
     * Input: MediaAssetUsageService và SeoMetadataService từ container.
     * Output: action sẵn sàng xử lý; không gọi database khi khởi tạo.
     */
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
        private readonly SeoMetadataService $seoMetadataService,
        private readonly AiProvenanceService $aiProvenanceService,
    ) {}

    /**
     * Cập nhật Post và quan hệ trong transaction có row lock.
     *
     * Input: Post, attributes hợp lệ và admin ID.
     * Output: Post fresh cùng slug/SEO/taxonomy/media; lỗi được rollback.
     */
    public function handle(Post $post, array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $attributes, $actorId): Post {
            // Input: ID Post. Output: instance mới mỗi retry; tránh dirty/original
            // của lần save đã rollback làm mất event đổi title và slug.
            $post = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $media = (array) ($attributes['media'] ?? []);
            $taxonomy = array_map(static fn ($ids): array => (array) $ids,
                array_intersect_key($attributes, array_flip(['category_ids', 'tag_ids'])));
            $aiRunId = $attributes['ai_run_id'] ?? null;
            $aiFields = (array) ($attributes['ai_fields'] ?? []);
            unset($attributes['media']);
            unset($attributes['ai_run_id'], $attributes['ai_fields']);
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

            $this->recordProvenance($post, $actorId, $aiRunId, $aiFields, $this->provenanceValues($attributes, $taxonomy, $media));

            return $post->fresh([
                'slugs',
                'seoMetadata.ogImage',
                'categories',
                'tags',
                'mediaAssetUsages.mediaAsset.media',
            ]);
        }, 5);
    }

    /**
     * Đồng bộ metadata SEO partial qua service chung.
     *
     * Input: Post đang sửa, attributes SEO và actor ID.
     * Output: không trả giá trị; metadata được cập nhật nếu payload có field SEO.
     */
    private function syncSeo(Post $post, array $attributes, int $actorId): void
    {
        $this->seoMetadataService->sync($post, $attributes, User::findOrFail($actorId));
    }

    /**
     * Gom payload domain sau khi đã tách media/taxonomy cho audit AI.
     *
     * Input: attributes Post còn lại, taxonomy map và media map.
     * Output: map phẳng đủ dữ liệu để AiProvenanceService băm từng field.
     * Side effect: không có; không ghi database.
     */
    private function provenanceValues(array $attributes, array $taxonomy, array $media): array
    {
        return array_merge($attributes, $taxonomy, ['media' => $media]);
    }

    /**
     * Ghi lineage AI sau khi update và các relation đã đồng bộ.
     *
     * Input: Post, actor ID, run UUID, field selection và payload values.
     * Output: không trả giá trị; service bỏ qua khi không có metadata AI.
     * Side effect: insert audit và cập nhật AiImport trong transaction cha.
     */
    private function recordProvenance(Post $post, int $actorId, ?string $runId, array $fields, array $values): void
    {
        if ($runId !== null || $fields !== []) {
            $this->aiProvenanceService->recordPost($actorId, $post, $runId, $fields, $values);
        }
    }
}
