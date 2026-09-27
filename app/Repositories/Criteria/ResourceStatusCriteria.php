<?php

namespace App\Repositories\Criteria;

use App\Enums\ResourceStatus;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lọc resource theo một trạng thái cụ thể
 * =====================================================================
 *
 * Criteria dùng cho bộ lọc admin, nơi người dùng chọn status từ query string.
 * Giá trị được kiểm tra lại bằng enum trước khi áp vào query, nên request
 * gửi status không hợp lệ sẽ bị bỏ qua thay vì ném exception.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận giá trị status cần lọc
 * - apply(): gắn điều kiện status vào builder
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : string $status lấy từ query của admin
 * - OUTPUT: Builder đã lọc, hoặc giữ nguyên nếu status không hợp lệ
 * =====================================================================
 */
class ResourceStatusCriteria implements CriteriaInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu trạng thái cần lọc, bỏ qua nếu không hợp lệ
     * =====================================================================
     *
     * INPUT:
     * - $status: giá trị status thô từ query
     */
    public function __construct(private readonly string $status) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn điều kiện lọc trạng thái vào builder
     * =====================================================================
     *
     * INPUT:
     * - $model: Builder hiện tại của repository
     * - $repository: repository đang áp dụng criteria
     *
     * OUTPUT:
     * - Builder: đã lọc nếu status hợp lệ, giữ nguyên nếu không
     */
    public function apply($model, RepositoryInterface $repository)
    {
        $status = ResourceStatus::tryFrom($this->status);

        if ($status === null) {
            return $model;
        }

        return $model->where('status', $status);
    }
}
