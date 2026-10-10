<?php

namespace App\Http\Resources;

use App\Enums\MediaAssetField;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Trả Category cùng menu, số bài và ảnh đại diện cho admin.
 * CÁC HÀM/METHOD TRONG FILE: toArray(): bổ sung dữ liệu quản trị vào TaxonomyItem.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Category đã eager load -> DTO cho form và cây.
 * =====================================================================
 */
class CategoryResource extends TaxonomyItem
{
    /** Input: request và Category đã load. Output: DTO, không phát query từ resource. */
    public function toArray(Request $request): array
    {
        $thumbnail = $this->resource->relationLoaded('mediaAssetUsages')
            ? $this->mediaAssetUsages->first(fn ($usage): bool => $usage->field === MediaAssetField::CategoryThumbnail)?->mediaAsset
            : null;

        return array_merge(parent::toArray($request), [
            'parent_id' => $this->parent_id,
            'show_on_menu' => (bool) $this->show_on_menu,
            'posts_count' => (int) ($this->posts_count ?? 0),
            'media' => $this->whenLoaded('mediaAssetUsages', fn (): array => [
                'thumbnail' => $thumbnail ? MediaAssetResource::make($thumbnail) : null,
            ]),
        ]);
    }
}
