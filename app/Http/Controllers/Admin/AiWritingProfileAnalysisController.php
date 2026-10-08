<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiWritingProfileAnalysisIndexRequest;
use App\Http\Requests\Admin\AiWritingProfileAnalysisRequest;
use App\Http\Resources\AiWritingProfileAnalysisResource;
use App\Http\Resources\AiWritingProfileAnalysisSummaryResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiWritingProfileAnalysis;
use App\Services\Ai\WritingProfiles\WritingProfileAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo/polling/hủy tác vụ phân tích mẫu riêng của admin.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: store(), show(), cancel(), ensureOwner().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tên/bài mẫu/model hoặc UUID analysis.
 * - OUTPUT: API envelope với lifecycle/result preview và liên kết profile nháp.
 * - SIDE EFFECT: dispatch queue hoặc cập nhật cancelled; worker tạo draft khi ready.
 * =====================================================================
 */
final class AiWritingProfileAnalysisController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê hàng đợi analysis của actor hiện tại.
     * =====================================================================
     * Input: page/per_page/status đã validate. Output: metadata an toàn có phân trang.
     * Side effect: chỉ đọc các analysis do actor tạo; không trả nguồn/snapshot/kết quả.
     * =====================================================================
     */
    public function index(AiWritingProfileAnalysisIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $analyses = AiWritingProfileAnalysis::query()
            ->where('created_by', $request->user()->getKey())
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->whereIn('status', $filters['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        return BaseResponse::paginated(AiWritingProfileAnalysisSummaryResource::collection($analyses));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Queue analysis từ bài tham khảo người dùng dán.
     * =====================================================================
     * Input: FormRequest validated/actor. Output: queued task HTTP 202.
     * Side effect: service ghi DB và dispatch sau commit, không chạy AI trong HTTP.
     * =====================================================================
     */
    public function store(AiWritingProfileAnalysisRequest $request, WritingProfileAnalysisService $service): JsonResponse
    {
        $analysis = $service->queue($request->validated(), $request->user()->getKey());

        return BaseResponse::success((new AiWritingProfileAnalysisResource($analysis))->resolve($request), 'Đã đưa bài mẫu vào hàng đợi phân tích.', 202);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Polling analysis của actor còn thời hạn.
     * =====================================================================
     * Input: analysis route binding/actor. Output: safe result hoặc 403/410.
     * Side effect: chỉ đọc database.
     * =====================================================================
     */
    public function show(Request $request, AiWritingProfileAnalysis $analysis): JsonResponse
    {
        $this->ensureOwner($request, $analysis);

        return BaseResponse::success((new AiWritingProfileAnalysisResource($analysis))->resolve($request));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hủy analysis queued/analyzing, response trễ không ghi đè.
     * =====================================================================
     * Input: analysis và actor. Output: cancelled/current terminal task.
     * Side effect: conditional DB update; không thể thu hồi request đã gửi upstream.
     * =====================================================================
     */
    public function cancel(Request $request, AiWritingProfileAnalysis $analysis): JsonResponse
    {
        $this->ensureOwner($request, $analysis);
        AiWritingProfileAnalysis::query()->whereKey($analysis->id)->whereIn('status', ['queued', 'analyzing'])
            ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);

        return BaseResponse::success((new AiWritingProfileAnalysisResource($analysis->refresh()))->resolve($request));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn actor khác và tác vụ hết hạn.
     * =====================================================================
     * Input: request actor và analysis. Output: void hoặc 403/410.
     * Side effect: không sửa DB.
     * =====================================================================
     */
    private function ensureOwner(Request $request, AiWritingProfileAnalysis $analysis): void
    {
        abort_unless((int) $analysis->created_by === (int) $request->user()->getKey(), 403);
        abort_if($analysis->expires_at->isPast(), 410, 'Phân tích bài mẫu đã hết thời hạn.');
    }
}
