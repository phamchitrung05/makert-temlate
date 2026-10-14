<?php

namespace App\Actions\Resources;

use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo Resource Version kèm MediaAsset usage
 * =====================================================================
 *
 * Action giữ insert version và đồng bộ package/documentation trong cùng
 * transaction. Payload media chỉ chứa id; kind, field và permission được
 * kiểm tra tại MediaAssetUsageService.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận service đồng bộ media usage.
 * - handle(): tạo version draft và đồng bộ media trong transaction.
 * - syncMedia(): ghi các media usage được chọn.
 * - fresh(): reload version cùng quan hệ API cần trả về.
 */
class CreateResourceVersionAction
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
     * Tạo version ở trạng thái draft; ready phải đi qua ready().
     * Input: tham số theo chữ ký `handle()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `handle()`; side effect/exception giữ theo implementation.
     */
    public function handle(array $attributes, int $actorId): ResourceVersion
    {
        return DB::transaction(function () use ($attributes, $actorId): ResourceVersion {
            $media = (array) ($attributes['media'] ?? []);
            unset($attributes['media'], $attributes['status'], $attributes['is_default']);

            $version = ResourceVersion::query()->create(array_merge($attributes, [
                'status' => 'draft',
                'created_by' => $actorId,
            ]));

            $this->syncMedia($version, $media, $actorId);

            return $this->fresh($version);
        });
    }

    /**
     * Đồng bộ package/documentation media của version.
     *
     * Input: ResourceVersion, media ids và actor id.
     * Output: void; ghi pivot qua MediaAssetUsageService khi media có dữ liệu.
     */
    private function syncMedia(ResourceVersion $version, array $media, int $actorId): void
    {
        if ($media === []) {
            return;
        }

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

    /**
     * Reload version cùng quan hệ cần trả về API.
     *
     * Input: ResourceVersion vừa tạo.
     * Output: ResourceVersion fresh với resource/media usages.
     */
    private function fresh(ResourceVersion $version): ResourceVersion
    {
        return $version->fresh([
            'resource',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }
}
