<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình category, tag và technology cho API
 * =====================================================================
 *
 * Một resource duy nhất phục vụ cả ba loại taxonomy vì chúng có shape gần như
 * giống nhau. Technology có thêm trường `type` nên controller admin gọi kèm
 * `?with_type=1` sẽ bổ sung giá trị này khi model có thuộc tính đó.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ taxonomy sang shape thống nhất
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Category, Tag hoặc Technology
 * - OUTPUT: array chứa id, name và các trường phụ nếu có
 * =====================================================================
 */
class TaxonomyItem extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Ánh xạ taxonomy sang shape thống nhất
     * =====================================================================
     *
     * INPUT:
     * - $request: request hiện tại do Laravel truyền vào
     *
     * OUTPUT:
     * - array<string, mixed>: id, name cùng description/status/type nếu có
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->relationLoaded('slugs') ? $this->primarySlugValue() : null,
            'description' => $this->whenHas('description'),
            'status' => $this->whenHas('status') && $this->status instanceof \BackedEnum
                ? $this->status->value
                : $this->whenHas('status'),
            'type' => $this->whenHas('type') && $this->type instanceof \BackedEnum
                ? $this->type->value
                : $this->whenHas('type'),
            'sort_order' => $this->whenHas('sort_order'),
            'parent_id' => $this->whenHas('parent_id'),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy giá trị slug primary của taxonomy
     * =====================================================================
     *
     * INPUT:
     * - Không có; chỉ chạy khi quan hệ slugs đã được load
     *
     * OUTPUT:
     * - string|null: slug primary hoặc null nếu chưa có
     */
    private function primarySlugValue(): ?string
    {
        $slug = $this->slugs
            ->first(fn ($item): bool => $item->is_primary && $item->locale === config('app.locale'));

        return $slug?->slug;
    }
}
