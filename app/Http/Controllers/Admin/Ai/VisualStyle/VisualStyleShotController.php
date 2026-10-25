<?php

namespace App\Http\Controllers\Admin\Ai\VisualStyle;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisualStyle\ReorderShotsRequest;
use App\Http\Requests\Admin\VisualStyle\StoreShotRequest;
use App\Http\Requests\Admin\VisualStyle\UpdateShotRequest;
use App\Http\Resources\VisualStyleShotResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiImageProfile;
use App\Models\VisualStyleShot;
use App\Services\Ai\VisualStyle\VisualStyleShotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP orchestration cho Shot Recipe nested theo Visual Style.
 * =====================================================================
 * Route dạng /admin/ai/visual-styles/{style}/shots/{shot}; controller chỉ
 * điều phối FormRequest → service → Resource, còn kiểm tra shot thuộc đúng
 * style, code unique và enum override nằm ở VisualStyleShotService/Definition.
 * CÁC HÀM/METHOD TRONG FILE:
 * - index/store/update/destroy: CRUD shots của style.
 * - reorder(): sắp lại sort_order theo ordered_ids.
 * - owner(): chặn truy cập style của người khác.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : FormRequest/Request đã authenticated + permission middleware.
 * - OUTPUT: BaseResponse JSON wrap VisualStyleShotResource.
 * - SIDE EFFECT: ủy quyền service; không ghi DB trực tiếp.
 * =====================================================================
 */
final class VisualStyleShotController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê shot recipe của style theo sort_order.
     * =====================================================================
     * Input: style route-bound. Output: danh sách VisualStyleShotResource.
     * Side effect: chỉ đọc DB qua service.
     * =====================================================================
     */
    public function index(Request $request, AiImageProfile $visualStyle, VisualStyleShotService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);

        return BaseResponse::success(VisualStyleShotResource::collection($service->list($visualStyle)));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo shot recipe mới cho style.
     * =====================================================================
     * Input: StoreShotRequest + style. Output: 201 shot đã tạo.
     * Side effect: INSERT qua service; code trùng/override sai enum bị reject.
     * =====================================================================
     */
    public function store(StoreShotRequest $request, AiImageProfile $visualStyle, VisualStyleShotService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $shot = $service->create($visualStyle, $request->validated());

        return BaseResponse::success(new VisualStyleShotResource($shot), config('messages.visual_style.shot_created'), 201);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật shot recipe thuộc đúng style.
     * =====================================================================
     * Input: UpdateShotRequest + style + shot. Output: shot đã refresh.
     * Side effect: UPDATE qua service; shot khác style trả 404.
     * =====================================================================
     */
    public function update(UpdateShotRequest $request, AiImageProfile $visualStyle, VisualStyleShot $shot, VisualStyleShotService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $shot = $service->update($visualStyle, $shot, $request->validated());

        return BaseResponse::success(new VisualStyleShotResource($shot), config('messages.visual_style.shot_updated'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa shot recipe thuộc đúng style.
     * =====================================================================
     * Input: style + shot. Output: message xóa.
     * Side effect: DELETE qua service; shot khác style trả 404.
     * =====================================================================
     */
    public function destroy(Request $request, AiImageProfile $visualStyle, VisualStyleShot $shot, VisualStyleShotService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $service->delete($visualStyle, $shot);

        return BaseResponse::success(null, config('messages.visual_style.shot_deleted'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sắp xếp lại sort_order theo danh sách id client gửi.
     * =====================================================================
     * Input: ReorderShotsRequest (ordered_ids) + style. Output: shots mới.
     * Side effect: một transaction UPDATE qua service; danh sách id không khớp
     * toàn bộ shot của style bị reject.
     * =====================================================================
     */
    public function reorder(ReorderShotsRequest $request, AiImageProfile $visualStyle, VisualStyleShotService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $shots = $service->reorder($visualStyle, $request->validated()['ordered_ids']);

        return BaseResponse::success(VisualStyleShotResource::collection($shots), config('messages.visual_style.shots_reordered'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn truy cập style của user khác.
     * =====================================================================
     * Input: request + style. Output: void hoặc 403.
     * Side effect: không ghi DB.
     * =====================================================================
     */
    private function owner(Request $request, AiImageProfile $style): void
    {
        abort_unless((int) $style->created_by === (int) $request->user()->id, 403);
    }
}
