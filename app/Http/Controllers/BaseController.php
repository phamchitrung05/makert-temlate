<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Base HTTP controller dùng chung cho toàn ứng dụng
 * =====================================================================
 *
 * Lớp này chỉ giữ các helper thuần HTTP được nhiều controller sử dụng. Nó
 * không chứa query, transaction hoặc business rule của một domain cụ thể;
 * các mutation nhiều bước vẫn phải đi qua Action/Service tương ứng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resolvePerPage(): chuẩn hóa và giới hạn số bản ghi mỗi trang
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP Request và tham số phân trang từ client
 * - OUTPUT: giá trị HTTP đã chuẩn hóa để controller con sử dụng
 * =====================================================================
 */
abstract class BaseController
{
    /**
     * Số bản ghi mặc định của một trang khi client không truyền per_page.
     */
    protected const DEFAULT_PER_PAGE = 15;

    /**
     * Giới hạn tối đa để tránh endpoint danh sách tải quá nhiều bản ghi.
     */
    protected const MAX_PER_PAGE = 100;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa số bản ghi mỗi trang từ query string
     * =====================================================================
     *
     * INPUT:
     * - $request: request hiện tại, có thể chứa query `per_page`
     * - $maximum: giới hạn riêng của controller; null dùng MAX_PER_PAGE
     *
     * OUTPUT:
     * - int: giá trị trong khoảng 1..maximum
     *
     * SIDE EFFECT:
     * - Không có; chỉ đọc query string
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction và không ném exception nghiệp vụ
     * =====================================================================
     */
    protected function resolvePerPage(Request $request, ?int $maximum = null): int
    {
        $limit = $maximum ?? static::MAX_PER_PAGE;
        $perPage = (int) $request->integer('per_page', static::DEFAULT_PER_PAGE);

        return max(1, min($perPage, $limit));
    }
}
