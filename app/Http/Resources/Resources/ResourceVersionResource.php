<?php

namespace App\Http\Resources\Resources;

use App\Enums\MediaAssetField;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize Resource Version cùng media package/documentation.
 * =====================================================================
 * Resource đọc media usages đã eager-load và trả payload ổn định cho admin;
 * không tự truy vấn hoặc ghi database.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ phiên bản, trạng thái và media theo field.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion và quan hệ mediaAssetUsages đã tải.
 * - OUTPUT: mảng JSON API; không có side effect.
 * =====================================================================
 */
class ResourceVersionResource extends JsonResource
{
    /**
     * Ánh xạ Resource Version và hai nhóm media theo field.
     *
     * Input: Request và ResourceVersion resource.
     * Output: array payload cho API; chỉ đọc quan hệ đã tải.
     */
    public function toArray(Request $request): array
    {
        $usages = $this->resource->relationLoaded('mediaAssetUsages')
            ? $this->mediaAssetUsages
            : collect();

        $package = $usages
            ->first(fn ($usage): bool => $usage->field === MediaAssetField::ResourceVersionPackage)
            ?->mediaAsset;
        $documentation = $usages
            ->filter(fn ($usage): bool => $usage->field === MediaAssetField::ResourceVersionDocumentation)
            ->sortBy('sort_order')
            ->map(fn ($usage) => $usage->mediaAsset)
            ->filter();

        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'version' => $this->version,
            'changelog' => $this->changelog,
            'requirements' => $this->requirements,
            'status' => $this->status->value,
            'is_default' => $this->is_default,
            'released_at' => $this->released_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'media' => [
                'package' => $package ? MediaAssetResource::make($package) : null,
                'documentation' => MediaAssetResource::collection($documentation),
            ],
        ];
    }
}
