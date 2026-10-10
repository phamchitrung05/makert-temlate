<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình registry adapter cho tracker task AI dùng chung.
 * =====================================================================
 * Mỗi model nghiệp vụ có worker AI khai báo một adapter tại đây để service,
 * API và popup dùng chung mà không phải biết schema riêng của model.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có function; file chỉ trả về danh sách adapter cấu hình.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : FQCN adapter implement AiTaskRunAdapter.
 * - OUTPUT: mảng cấu hình được AppServiceProvider đăng ký vào container.
 * - SIDE EFFECT: không query, ghi database hoặc gọi provider.
 * =====================================================================
 */

use App\Services\Ai\Runs\Adapters\AiImportTaskAdapter;
use App\Services\Ai\Runs\Adapters\AiWritingProfileAnalysisTaskAdapter;

return [
    /*
     * Thêm adapter class vào danh sách này khi một model mới có worker AI.
     * Adapter phải implement AiTaskRunAdapter và tự whitelist metadata/cancel.
     */
    'adapters' => [
        AiWritingProfileAnalysisTaskAdapter::class,
        AiImportTaskAdapter::class,
    ],
];
