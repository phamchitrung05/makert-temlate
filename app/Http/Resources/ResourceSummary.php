<?php

namespace App\Http\Resources;

use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định hình resource rút gọn cho danh sách admin
 * =====================================================================
 *
 * Dùng cho endpoint index. Bỏ description dài và các trường SEO để payload
 * danh sách nhẹ hơn; admin mở form chi tiết mới cần các trường đó.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ các trường tóm tắt của resource
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Resource nằm trong paginator của endpoint index
 * - OUTPUT: array gọn dùng để render dòng trong bảng admin
 * =====================================================================
 */
class ResourceSummary extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Ánh xạ resource sang shape tóm tắt
     * =====================================================================
     *
     * INPUT:
     * - $request: request hiện tại do Laravel truyền vào
     *
     * OUTPUT:
     * - array<string, mixed>: các trường cần cho cột bảng và thao tác nhanh
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'code' => $this->code,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'is_featured' => $this->is_featured,
            'counters' => [
                'views' => $this->view_count,
                'downloads' => $this->download_count,
            ],
            'author_name' => $this->whenLoaded('author', fn (): ?string => $this->author?->name),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
