<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiCandidateApproveRequest;
use App\Http\Requests\Admin\AiCandidateRejectRequest;
use App\Http\Requests\Admin\AiSessionIndexRequest;
use App\Http\Responses\BaseResponse;
use App\Models\AiImport;
use App\Services\Ai\Content\AiContentReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP boundary xem nguồn/lịch sử và duyệt/từ chối bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: show(), history(), approve(), reject().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): admin request -> BaseResponse từ review service.
 * SIDE EFFECT: chỉ approve/reject ghi nghiệp vụ; không dispatch hoặc gọi provider.
 * =====================================================================
 */
class AiContentReviewController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả dữ liệu so sánh và trạng thái review từ service.
     * INPUT: admin Request và candidate binding theo UUID.
     * OUTPUT: JsonResponse allowlist hoặc lỗi quyền/owner/target từ service.
     * SIDE EFFECT: không ghi DB, fetch nguồn hoặc mở transaction.
     * =====================================================================
     */
    public function show(Request $request, AiImport $aiImport, AiContentReviewService $review): JsonResponse
    {
        return BaseResponse::success($review->view($request->user(), $aiImport));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả lịch sử Spatie Activitylog có phân trang.
     * INPUT: admin/candidate và query đã validate.
     * OUTPUT: BaseResponse items + meta.pagination; lỗi authorize truyền lên.
     * SIDE EFFECT: service chỉ đọc DB/causer, không ghi lịch sử giả.
     * =====================================================================
     */
    public function history(AiSessionIndexRequest $request, AiImport $aiImport, AiContentReviewService $review): JsonResponse
    {
        $data = $request->validated();
        $history = $review->history($request->user(), $aiImport, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 20));

        return BaseResponse::success($history['items'], null, 200, ['pagination' => $history['pagination']]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển quyết định duyệt đã validate vào service.
     * INPUT: authenticated admin, candidate, fields/hai version/lý do.
     * OUTPUT: Post draft và review server; lỗi quyền/stale/validation truyền lên.
     * SIDE EFFECT: transaction ghi Post/provenance/review/audit thuộc service.
     * =====================================================================
     */
    public function approve(AiCandidateApproveRequest $request, AiImport $aiImport, AiContentReviewService $review): JsonResponse
    {
        return BaseResponse::success($review->approve($request->user(), $aiImport, $request->validated()), 'Đã duyệt và tạo Post nháp.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển quyết định từ chối đã validate vào service.
     * INPUT: authenticated admin, candidate, hai version/lý do bắt buộc.
     * OUTPUT: rejected và hash mới; lỗi quyền/stale/validation truyền lên.
     * SIDE EFFECT: service ghi review/audit trong transaction, không tạo Post.
     * =====================================================================
     */
    public function reject(AiCandidateRejectRequest $request, AiImport $aiImport, AiContentReviewService $review): JsonResponse
    {
        return BaseResponse::success($review->reject($request->user(), $aiImport, $request->validated()), 'Đã từ chối nội dung AI.');
    }
}
