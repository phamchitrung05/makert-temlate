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
 */
class CreateResourceVersionAction
{
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
    ) {}

    /**
     * Tạo version ở trạng thái draft; ready phải đi qua ready().
     */
    public function handle(array $attributes, int $actorId): ResourceVersion
    {
        return DB::transaction(function () use ($attributes, $actorId): ResourceVersion {
            $media = (array) ($attributes['media'] ?? []);
            unset($attributes['media']);

            $version = ResourceVersion::query()->create(array_merge($attributes, [
                'status' => 'draft',
                'created_by' => $actorId,
            ]));

            $this->syncMedia($version, $media, $actorId);

            return $this->fresh($version);
        });
    }

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

    private function fresh(ResourceVersion $version): ResourceVersion
    {
        return $version->fresh([
            'resource',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }
}
