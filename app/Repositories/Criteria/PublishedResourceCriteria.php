<?php

namespace App\Repositories\Criteria;

use App\Enums\ResourceStatus;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lọc resource chỉ giữ trạng thái đã xuất bản
 * =====================================================================
 *
 * Criteria được đẩy vào repository bằng `pushCriteria()` trước khi gọi
 * `all()`, `paginate()` hoặc `find()`. Tham số `filter` của RequestCriteria
 * chỉ chọn cột, không lọc điều kiện, nên mọi điều kiện nghiệp vụ về trạng
 * thái đều phải dùng Criteria riêng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - apply(): gắn điều kiện status = published vào query
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Eloquent builder do repository truyền vào cùng repository hiện tại
 * - OUTPUT: Builder đã lọc
 * =====================================================================
 */
class PublishedResourceCriteria implements CriteriaInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn điều kiện published vào builder
     * =====================================================================
     *
     * INPUT:
     * - $model: Builder hiện tại của repository
     * - $repository: repository đang áp dụng criteria
     *
     * OUTPUT:
     * - Builder: query chỉ còn resource có status published
     */
    public function apply($model, RepositoryInterface $repository)
    {
        return $model->where('status', ResourceStatus::Published);
    }
}
