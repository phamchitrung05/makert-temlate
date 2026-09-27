<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\TechnologyCreateRequest;
use App\Http\Requests\Admin\TechnologyUpdateRequest;
use App\Repositories\Contracts\TechnologyRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD công nghệ cho admin
 * =====================================================================
 *
 * Controller mỏng kế thừa hành vi CRUD từ TaxonomyController. Khác với
 * category và tag, bảng `technologies` không có cột deleted_at nên thao tác
 * xoá ở đây là xoá cứng; quan hệ với resource được gỡ qua cascade của
 * pivot `resource_technology`.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - messageKey(): trả về khoá `technology` để tra message
 * - createRequestClass(): trả về TechnologyCreateRequest
 * - updateRequestClass(): trả về TechnologyUpdateRequest
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua auth:sanctum, abilities:admin và permission
 * - OUTPUT: JsonResponse hoặc Response theo envelope BaseResponse
 * =====================================================================
 */
class TechnologyController extends TaxonomyController
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy khoá taxonomy dùng để tra message trong config
     * =====================================================================
     *
     * OUTPUT:
     * - string: technology
     */
    protected function messageKey(): string
    {
        return 'technology';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định interface repository của công nghệ
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: TechnologyRepositoryInterface
     */
    protected function repositoryInterface(): string
    {
        return TechnologyRepositoryInterface::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho tạo công nghệ
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: TechnologyCreateRequest
     */
    protected function createRequestClass(): string
    {
        return TechnologyCreateRequest::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho cập nhật công nghệ
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: TechnologyUpdateRequest
     */
    protected function updateRequestClass(): string
    {
        return TechnologyUpdateRequest::class;
    }
}
