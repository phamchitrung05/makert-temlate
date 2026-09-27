<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\TaxonomyItem;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình CRUD base dùng chung cho taxonomy admin
 * =====================================================================
 *
 * Category, Tag và Technology dùng cùng contract HTTP CRUD nên kế thừa
 * BaseCrudController. Lớp này chỉ cung cấp JsonResource, message và tên route
 * parameter theo taxonomy; controller con khai báo repository và FormRequest.
 * Khác biệt về field, unique rule và kiểu xoá vẫn do model/request/repository
 * của từng taxonomy quyết định.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resourceClass(): trả TaxonomyItem cho response CRUD
 * - routeParameterName(): lấy tên route parameter từ messageKey
 * - responseMessage(): lấy message taxonomy theo ngữ cảnh
 * - messageKey(): khai báo category, tag hoặc technology
 * - repositoryInterface(): khai báo repository contract của taxonomy
 * - createRequestClass(): khai báo FormRequest tạo taxonomy
 * - updateRequestClass(): khai báo FormRequest cập nhật taxonomy
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua auth:sanctum, abilities:admin và permission
 * - OUTPUT: JsonResponse/Response theo BaseResponse thông qua CRUD base
 *
 * EXCEPTION/TRANSACTION:
 * - Không tự mở transaction vì mỗi CRUD taxonomy hiện ghi một model
 * - l5-repository không tự quản lý transaction; mutation nhiều bước sau này
 *   phải chuyển vào Action/Service có transaction rõ ràng
 * =====================================================================
 */
abstract class TaxonomyController extends BaseCrudController
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo JsonResource dùng chung cho taxonomy
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string<TaxonomyItem>: resource định hình category/tag/technology
     * =====================================================================
     */
    protected function resourceClass(): string
    {
        return TaxonomyItem::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả tên route parameter của taxonomy hiện tại
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - string: category, tag hoặc technology
     * =====================================================================
     */
    protected function routeParameterName(): string
    {
        return $this->messageKey();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy message taxonomy theo ngữ cảnh CRUD
     * =====================================================================
     *
     * INPUT:
     * - $context: list, detail, created hoặc updated
     *
     * OUTPUT:
     * - string|null: message tương ứng trong config/messages.php
     * =====================================================================
     */
    protected function responseMessage(string $context): ?string
    {
        return config("messages.taxonomy.{$this->messageKey()}.{$context}");
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo khoá taxonomy dùng cho message và route
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - string: category, tag hoặc technology
     * =====================================================================
     */
    abstract protected function messageKey(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo repository contract của taxonomy
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string: interface trong App\Repositories\Contracts
     * =====================================================================
     */
    abstract protected function repositoryInterface(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo FormRequest dùng cho tạo taxonomy
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string: lớp CreateRequest của controller con
     * =====================================================================
     */
    abstract protected function createRequestClass(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo FormRequest dùng cho cập nhật taxonomy
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string: lớp UpdateRequest của controller con
     * =====================================================================
     */
    abstract protected function updateRequestClass(): string;
}
