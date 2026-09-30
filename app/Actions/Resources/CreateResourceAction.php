<?php

namespace App\Actions\Resources;

use App\Models\Resource;
use App\Repositories\Contracts\ResourceRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo resource mới kèm taxonomy
 * =====================================================================
 *
 * Action này là điểm ghi duy nhất cho việc tạo resource. Nó gom hai thao tác
 * vào cùng một transaction: insert bản ghi resource qua repository và đồng
 * bộ category/tag/technology. Slug không được gọi tại đây vì model đã tự sinh
 * qua trait `HasSlug` trong hook `created`.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): tạo resource và liên kết taxonomy trong một transaction
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : mảng attributes chưa validate, id admin thực hiện
 * - OUTPUT: Resource vừa tạo kèm quan hệ đã load
 *
 * EXCEPTION/TRANSACTION:
 * - Mở transaction qua withinTransaction(); lỗi bất kỳ khiến rollback
 * - ValidatorException từ repository ném ra ngoài transaction
 * =====================================================================
 */
class CreateResourceAction extends ResourceAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo resource kèm taxonomy trong một transaction
     * =====================================================================
     *
     * INPUT:
     * - $attributes: mảng dữ liệu resource chưa validate
     * - $actorId: id admin thực hiện thao tác
     * - $repository: repository dùng để chạy ResourceValidator trước khi ghi;
     *   bỏ trống sẽ resolve từ container
     *
     * OUTPUT:
     * - Resource: bản ghi vừa tạo, đã load sẵn quan hệ cần trả về client
     *
     * SIDE EFFECT:
     * - INSERT resources, categorizables, taggables, resource_technology
     * - INSERT slugable do trait HasSlug tự sinh trong hook created
     * - Ghi activity log `created` cho resource
     *
     * EXCEPTION/TRANSACTION:
     * - DB::transaction; lỗi bất kỳ khiến toàn bộ thay đổi rollback
     */
    public function handle(
        array $attributes,
        ?int $actorId = null,
        ?ResourceRepositoryInterface $repository = null,
    ): Resource {
        $resource = $this->withinTransaction(
            function (ResourceRepositoryInterface $repository) use ($attributes, $actorId): Resource {
                $taxonomy = $this->extractTaxonomy($attributes);

                // Gọi repository để ResourceValidator chạy trước khi ghi; tạo
                // model trực tiếp sẽ bypass validator và làm enum cast ném lỗi.
                /** @var resource $resource */
                $resource = $repository->create(
                    array_merge($this->extractAttributes($attributes), [
                        'author_id' => $attributes['author_id'] ?? $actorId,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]),
                );

                $this->syncTaxonomy($resource, $taxonomy);
                $this->syncSeo($resource, $attributes, $actorId);
                $this->syncMedia($resource, $attributes, $actorId);

                return $resource;
            },
            $repository,
        );

        return $this->reloadWithRelations($resource);
    }
}
