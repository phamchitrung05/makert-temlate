<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Provenance\AiProvenanceService;
use App\Services\Media\ContentMediaReferenceService;
use App\Services\MediaAssetUsageService;
use App\Services\SeoMetadataService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo Post, slug và media trong transaction có retry deadlock.
 * =====================================================================
 * Action là boundary nghiệp vụ cho create; Controller chỉ truyền payload đã
 * validate. SEO, taxonomy và media usage được đồng bộ trong cùng transaction.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - recordProvenance(): ghi nguồn AI sau khi Post đã lưu trong transaction.
 * - __construct(): nhận các service domain và validator ảnh inline dùng chung.
 * - handle(): tạo Post và đồng bộ SEO/taxonomy/media atomically.
 * - syncTaxonomy()/extractTaxonomy(): chuẩn hóa và đồng bộ pivot.
 * - syncSeo()/syncMedia(): ghi SEO và usage media theo quyền actor.
 * - provenanceValues(): gom giá trị field dùng để băm lineage AI.
 * - fresh(): eager load response relations.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : attributes đã validate và actor ID.
 * - OUTPUT: Post đã lưu cùng relations; ném lỗi validation/authorization khi invariant sai.
 * =====================================================================
 */
class CreatePostAction
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
     * CHỨC NĂNG: Tạo Post và đồng bộ quan hệ trong transaction có retry deadlock.
     * =====================================================================
     * INPUT: attributes đã validate và admin ID; content có ảnh phải mang ID/URL MediaLibrary thật.
     * OUTPUT: Post mới đã eager load.
     * SIDE EFFECT: ghi Post, SEO, taxonomy, media usage và lineage AI.
     * EXCEPTION/TRANSACTION: rollback khi một boundary thất bại; retry deadlock tối đa 5 lần.
     * =====================================================================
     */
    public function handle(array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($attributes, $actorId): Post {
            $media = (array) ($attributes['media'] ?? []);
            // INPUT: content mới. OUTPUT: usage theo ảnh thực sự trong HTML, bỏ gallery client sai lệch.
            if (array_key_exists('content', $attributes)) {
                $media['content_image_ids'] = $this->contentMediaReferenceService->validate((string) ($attributes['content'] ?? ''), User::findOrFail($actorId));
            }
            $taxonomy = $this->extractTaxonomy($attributes);
            $aiRunId = $attributes['ai_run_id'] ?? null;
            $aiFields = (array) ($attributes['ai_fields'] ?? []);
            $aiRuns = (array) ($attributes['ai_runs'] ?? []);
            unset($attributes['media']);
            unset($attributes['ai_run_id'], $attributes['ai_fields'], $attributes['ai_runs']);

            $post = Post::query()->create(array_merge(array_diff_key($attributes, $this->seoMetadataService->fields($attributes), array_flip(['category_ids', 'tag_ids'])), [
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]));

            $this->syncSeo($post, $attributes, $actorId);
            $this->syncTaxonomy($post, $taxonomy);
            $this->syncMedia($post, $media, $actorId);
            $this->recordProvenance($post, $actorId, $aiRunId, $aiFields, $aiRuns, $this->provenanceValues($attributes, $taxonomy, $media));

            return $this->fresh($post);
        }, 5);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ category/tag pivot của Post mới.
     * =====================================================================
     * INPUT: Post và map taxonomy ID.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: ghi pivot category/tag.
     * EXCEPTION/TRANSACTION: query exception truyền lên; dùng transaction của handle().
     * =====================================================================
     */
    private function syncTaxonomy(Post $post, array $taxonomy): void
    {
        if (array_key_exists('category_ids', $taxonomy)) {
            $post->categories()->sync($taxonomy['category_ids']);
        }
        if (array_key_exists('tag_ids', $taxonomy)) {
            $post->tags()->sync($taxonomy['tag_ids']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy taxonomy từ payload create.
     * =====================================================================
     * INPUT: attributes bất kỳ đã validate.
     * OUTPUT: map category_ids/tag_ids luôn ở dạng mảng.
     * SIDE EFFECT: không mutate input hoặc ghi database.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    private function extractTaxonomy(array $attributes): array
    {
        return array_map(static fn ($ids): array => (array) $ids,
            array_intersect_key($attributes, array_flip(['category_ids', 'tag_ids'])));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ metadata SEO qua service chung.
     * =====================================================================
     * INPUT: Post, attributes SEO và actor ID.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: tạo/cập nhật metadata SEO.
     * EXCEPTION/TRANSACTION: service exception truyền lên; dùng transaction của handle().
     * =====================================================================
     */
    private function syncSeo(Post $post, array $attributes, int $actorId): void
    {
        $this->seoMetadataService->sync($post, $attributes, User::findOrFail($actorId));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ thumbnail/content image usage.
     * =====================================================================
     * INPUT: Post, media map và actor ID.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: thay thế media usage theo field được gửi.
     * EXCEPTION/TRANSACTION: lỗi quyền/asset truyền lên; dùng transaction của handle().
     * =====================================================================
     */
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
     * INPUT: Post mới, actor ID, run UUID hoặc danh sách run, field selection và values.
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Nạp lại Post cùng quan hệ dùng cho response.
     * =====================================================================
     * INPUT: Post đã lưu.
     * OUTPUT: Post fresh với slug, SEO, taxonomy và media relations.
     * SIDE EFFECT: truy vấn read-only database.
     * EXCEPTION/TRANSACTION: query exception truyền lên; dùng transaction của handle().
     * =====================================================================
     */
    private function fresh(Post $post): Post
    {
        return $post->fresh([
            'slugs',
            'seoMetadata.ogImage',
            'categories',
            'tags',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }
}
