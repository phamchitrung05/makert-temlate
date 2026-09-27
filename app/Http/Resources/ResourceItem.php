<?php

namespace App\Http\Resources;

use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình resource đầy đủ cho admin API
 * =====================================================================
 *
 * Resource dùng cho endpoint chi tiết (show, store, update). Danh sách dùng
 * ResourceSummary để payload nhẹ hơn. Cả hai đều trả enum dưới dạng string
 * value để frontend không cần biết tên case của enum.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ toàn bộ trường nghiệp vụ của resource
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Resource do controller hoặc paginator truyền vào
 * - OUTPUT: array đã định hình cho client admin
 * =====================================================================
 */
class ResourceItem extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Ánh xạ resource sang shape trả về cho admin
     * =====================================================================
     *
     * INPUT:
     * - $request: request hiện tại do Laravel truyền vào
     *
     * OUTPUT:
     * - array<string, mixed>: thông tin resource, taxonomy và slug hiện hành
     *
     * SIDE EFFECT:
     * - Đọc các quan hệ đã eager load; không query thêm nếu controller đã load
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'code' => $this->code,
            'slug' => $this->primarySlug()?->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'is_featured' => $this->is_featured,
            'demo_url' => $this->demo_url,
            'documentation_url' => $this->documentation_url,
            'seo' => [
                'title' => $this->seo_title,
                'description' => $this->seo_description,
                'canonical_url' => $this->canonical_url,
            ],
            'counters' => [
                'views' => $this->view_count,
                'downloads' => $this->download_count,
            ],
            'taxonomy' => [
                'categories' => TaxonomyItem::collection($this->whenLoaded('categories')),
                'tags' => TaxonomyItem::collection($this->whenLoaded('tags')),
                'technologies' => TaxonomyItem::collection($this->whenLoaded('technologies')),
            ],
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author
                ? ['id' => $this->author->id, 'name' => $this->author->name]
                : null),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
