<?php

namespace App\Http\Resources\Resources;

use App\Enums\MediaAssetField;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Http\Resources\Media\MediaAssetResource;
use App\Models\ResourceVersionTemplate;
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
 * - toArray(): ánh xạ phiên bản, trạng thái, template và media theo field.
 * - templatePayload(): trả processing state/screen metadata bounded.
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
        $template = $this->resource->relationLoaded('template')
            ? $this->template
            : null;

        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'resource_type' => $this->resource->relationLoaded('resource') ? $this->resource->resource?->type->value : null,
            'version' => $this->version,
            'changelog' => $this->changelog,
            'requirements' => $this->requirements,
            'status' => $this->status->value,
            'is_default' => $this->is_default,
            'released_at' => $this->released_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'template' => $template ? $this->templatePayload($template) : null,
            'media' => [
                'package' => $package ? MediaAssetResource::make($package) : null,
                'documentation' => MediaAssetResource::collection($documentation),
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize processing state template không lộ storage path
     * =====================================================================
     * INPUT: ResourceVersionTemplate đã eager-load tùy endpoint.
     * OUTPUT: state/checksum/entry/screens bounded cho admin resource version.
     * SIDE EFFECT: Chỉ đọc model; không kiểm filesystem hoặc query lazy.
     * EXCEPTION/TRANSACTION: Không mở transaction; không trả raw manifest lớn.
     * =====================================================================
     */
    private function templatePayload(ResourceVersionTemplate $template): array
    {
        $screens = $template->relationLoaded('screens')
            ? $template->screens->map(function ($screen): array {
                $preview = $screen->relationLoaded('mediaAssetUsages')
                    ? $screen->mediaAssetUsages
                        ->first(fn ($usage): bool => $usage->field === MediaAssetField::TemplateScreenPreview)
                        ?->mediaAsset
                    : null;

                return [
                    'id' => $screen->id,
                    'slug' => $screen->slug,
                    'title' => $screen->title,
                    'source_path' => $screen->source_path,
                    'sort_order' => $screen->sort_order,
                    'metadata' => is_array($screen->metadata) ? array_intersect_key($screen->metadata, ['link_mapping' => true]) : [],
                    'preview' => $preview ? MediaAssetResource::make($preview) : null,
                ];
            })->values()->all()
            : null;

        return [
            'processing_status' => $template->processing_status->value,
            'source_media_id' => $template->source_media_id,
            'package_sha256' => $template->package_sha256,
            'source_root' => $template->source_root,
            'entry_screen_slug' => $template->entry_screen_slug,
            'processing_error' => $template->processing_error
                ? mb_substr((string) $template->processing_error, 0, 1000)
                : null,
            'processed_at' => $template->processed_at?->toIso8601String(),
            'preview_available' => $template->processing_status === ResourceVersionTemplateProcessingStatus::Ready
                && filled($template->preview_path),
            'screens_count' => $screens === null ? null : count($screens),
            'screens' => $screens,
            'warnings' => array_slice((array) data_get($template->manifest_json, 'warnings', []), 0, 200),
            'warnings_total' => (int) data_get($template->manifest_json, 'warnings_total', 0),
            'requires_entry_selection' => (bool) data_get($template->manifest_json, 'requires_entry_selection', true),
        ];
    }
}
