<?php

namespace App\Http\Controllers;

use App\Http\Responses\BaseResponse;
use Illuminate\Http\JsonResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp liveness endpoint cho API của ứng dụng
 * =====================================================================
 *
 * Controller này trả trạng thái tối thiểu của HTTP API để local development,
 * reverse proxy và monitoring kiểm tra ứng dụng đã khởi động. Endpoint không
 * yêu cầu authentication và không trả secret hoặc thông tin người dùng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __invoke(): trả JSON status của API
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP GET /api/health không cần request body hoặc Bearer token
 * - OUTPUT: JSON gồm status, service và timestamp; HTTP 200 khi ứng dụng sống
 * =====================================================================
 */
final class HealthController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả trạng thái liveness của API
     * =====================================================================
     *
     * INPUT:
     * - Không có input nghiệp vụ; route chỉ cần nhận HTTP GET
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 theo BaseResponse với status `ok`, service `api` và timestamp ISO-8601
     *
     * SIDE EFFECT:
     * - Không ghi database, không tạo session và không phát event/job
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction và không phát sinh exception nghiệp vụ
     * =====================================================================
     */
    public function __invoke(): JsonResponse
    {
        return BaseResponse::success([
            'status' => 'ok',
            'service' => 'api',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
