<?php

namespace App\Repositories\Contracts;

use Prettus\Repository\Contracts\RepositoryInterface as PrettusRepositoryInterface;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng truy cập dữ liệu công nghệ
 * =====================================================================
 *
 * Interface kế thừa hợp đồng của prettus/l5-repository cho bảng technologies.
 * Controller chỉ type-hint vào interface này.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không khai báo method mới; toàn bộ hợp đồng đến từ interface của gói
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : các tham số truy vấn và mảng attributes từ controller
 * - OUTPUT: Technology, Collection hoặc Paginator tuỳ method được gọi
 * =====================================================================
 */
interface TechnologyRepositoryInterface extends PrettusRepositoryInterface {}
