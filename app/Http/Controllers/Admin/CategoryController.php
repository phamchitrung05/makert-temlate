<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CategoryCreateRequest;
use App\Http\Requests\Admin\CategoryUpdateRequest;
use App\Repositories\Contracts\CategoryRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD danh mục cho admin
 * =====================================================================
 *
 * Controller mỏng kế thừa toàn bộ hành vi CRUD từ TaxonomyController. Nó chỉ
 * khai báo khoá message để tra đúng chuỗi trong config/messages.php, đồng
 * thời chỉ định FormRequest dùng cho tạo mới và cập nhật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - messageKey(): trả về khoá `category` để tra message
 * - createRequestClass(): trả về CategoryCreateRequest
 * - updateRequestClass(): trả về CategoryUpdateRequest
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua auth:sanctum, abilities:admin và permission
 * - OUTPUT: JsonResponse hoặc Response theo envelope BaseResponse
 * =====================================================================
 */
class CategoryController extends TaxonomyController
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy khoá taxonomy dùng để tra message trong config
     * =====================================================================
     *
     * OUTPUT:
     * - string: category
     */
    protected function messageKey(): string
    {
        return 'category';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định interface repository của danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: CategoryRepositoryInterface
     */
    protected function repositoryInterface(): string
    {
        return CategoryRepositoryInterface::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho tạo danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: CategoryCreateRequest
     */
    protected function createRequestClass(): string
    {
        return CategoryCreateRequest::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho cập nhật danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: CategoryUpdateRequest
     */
    protected function updateRequestClass(): string
    {
        return CategoryUpdateRequest::class;
    }
}
