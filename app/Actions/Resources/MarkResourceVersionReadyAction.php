<?php

namespace App\Actions\Resources;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Enums\ResourceVersionStatus;
use App\Models\ResourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển Resource Version sang ready sau khi kiểm tra package
 * =====================================================================
 *
 * Package archive phải private và security scan clean trước khi version được
 * public cho luồng download. Kiểm tra chạy trong transaction và khóa version.
 */
class MarkResourceVersionReadyAction
{
    public function handle(ResourceVersion $version, int $actorId): ResourceVersion
    {
        return DB::transaction(function () use ($version, $actorId): ResourceVersion {
            $version = ResourceVersion::query()
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $usage = $version->mediaAssetUsagesForField(MediaAssetField::ResourceVersionPackage)
                ->with('mediaAsset.media')
                ->first();

            if ($usage === null || $version->mediaAssetUsagesForField(MediaAssetField::ResourceVersionPackage)->count() !== 1) {
                throw ValidationException::withMessages([
                    'package' => 'Version phải có đúng một package archive trước khi ready.',
                ]);
            }

            $asset = $usage->mediaAsset;
            $media = $asset?->getFirstMedia('library');

            if ($asset === null
                || $asset->kind !== MediaAssetKind::Archive
                || $asset->visibility !== MediaAssetVisibility::Private
                || $media?->getCustomProperty('scan_status') !== MediaScanStatus::Clean->value) {
                throw ValidationException::withMessages([
                    'package' => 'Package phải là archive private và có security scan clean.',
                ]);
            }

            $version->status = ResourceVersionStatus::Ready;
            $version->released_at ??= now();
            $version->created_by ??= $actorId;
            $version->save();

            return $version->fresh([
                'resource',
                'mediaAssetUsages.mediaAsset.media',
            ]);
        });
    }
}
