<?php

namespace App\Repositories\Criteria;

use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lọc resource theo danh mục đã chọn
 * =====================================================================
 *
 * Áp dụng khi admin hoặc public catalog cần xem resource thuộc một danh mục.
 * Criteria dùng `whereHas` trên quan hệ morphToMany nên không sinh query
 * trùng lặp dù một resource gắn nhiều danh mục cùng lúc.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận id danh mục cần lọc
 * - apply(): gắn điều kiện quan hệ categories vào builder
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : int|null $categoryId lấy từ query string của controller
 * - OUTPUT: Builder chỉ còn resource thuộc danh mục đó
 * =====================================================================
 */
class ResourceCategoryCriteria implements CriteriaInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu id danh mục cần lọc vào criteria
     * =====================================================================
     *
     * INPUT:
     * - $categoryId: id danh mục, null nghĩa là không lọc
     */
    public function __construct(private readonly ?int $categoryId) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn điều kiện lọc theo danh mục vào builder
     * =====================================================================
     *
     * INPUT:
     * - $model: Builder hiện tại của repository
     * - $repository: repository đang áp dụng criteria
     *
     * OUTPUT:
     * - Builder: query đã lọc, hoặc giữ nguyên nếu không có category id
     */
    public function apply($model, RepositoryInterface $repository)
    {
        if ($this->categoryId === null) {
            return $model;
        }

        return $model->whereHas('categories', function ($query): void {
            $query->where('categories.id', $this->categoryId);
        });
    }
}
