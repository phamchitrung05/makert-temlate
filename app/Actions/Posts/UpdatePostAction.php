<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Provenance\AiProvenanceService;
use App\Services\MediaAssetUsageService;
use App\Services\Media\ContentMediaReferenceService;
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
 * - __construct(): nhận service media, SEO, lineage và validator ảnh inline.
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
     * =====================================================================
     * CHỨC NĂNG: Nhận service media, SEO và lineage AI từ container.
     * =====================================================================
     * INPUT: MediaAssetUsageService, SeoMetadataService, AiProvenanceService và ContentMediaReferenceService.
     * OUTPUT: action sẵn sàng xử lý.
     * SIDE EFFECT: không gọi database khi khởi tạo.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
        private readonly SeoMetadataService $seoMetadataService,
        private readonly AiProvenanceService $aiProvenanceService,
        private readonly ContentMediaReferenceService $contentMediaReferenceService,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật Post và quan hệ trong transaction có row lock.
     * =====================================================================
     * INPUT: Post, attributes hợp lệ và admin ID; content được gửi quyết định ảnh usage thực tế.
     * OUTPUT: Post fresh cùng slug/SEO/taxonomy/media.
     * SIDE EFFECT: ghi Post, SEO, taxonomy, media usage và lineage AI.
     * EXCEPTION/TRANSACTION: rollback khi boundary thất bại; retry deadlock tối đa 5 lần.
     * =====================================================================
     */
    public function handle(Post $post, array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($post, $attributes, $actorId): Post {
            /**
             * =====================================================================
             * GHI CHÚ: Lấy instance Post mới ở mỗi retry để trạng thái dirty/original
             * của lần save đã rollback không làm mất event đổi title và slug.
             * =====================================================================
             */
            $post = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $media = (array) ($attributes['media'] ?? []);
            // INPUT: content nếu được cập nhật. OUTPUT: usage đúng HTML; partial update giữ usage cũ.
            if (array_key_exists('content', $attributes)) {
                $media['content_image_ids'] = $this->contentMediaReferenceService->validate((string) ($attributes['content'] ?? ''), User::findOrFail($actorId));
            }
            $taxonomy = array_map(static fn ($ids): array => (array) $ids,
                array_intersect_key($attributes, array_flip(['category_ids', 'tag_ids'])));
            $aiRunId = $attributes['ai_run_id'] ?? null;
            $aiFields = (array) ($attributes['ai_fields'] ?? []);
            $aiRuns = (array) ($attributes['ai_runs'] ?? []);
            unset($attributes['media']);
            unset($attributes['ai_run_id'], $attributes['ai_fields'], $attributes['ai_runs']);
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

            $this->recordProvenance($post, $actorId, $aiRunId, $aiFields, $aiRuns, $this->provenanceValues($attributes, $taxonomy, $media));

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
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ metadata SEO partial qua service chung.
     * =====================================================================
     * INPUT: Post đang sửa, attributes SEO và actor ID.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật metadata nếu payload có field SEO.
     * EXCEPTION/TRANSACTION: service exception truyền lên; dùng transaction của handle().
     * =====================================================================
     */
    private function syncSeo(Post $post, array $attributes, int $actorId): void
    {
        $this->seoMetadataService->sync($post, $attributes, User::findOrFail($actorId));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gom payload domain sau khi tách media/taxonomy cho audit AI.
     * =====================================================================
     * INPUT: attributes Post còn lại, taxonomy map và media map.
     * OUTPUT: map phẳng đủ dữ liệu để AiProvenanceService băm từng field.
     * SIDE EFFECT: không có; không ghi database.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    private function provenanceValues(array $attributes, array $taxonomy, array $media): array
    {
        return array_merge($attributes, $taxonomy, ['media' => $media]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi lineage AI sau khi Post và relation đã đồng bộ.
     * =====================================================================
     * INPUT: Post, actor ID, run UUID hoặc danh sách run, field selection và values.
     * OUTPUT: không trả giá trị; bỏ qua khi không có metadata AI.
     * SIDE EFFECT: insert audit và cập nhật AiImport.
     * EXCEPTION/TRANSACTION: provenance validation exception truyền lên; dùng transaction của handle().
     * =====================================================================
     */
    private function recordProvenance(Post $post, int $actorId, ?string $runId, array $fields, array $runs, array $values): void
    {
        if ($runs !== []) {
            $this->aiProvenanceService->recordPostRuns($actorId, $post, $runs, $values);
        } elseif ($runId !== null || $fields !== []) {
            $this->aiProvenanceService->recordPost($actorId, $post, $runId, $fields, $values);
        }
    }
}
