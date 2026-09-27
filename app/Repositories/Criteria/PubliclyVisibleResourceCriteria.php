<?php

namespace App\Repositories\Criteria;

use App\Enums\ResourceStatus;
use App\Enums\ResourceVisibility;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lọc resource mà khách chưa đăng nhập được phép xem
 * =====================================================================
 *
 * Đây là criteria bắt buộc cho mọi truy vấn public catalog. Nó chặn đồng
 * thời resource chưa published và resource không công khai, nên draft hoặc
 * private không thể lọt ra public API dù controller có quên kiểm tra.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - apply(): gắn điều kiện status và visibility vào query
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Eloquent builder do repository truyền vào
 * - OUTPUT: Builder chỉ còn resource public và đã xuất bản
 * =====================================================================
 */
class PubliclyVisibleResourceCriteria implements CriteriaInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn điều kiện public và published vào builder
     * =====================================================================
     *
     * INPUT:
     * - $model: Builder hiện tại của repository
     * - $repository: repository đang áp dụng criteria
     *
     * OUTPUT:
     * - Builder: query an toàn cho khách chưa đăng nhập
     */
    public function apply($model, RepositoryInterface $repository)
    {
        return $model
            ->where('status', ResourceStatus::Published)
            ->where('visibility', ResourceVisibility::Public);
    }
}
