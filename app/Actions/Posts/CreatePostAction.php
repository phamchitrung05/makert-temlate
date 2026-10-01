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
 * CHỨC NĂNG FILE: Tạo Post, slug và media trong transaction có retry deadlock.
 * =====================================================================
 * Action là boundary nghiệp vụ cho create; Controller chỉ truyền payload đã
 * validate. SEO, taxonomy và media usage được đồng bộ trong cùng transaction.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận các service domain dùng chung.
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
     * Tạo Post và đồng bộ các quan hệ trong transaction có retry deadlock.
     *
     * Input: attributes đã validate và admin ID.
     * Output: Post mới đã eager load; rollback khi một boundary thất bại.
     */
    public function handle(array $attributes, int $actorId): Post
    {
        return DB::transaction(function () use ($attributes, $actorId): Post {
            $media = (array) ($attributes['media'] ?? []);
            $taxonomy = $this->extractTaxonomy($attributes);
            $aiRunId = $attributes['ai_run_id'] ?? null;
            $aiFields = (array) ($attributes['ai_fields'] ?? []);
            unset($attributes['media']);
            unset($attributes['ai_run_id'], $attributes['ai_fields']);

            $post = Post::query()->create(array_merge(array_diff_key($attributes, $this->seoMetadataService->fields($attributes), array_flip(['category_ids', 'tag_ids'])), [
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]));

            $this->syncSeo($post, $attributes, $actorId);
            $this->syncTaxonomy($post, $taxonomy);
            $this->syncMedia($post, $media, $actorId);
            $this->recordProvenance($post, $actorId, $aiRunId, $aiFields, $this->provenanceValues($attributes, $taxonomy, $media));

            return $this->fresh($post);
        }, 5);
    }

    /**
     * Đồng bộ category/tag pivot của Post mới.
     *
     * Input: Post và map taxonomy ID.
     * Output: không trả giá trị; pivot được sync hoặc exception truyền lên.
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
     * Lấy taxonomy từ payload create.
     *
     * Input: attributes bất kỳ đã validate.
     * Output: map category_ids/tag_ids luôn ở dạng mảng.
     */
    private function extractTaxonomy(array $attributes): array
    {
        return array_map(static fn ($ids): array => (array) $ids,
            array_intersect_key($attributes, array_flip(['category_ids', 'tag_ids'])));
    }

    /**
     * Đồng bộ metadata SEO qua service chung.
     *
     * Input: Post, attributes SEO và actor ID.
     * Output: không trả giá trị; metadata được tạo/cập nhật trong transaction cha.
     */
    private function syncSeo(Post $post, array $attributes, int $actorId): void
    {
        $this->seoMetadataService->sync($post, $attributes, User::findOrFail($actorId));
    }

    /**
     * Đồng bộ thumbnail/content image usage.
     *
     * Input: Post, media map và actor ID.
     * Output: không trả giá trị; usage được replace hoặc ném lỗi quyền/asset.
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
     * Ghi lineage AI sau khi Post và các relation đã đồng bộ.
     *
     * Input: Post mới, actor ID, run UUID, field selection và payload values.
     * Output: không trả giá trị; service bỏ qua khi không có metadata AI.
     * Side effect: insert audit và cập nhật AiImport trong transaction cha.
     */
    private function recordProvenance(Post $post, int $actorId, ?string $runId, array $fields, array $values): void
    {
        if ($runId !== null || $fields !== []) {
            $this->aiProvenanceService->recordPost($actorId, $post, $runId, $fields, $values);
        }
    }

    /**
     * Nạp lại Post cùng quan hệ dùng cho response.
     *
     * Input: Post đã lưu.
     * Output: Post fresh với slug, SEO, taxonomy và media relations.
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
