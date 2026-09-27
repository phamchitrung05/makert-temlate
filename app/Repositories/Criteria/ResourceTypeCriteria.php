<?php

namespace App\Repositories\Criteria;

use App\Enums\ResourceType;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lọc resource theo một loại cụ thể
 * =====================================================================
 *
 * Criteria dùng cho bộ lọc admin, nơi người dùng chọn type từ query string.
 * Giá trị được kiểm tra lại bằng enum trước khi áp vào query, nên request
 * gửi type không hợp lệ sẽ bị bỏ qua thay vì ném exception.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận giá trị type cần lọc
 * - apply(): gắn điều kiện type vào builder
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : string $type lấy từ query của admin
 * - OUTPUT: Builder đã lọc, hoặc giữ nguyên nếu type không hợp lệ
 * =====================================================================
 */
class ResourceTypeCriteria implements CriteriaInterface
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu loại cần lọc
     * =====================================================================
     *
     * INPUT:
     * - $type: giá trị type thô từ query
     */
    public function __construct(private readonly string $type) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn điều kiện lọc loại vào builder
     * =====================================================================
     *
     * INPUT:
     * - $model: Builder hiện tại của repository
     * - $repository: repository đang áp dụng criteria
     *
     * OUTPUT:
     * - Builder: đã lọc nếu type hợp lệ, giữ nguyên nếu không
     */
    public function apply($model, RepositoryInterface $repository)
    {
        $type = ResourceType::tryFrom($this->type);

        if ($type === null) {
            return $model;
        }

        return $model->where('type', $type);
    }
}
