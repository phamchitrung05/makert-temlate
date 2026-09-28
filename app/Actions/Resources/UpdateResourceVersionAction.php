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
 */
class UpdateResourceVersionAction
{
    public function __construct(
        private readonly MediaAssetUsageService $mediaAssetUsageService,
    ) {}

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
