<?php

namespace App\Repositories\Contracts;

use Prettus\Repository\Contracts\RepositoryInterface as PrettusRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng truy cập dữ liệu resource
 * =====================================================================
 *
 * Interface kế thừa toàn bộ hợp đồng của prettus/l5-repository nên controller
 * có thể type-hint vào interface này thay vì gắn cứng vào lớp Eloquent.
 * RepositoryServiceProvider bind interface này sang
 * `ResourceRepositoryEloquent`, nhờ đó có thể thay implementation khi test.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - published(): thêm criteria chỉ lấy resource đã published
 * - publiclyVisible(): thêm criteria chỉ lấy resource public cho guest
 * - forCategory(): thêm criteria lọc theo category id
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : các tham số truy vấn và mảng attributes từ controller
 * - OUTPUT: Resource, Collection hoặc Paginator tuỳ method được gọi
 * =====================================================================
 */
interface ResourceRepositoryInterface extends PrettusRepositoryInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn repository chỉ còn resource đã xuất bản
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - static: repository đã push PublishedResourceCriteria
     * =====================================================================
     */
    public function published(): static;

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn repository chỉ còn resource public cho guest
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - static: repository đã push PubliclyVisibleResourceCriteria
     * =====================================================================
     */
    public function publiclyVisible(): static;

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc resource theo category
     * =====================================================================
     *
     * INPUT:
     * - $categoryId: id category; null nghĩa là không thêm điều kiện
     *
     * OUTPUT:
     * - static: repository đã push ResourceCategoryCriteria
     * =====================================================================
     */
    public function forCategory(?int $categoryId): static;
}
