<?php

namespace App\Actions\Resources;

use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cập nhật Resource Version và media usage
 * =====================================================================
 *
 * Version ready không được thay package trong cùng một request; caller phải
 * chuyển version về draft theo policy nghiệp vụ trước khi thay file.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận service đồng bộ media usage.
 * - handle(): cập nhật version và media trong transaction.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion, attributes đã validate và actor id.
 * - OUTPUT: ResourceVersion fresh; rollback nếu update/media thất bại.
 */
class UpdateResourceVersionAction
{
    /**
     * Khởi tạo service đồng bộ media usage.
     *
     * Input: MediaAssetUsageService.
     * Output: instance action; chưa mở transaction.
     */
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
    ) {}

    /**
     * Cập nhật Resource Version và các media usage được chọn.
     *
     * Input: version hiện tại, attributes và actor id.
     * Output: ResourceVersion fresh; ném DomainException nếu version ready đổi media.
     */
    public function handle(
        ResourceVersion $version,
        array $attributes,
        int $actorId,
    ): ResourceVersion {
        return DB::transaction(function () use ($version, $attributes, $actorId): ResourceVersion {
            if ($version->status->value === 'ready' && array_key_exists('media', $attributes)) {
                throw new \DomainException('Không thể thay media của version đang ready.');
            }

            $media = (array) ($attributes['media'] ?? []);
            unset($attributes['media']);
            unset($attributes['status']);

            $version->fill($attributes);
            $version->save();

            if ($media !== []) {
                $actor = User::query()->findOrFail($actorId);
                $fields = [];

                if (array_key_exists('package_id', $media)) {
                    $fields['resource_version.package'] = $media['package_id'] === null
                        ? []
                        : [$media['package_id']];
                }

                if (array_key_exists('documentation_ids', $media)) {
                    $fields['resource_version.documentation'] = (array) ($media['documentation_ids'] ?? []);
                }

                $this->mediaAssetUsageService->syncFields($actor, $version, $fields);
            }

            return $version->fresh([
                'resource',
                'mediaAssetUsages.mediaAsset.media',
            ]);
        });
    }
}
