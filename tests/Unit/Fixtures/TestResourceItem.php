<?php

namespace Tests\Unit\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: JsonResource giả lập cho unit test BaseResponse
 * =====================================================================
 *
 * Resource tối giảu chứng minh rằng paginated() đưa mọi phần tử đi qua
 * JsonResource trước khi ghi vào trường data của envelope.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): ánh xạ hai trường id và title ra shape mong muốn
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceStub do Paginator bọc
 * - OUTPUT: array dạng ['id' => int, 'title' => string]
 * =====================================================================
 */
class TestResourceItem extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Ánh xạ resource sang shape trả về cho client
     * =====================================================================
     *
     * INPUT:
     * - $request: request hiện tại do Laravel truyền vào
     *
     * OUTPUT:
     * - array<string, mixed>: chỉ gồm id và title
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
