<?php

namespace App\Actions\Resources;

use App\Models\Resource;
use App\Models\User;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Base chung cho các action ghi resource
 * =====================================================================
 *
 * Base class gom phần lặp lại giữa Create và Update: tách mảng id taxonomy
 * khỏi attributes, đồng bộ pivot và mở transaction. Lớp con chỉ khai báo
 * phần khác biệt của từng use case.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - withinTransaction(): mở transaction và trả về kết quả của closure
 * - syncTaxonomy(): gắn category, tag và technology cho resource
 * - extractTaxonomy(): lấy các mảng *_ids ra khỏi payload
 * - extractAttributes(): loại các mảng *_ids khỏi dữ liệu ghi vào bảng resources
 * - resolveRepository(): lấy repository từ container nếu caller không truyền
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload của action con
 * - OUTPUT: Resource sau khi ghi, đã load sẵn quan hệ cần trả về client
 *
 * EXCEPTION/TRANSACTION:
 * - Mở transaction trong withinTransaction(); mọi lỗi khiến rollback
 * =====================================================================
 */
abstract class ResourceAction
{
    public function __construct(
        protected readonly MediaAssetUsageService $mediaAssetUsageService,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy closure bên trong transaction
     * =====================================================================
     *
     * INPUT:
     * - $callback: closure nhận repository và trả về giá trị cần dùng
     * - $repository: repository dùng để ghi, bỏ trống sẽ resolve từ container
     *
     * OUTPUT:
     * - mixed: giá trị trả về của closure sau khi commit
     *
     * SIDE EFFECT:
     * - INSERT/UPDATE theo logic của closure; rollback toàn bộ nếu có lỗi
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction
     */
    protected function withinTransaction(
        callable $callback,
        ?ResourceRepositoryInterface $repository = null,
    ): mixed {
        $repository = $this->resolveRepository($repository);

        return DB::transaction(fn (): mixed => $callback($repository));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn category, tag và technology cho resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource vừa ghi
     * - $taxonomy: mảng chứa category_ids, tag_ids và technology_ids
     *
     * SIDE EFFECT:
     * - Ghi đè pivot tương ứng; không đụng loại taxonomy không có trong payload
     */
    protected function syncTaxonomy(Resource $resource, array $taxonomy): void
    {
        if (isset($taxonomy['category_ids'])) {
            $resource->categories()->sync($taxonomy['category_ids']);
        }

        if (isset($taxonomy['tag_ids'])) {
            $resource->tags()->sync($taxonomy['tag_ids']);
        }

        if (isset($taxonomy['technology_ids'])) {
            $resource->technologies()->sync($taxonomy['technology_ids']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ cover/preview MediaAsset của Resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: Resource vừa tạo hoặc đang cập nhật
     * - $attributes: payload có thể chứa media.cover_id/preview_ids
     * - $actorId: admin thực hiện mutation
     * OUTPUT: Không trả giá trị; usage được replace trong transaction caller
     * =====================================================================
     */
    protected function syncMedia(Resource $resource, array $attributes, ?int $actorId): void
    {
        if (! array_key_exists('media', $attributes)) {
            return;
        }

        $actor = User::query()->findOrFail($actorId);
        $media = (array) ($attributes['media'] ?? []);
        $fields = [];

        if (array_key_exists('cover_id', $media)) {
            $fields['resource.cover'] = $media['cover_id'] === null
                ? []
                : [$media['cover_id']];
        }

        if (array_key_exists('preview_ids', $media)) {
            $fields['resource.preview'] = (array) ($media['preview_ids'] ?? []);
        }

        $this->mediaAssetUsageService->syncFields($actor, $resource, $fields);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các mảng id taxonomy ra khỏi payload
     * =====================================================================
     *
     * INPUT:
     * - $attributes: mảng dữ liệu đầy đủ từ request
     *
     * OUTPUT:
     * - array: các mảng *_ids đã tách ra, ép về array
     */
    protected function extractTaxonomy(array $attributes): array
    {
        $taxonomy = array_intersect_key($attributes, array_flip(self::TAXONOMY_FIELDS));

        return array_map(fn ($ids): array => (array) $ids, $taxonomy);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Loại các mảng id taxonomy khỏi dữ liệu ghi vào bảng resources
     * =====================================================================
     *
     * INPUT:
     * - $attributes: mảng dữ liệu đầy đủ từ request
     *
     * OUTPUT:
     * - array: chỉ còn các cột thực sự thuộc bảng resources
     */
    protected function extractAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            ...self::TAXONOMY_FIELDS,
            'media',
        ]));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy repository resource từ container khi caller không truyền
     * =====================================================================
     *
     * INPUT:
     * - $repository: repository do caller truyền hoặc null
     *
     * OUTPUT:
     * - ResourceRepositoryInterface: repository sẽ dùng để ghi
     */
    protected function resolveRepository(
        ?ResourceRepositoryInterface $repository = null,
    ): ResourceRepositoryInterface {
        return $repository ?? app(ResourceRepositoryInterface::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Nạp lại resource kèm quan hệ cần cho client
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource vừa ghi
     *
     * OUTPUT:
     * - Resource: bản ghi mới nhất kèm author, categories, tags, technologies
     */
    protected function reloadWithRelations(Resource $resource): Resource
    {
        return $resource->fresh([
            'author',
            'categories',
            'tags',
            'technologies',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }

    /**
     * Danh sách field chứa id taxonomy, dùng chung cho cả Create và Update.
     */
    protected const TAXONOMY_FIELDS = [
        'category_ids',
        'tag_ids',
        'technology_ids',
    ];
}
