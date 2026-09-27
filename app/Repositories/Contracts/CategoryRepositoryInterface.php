<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Prettus\Repository\Contracts\RepositoryInterface as PrettusRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng truy cập dữ liệu danh mục
 * =====================================================================
 *
 * Interface kế thừa hợp đồng của prettus/l5-repository cho taxonomy category.
 * Controller chỉ type-hint vào interface này.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - roots(): lấy danh mục cấp cao nhất theo sort_order
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : các tham số truy vấn và mảng attributes từ controller
 * - OUTPUT: Category, Collection hoặc Paginator tuỳ method được gọi
 * =====================================================================
 */
interface CategoryRepositoryInterface extends PrettusRepositoryInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các danh mục cấp cao nhất
     * =====================================================================
     *
     * INPUT:
     * - Không có; điều kiện cố định là parent_id null
     *
     * OUTPUT:
     * - Collection<Category>: danh mục gốc đã sắp xếp theo sort_order
     * =====================================================================
     */
    public function roots(): Collection;
}
