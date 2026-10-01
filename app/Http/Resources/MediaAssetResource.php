<?php

namespace App\Http\Resources;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình MediaAsset và file Spatie cho admin API
 * =====================================================================
 *
 * Resource gom metadata nghiệp vụ với custom properties của file library.
 * Không trả absolute path, storage disk hoặc private URL lâu hạn; private
 * asset chỉ có endpoint download đã authorize ở controller.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ asset, file, status, URL và usage
 * - media(): lấy media item trong collection library
 * - filePayload(): tạo metadata file không lộ storage path
 * - publicUrl(): lấy URL public và nuốt lỗi driver không hỗ trợ URL
 * - previewUrl(): lấy URL conversion featured/thumb nếu đã sẵn sàng
 * - conversionUrl(): lấy URL conversion canonical khi file đã sẵn sàng
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : MediaAsset có thể eager load media, createdBy và usages
 * - OUTPUT: payload an toàn cho list/detail/picker của Media Library
 * =====================================================================
 */
class MediaAssetResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Ánh xạ MediaAsset sang response JSON ổn định
     * =====================================================================
     *
     * INPUT: $request HTTP hiện tại.
     * OUTPUT: array<string, mixed> gồm metadata và file public-safe.
     */
    public function toArray(Request $request): array
    {
        $media = $this->media();
        $customProperties = $media?->custom_properties ?? [];
        $uploadMetadata = $customProperties['upload_metadata'] ?? [];

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'alt_text' => $this->alt_text,
            'visibility' => $this->visibility->value,
            'created_by' => $this->created_by,
            'owner' => $this->whenLoaded('createdBy', fn (): ?array => $this->createdBy
                ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]
                : null),
            'file' => $this->filePayload($media, $customProperties, $uploadMetadata),
            'download_url' => route('admin.media-assets.download', ['mediaAsset' => $this->id]),
            'usages' => MediaAssetUsageResource::collection($this->whenLoaded('usages')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy media đầu tiên trong collection library
     * =====================================================================
     *
     * OUTPUT:
     * - Media|null: file Spatie của asset hoặc null nếu metadata-only
     */
    private function media(): ?\Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        return $this->getFirstMedia('library');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo metadata file không lộ đường dẫn nội bộ
     * =====================================================================
     *
     * INPUT: media và custom properties đã lưu trong Spatie.
     * OUTPUT: array file/status/URL an toàn cho client.
     */
    private function filePayload(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media, array $customProperties, array $uploadMetadata): array
    {
        if ($media === null) {
            return [
                'id' => null,
                'original_name' => null,
                'file_name' => null,
                'mime_type' => null,
                'extension' => null,
                'size' => null,
                'checksum_sha256' => null,
                'scan_status' => null,
                'conversion_status' => null,
                'url' => null,
                'preview_url' => null,
            ];
        }

        return [
            'id' => $media->getKey(),
            'original_name' => $uploadMetadata['original_name'] ?? $media->file_name,
            'file_name' => $media->file_name,
            'mime_type' => $uploadMetadata['mime_type'] ?? $media->mime_type,
            'extension' => $uploadMetadata['extension'] ?? $media->extension,
            'size' => (int) ($uploadMetadata['size'] ?? $media->size),
            'checksum_sha256' => $customProperties['checksum_sha256'] ?? null,
            'scan_status' => $customProperties['scan_status'] ?? null,
            'conversion_status' => $customProperties['conversion_status'] ?? null,
            'url' => $this->isPublicAsset() ? $this->publicUrl($media) : null,
            'preview_url' => $this->isPublicAsset()
                ? $this->previewUrl($media)
                : null,
            'featured_url' => $this->isPublicAsset()
                ? $this->conversionUrl($media, 'featured')
                : null,
            'og_url' => $this->isPublicAsset()
                ? $this->conversionUrl($media, 'og')
                : null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy URL gốc của public asset
     * =====================================================================
     *
     * INPUT: media Spatie trên public disk.
     * OUTPUT: string|null; null nếu disk không hỗ trợ URL.
     */
    private function publicUrl(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): ?string
    {
        try {
            return $media->getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy URL conversion thumb nếu đã tạo
     * =====================================================================
     *
     * INPUT: media Spatie của image public.
     * OUTPUT: string|null; không trả URL khi conversion chưa ready.
     */
    private function previewUrl(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): ?string
    {
        return $this->conversionUrl($media, 'featured')
            ?? $this->conversionUrl($media, 'thumb');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy URL conversion canonical đã sẵn sàng
     * =====================================================================
     *
     * INPUT: media Spatie và tên conversion đã allowlist trong model.
     * OUTPUT: string|null; không để lộ lỗi driver hoặc URL khi file chưa xong.
     */
    private function conversionUrl(\Spatie\MediaLibrary\MediaCollections\Models\Media $media, string $conversion): ?string
    {
        if (! $media->hasGeneratedConversion($conversion)) {
            return null;
        }

        try {
            return $media->getUrl($conversion);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định asset có được phép trả URL public hay không
     * =====================================================================
     *
     * OUTPUT:
     * - bool: false cho archive dù metadata visibility bị cấu hình sai
     */
    private function isPublicAsset(): bool
    {
        return $this->visibility === MediaAssetVisibility::Public
            && $this->kind !== MediaAssetKind::Archive;
    }
}
