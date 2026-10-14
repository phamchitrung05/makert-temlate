<?php

namespace App\Services;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Models\MediaAsset;
use App\Models\ResourceVersion;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra package archive trước các pipeline version
 * =====================================================================
 *
 * Guard dùng chung cho ready, process và preview. Nó luôn đọc lại usage,
 * loại asset, visibility và custom property scan hiện tại thay vì tin vào
 * trạng thái đã nhận ở bước upload.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - cleanPackageFor(): trả package duy nhất đã scan clean.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion có usage resource_version.package.
 * - OUTPUT: MediaAsset archive private, scan clean.
 * - EXCEPTION/TRANSACTION: ValidationException nếu package chưa đạt gate.
 * =====================================================================
 */
class ResourceVersionPackageGuard
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc và xác nhận package archive đã scan clean
     * =====================================================================
     * INPUT: ResourceVersion cần process, ready hoặc cấp preview.
     * OUTPUT: MediaAsset package duy nhất còn tồn tại và scan clean.
     * SIDE EFFECT: Chỉ đọc database/media metadata.
     * EXCEPTION/TRANSACTION: ValidationException nếu thiếu, sai loại hoặc
     * scan_status khác clean.
     * =====================================================================
     */
    public function cleanPackageFor(ResourceVersion $version): MediaAsset
    {
        $usages = $version->mediaAssetUsagesForField(MediaAssetField::ResourceVersionPackage)
            ->with([
                'mediaAsset' => fn ($query) => $query->withTrashed()->with('media'),
            ])
            ->get();

        if ($usages->count() !== 1) {
            throw ValidationException::withMessages([
                'package' => 'Version phải có đúng một package archive trước khi xử lý.',
            ]);
        }

        $asset = $usages->first()->mediaAsset;
        $media = $asset?->getFirstMedia('library');

        if (! $asset instanceof MediaAsset
            || $asset->trashed()
            || $asset->kind !== MediaAssetKind::Archive
            || $asset->visibility !== MediaAssetVisibility::Private
            || $media?->getCustomProperty('scan_status') !== MediaScanStatus::Clean->value) {
            throw ValidationException::withMessages([
                'package' => 'Package phải là archive private và có security scan clean.',
            ]);
        }

        return $asset;
    }
}
