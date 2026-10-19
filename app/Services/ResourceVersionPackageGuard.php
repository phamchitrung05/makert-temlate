<?php

namespace App\Services;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Models\MediaAsset;
use App\Models\ResourceVersion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra package archive trước các pipeline version
 * =====================================================================
 *
 * Gate ready/process/preview luôn đọc lại usage và scan hiện tại. Catalog
 * chỉ hiển thị metadata nên có đường riêng để kiểm snapshot đã eager load.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - cleanPackageFor(): trả package duy nhất đã scan clean.
 * - cleanLoadedPackageFor(): kiểm snapshot eager load dành riêng cho catalog.
 * - validatePackage(): kiểm số lượng, loại, visibility và scan của package.
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

        return $this->validatePackage($usages);
    }

    /** Input: version có usages/asset/media đã eager load. Output: package hợp lệ cho DTO catalog, không query lại. */
    public function cleanLoadedPackageFor(ResourceVersion $version): MediaAsset
    {
        if (! $version->relationLoaded('mediaAssetUsages')) {
            throw new \LogicException('Catalog package usages must be eager loaded.');
        }

        $usages = $version->mediaAssetUsages
            ->filter(fn ($usage): bool => $usage->field === MediaAssetField::ResourceVersionPackage);
        foreach ($usages as $usage) {
            if (! $usage->relationLoaded('mediaAsset') || ($usage->mediaAsset && ! $usage->mediaAsset->relationLoaded('media'))) {
                throw new \LogicException('Catalog package assets and media must be eager loaded.');
            }
        }

        return $this->validatePackage($usages);
    }

    /** Input: usages package đã nạp. Output: archive private/clean duy nhất; ValidationException nếu không hợp lệ. */
    private function validatePackage(Collection $usages): MediaAsset
    {

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
