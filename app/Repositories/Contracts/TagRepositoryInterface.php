<?php

namespace App\Repositories\Contracts;

use Prettus\Repository\Contracts\RepositoryInterface as PrettusRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng truy cập dữ liệu tag
 * =====================================================================
 *
 * Interface kế thừa hợp đồng của prettus/l5-repository cho taxonomy tag.
 * Controller chỉ type-hint vào interface này.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không khai báo method mới; toàn bộ hợp đồng đến từ interface của gói
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : các tham số truy vấn và mảng attributes từ controller
 * - OUTPUT: Tag, Collection hoặc Paginator tuỳ method được gọi
 * =====================================================================
 */
interface TagRepositoryInterface extends PrettusRepositoryInterface {}
