<?php

namespace App\Actions\Resources;

use App\Models\Resource;
use App\Repositories\Contracts\ResourceRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cập nhật resource kèm taxonomy
 * =====================================================================
 *
 * Đối xứng với CreateResourceAction nhưng dành cho bản ghi đã tồn tại. Slug
 * không được gọi tại đây: trait `HasSlug` đã tự sinh lại slug trong hook
 * `updated` khi và chỉ khi cột nguồn slug thay đổi, nên URL công khai giữ
 * nguyên khi admin lưu form mà không sửa tên.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): cập nhật resource và liên kết taxonomy trong một transaction
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Resource cần sửa, mảng attributes chưa validate, id admin
 * - OUTPUT: Resource sau khi cập nhật
 *
 * EXCEPTION/TRANSACTION:
 * - Mở transaction qua withinTransaction(); lỗi bất kỳ khiến rollback
 * =====================================================================
 */
class UpdateResourceAction extends ResourceAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật resource trong một transaction
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần cập nhật
     * - $attributes: mảng dữ liệu chưa validate
     * - $actorId: id admin thực hiện thao tác
     * - $repository: repository dùng để chạy ResourceValidator trước khi ghi
     *
     * OUTPUT:
     * - Resource: bản ghi sau cập nhật, đã load sẵn quan hệ cần trả về
     *
     * SIDE EFFECT:
     * - UPDATE resources; ghi lại pivot taxonomy nếu có trong payload
     * - INSERT/UPDATE slugable do trait HasSlug tự sinh khi tên đổi
     * - Ghi activity log `updated` cho resource
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction; lỗi bất kỳ khiến toàn bộ thay đổi rollback
     */
    public function handle(
        Resource $resource,
        array $attributes,
        ?int $actorId = null,
        ?ResourceRepositoryInterface $repository = null,
    ): Resource {
        $this->withinTransaction(
            function (ResourceRepositoryInterface $repository) use ($resource, $attributes, $actorId): void {
                $taxonomy = $this->extractTaxonomy($attributes);

                $repository->update(
                    array_merge($this->extractAttributes($attributes), ['updated_by' => $actorId]),
                    $resource->getKey(),
                );

                $this->syncTaxonomy($resource, $taxonomy);
            },
            $repository,
        );

        return $this->reloadWithRelations($resource);
    }
}
