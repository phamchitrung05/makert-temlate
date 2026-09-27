<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\TagCreateRequest;
use App\Http\Requests\Admin\TagUpdateRequest;
use App\Repositories\Contracts\TagRepositoryInterface;

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
     * =====================================================================
     * CHỨC NĂNG: Lấy khoá taxonomy dùng để tra message trong config
     * =====================================================================
     *
     * OUTPUT:
     * - string: tag
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
     */
    protected function updateRequestClass(): string
    {
        return TagUpdateRequest::class;
    }
}
