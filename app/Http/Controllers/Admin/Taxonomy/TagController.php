<?php

namespace App\Http\Controllers\Admin\Taxonomy;

use App\Http\Requests\Admin\Taxonomy\TagCreateRequest;
use App\Http\Requests\Admin\Taxonomy\TagUpdateRequest;
use App\Http\Resources\Taxonomy\TaxonomyItem;
use App\Http\Responses\BaseResponse;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD tag cho admin
 * =====================================================================
 *
 * Controller mỏng kế thừa toàn bộ hành vi CRUD từ TaxonomyController. Nó chỉ
 * khai báo khoá message để tra đúng chuỗi trong config/messages.php, đồng
 * thời chỉ định FormRequest dùng cho tạo mới và cập nhật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - messageKey(): trả về khoá `tag` để tra message
 * - createRequestClass(): trả về TagCreateRequest
 * - updateRequestClass(): trả về TagUpdateRequest
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua auth:sanctum, abilities:admin và permission
 * - OUTPUT: JsonResponse hoặc Response theo envelope BaseResponse
 * =====================================================================
 */
class TagController extends TaxonomyController
{
    /**
     * Input: query phân trang + `type` tùy chọn (`resource`/`visual_style`) để
     * chỉ trả tag đã gắn vào loại tài nguyên đó qua pivot taggables.
     * Output: danh sách Tag phân trang.
     */
    public function index(Request $request): JsonResponse
    {
        $relation = match ((string) $request->query('type')) {
            'resource' => 'resources',
            'visual_style' => 'visualStyles',
            default => null,
        };

        $paginator = $this->repository()
            ->scopeQuery(fn ($query) => $query->when($relation, fn ($q) => $q->whereHas($relation))->orderBy('id'))
            ->paginate($this->resolvePerPage($request));

        return BaseResponse::paginated(
            TaxonomyItem::collection($paginator),
            $this->responseMessage('list'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy khoá taxonomy dùng để tra message trong config
     * =====================================================================
     *
     * OUTPUT:
     * - string: tag
     * Input: tham số theo chữ ký `messageKey()` và state đã hydrate của class.
     */
    protected function messageKey(): string
    {
        return 'tag';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định interface repository của tag
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: TagRepositoryInterface
     * Input: tham số theo chữ ký `repositoryInterface()` và state đã hydrate của class.
     */
    protected function repositoryInterface(): string
    {
        return TagRepositoryInterface::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho tạo tag
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: TagCreateRequest
     * Input: tham số theo chữ ký `createRequestClass()` và state đã hydrate của class.
     */
    protected function createRequestClass(): string
    {
        return TagCreateRequest::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho cập nhật tag
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: TagUpdateRequest
     * Input: tham số theo chữ ký `updateRequestClass()` và state đã hydrate của class.
     */
    protected function updateRequestClass(): string
    {
        return TagUpdateRequest::class;
    }
}
