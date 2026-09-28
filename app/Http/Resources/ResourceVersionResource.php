<?php

namespace App\Http\Resources;

use App\Enums\MediaAssetField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Định hình Resource Version cùng package và documentation media.
 */
class ResourceVersionResource extends JsonResource
{
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
