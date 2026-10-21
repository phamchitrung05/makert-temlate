<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisualStyle\AnalyzeVisualStyleRequest;
use App\Http\Requests\Admin\VisualStyle\StoreVisualStyleRequest;
use App\Http\Requests\Admin\VisualStyle\UpdateDnaRequest;
use App\Http\Requests\Admin\VisualStyle\UpdateVisualStyleRequest;
use App\Http\Resources\VisualStyleResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiImageProfile;
use App\Services\Ai\VisualStyle\VisualStyleAnalyzerService;
use App\Services\Ai\VisualStyle\VisualStyleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP orchestration cho Visual Style Engine (ai_image_profiles).
 * =====================================================================
 * Controller chỉ điều phối: FormRequest validate + authorize, service xử lý
 * nghiệp vụ (queue phân tích, CRUD, DNA, lifecycle), Resource/BaseResponse
 * định hình output. Không chứa transaction, provider call hay business rule
 * nhiều bước — chúng nằm ở App\Services\Ai\VisualStyle.
 * CÁC HÀM/METHOD TRONG FILE:
 * - index/store/show/update/destroy: CRUD style theo owner.
 * - analyze(): queue phân tích ảnh mẫu → DNA qua AnalyzeVisualStyleJob.
 * - updateDna(): lưu DNA đã chỉnh với expected_version optimistic lock.
 * - activate()/archive(): lifecycle style.
 * - duplicate(): nhân bản style + shots sang bản draft.
 * - owner(): chặn truy cập style của người khác.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : FormRequest/Request đã authenticated + permission middleware.
 * - OUTPUT: BaseResponse JSON wrap VisualStyleResource.
 * - SIDE EFFECT: ủy quyền service; không ghi DB trực tiếp ngoài service.
 * =====================================================================
 */
final class VisualStyleController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê style của actor với search/filter/paginate.
     * =====================================================================
     * Input: query search/status/per_page. Output: BaseResponse paginated
     * VisualStyleResource kèm shots_count.
     * Side effect: chỉ đọc DB qua service.
     * =====================================================================
     */
    public function index(Request $request, VisualStyleService $service): JsonResponse
    {
        $page = $service->index($request->only(['search', 'status', 'per_page']), (int) $request->user()->id);

        return BaseResponse::paginated(VisualStyleResource::collection($page), 'Danh sách visual style.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo style thủ công status=draft (không phân tích ảnh).
     * =====================================================================
     * Input: StoreVisualStyleRequest (name/category/dna?). Output: 201 style.
     * Side effect: INSERT qua service; DNA nếu có đã validate trong service.
     * =====================================================================
     */
    public function store(StoreVisualStyleRequest $request, VisualStyleService $service): JsonResponse
    {
        $style = $service->store($request->validated(), (int) $request->user()->id);

        return BaseResponse::created(new VisualStyleResource($style), 'Đã tạo visual style.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chi tiết style kèm danh sách shots.
     * =====================================================================
     * Input: style route-bound. Output: VisualStyleResource có shots.
     * Side effect: eager load shots; chặn owner khác.
     * =====================================================================
     */
    public function show(Request $request, AiImageProfile $visualStyle): JsonResponse
    {
        $this->owner($request, $visualStyle);

        return BaseResponse::success(new VisualStyleResource($visualStyle->load('shots')));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật metadata style (name/category/is_enabled).
     * =====================================================================
     * Input: UpdateVisualStyleRequest + style. Output: style đã refresh.
     * Side effect: UPDATE qua service; bật is_enabled khi chưa active bị reject.
     * =====================================================================
     */
    public function update(UpdateVisualStyleRequest $request, AiImageProfile $visualStyle, VisualStyleService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $style = $service->update($visualStyle, $request->validated());

        return BaseResponse::success(new VisualStyleResource($style), 'Đã lưu visual style.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa style và file tham chiếu; chặn khi đang queued/analyzing.
     * =====================================================================
     * Input: style. Output: message xóa.
     * Side effect: DELETE style + file local qua service; shots cascade DB.
     * =====================================================================
     */
    public function destroy(Request $request, AiImageProfile $visualStyle, VisualStyleService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $service->destroy($visualStyle);

        return BaseResponse::success(null, 'Đã xóa visual style.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Queue phân tích ảnh mẫu thành Style DNA mới.
     * =====================================================================
     * Input: AnalyzeVisualStyleRequest multipart (name/model_id/image/category?).
     * Output: 202 style status=queued; worker cập nhật analyzed/failed sau.
     * Side effect: lưu file local + INSERT profile + dispatch job qua service.
     * =====================================================================
     */
    public function analyze(AnalyzeVisualStyleRequest $request, VisualStyleAnalyzerService $analyzer): JsonResponse
    {
        $style = $analyzer->queue($request->validated(), $request->file('image'), (int) $request->user()->id);

        return BaseResponse::success(new VisualStyleResource($style), 'Đã xếp hàng phân tích phong cách.', 202);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu DNA đã chỉnh với optimistic lock expected_version.
     * =====================================================================
     * Input: UpdateDnaRequest + style. Output: style version++.
     * Side effect: UPDATE qua service; conflict version trả ValidationException.
     * =====================================================================
     */
    public function updateDna(UpdateDnaRequest $request, AiImageProfile $visualStyle, VisualStyleService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $validated = $request->validated();
        $style = $service->updateDna($visualStyle, $validated['dna'], (int) $validated['expected_version']);

        return BaseResponse::success(new VisualStyleResource($style), 'Đã lưu Style DNA.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Activate style khi đã có DNA hợp lệ.
     * =====================================================================
     * Input: style. Output: style status=active + is_enabled.
     * Side effect: UPDATE qua service; style chưa analyzed/DNA rỗng bị reject.
     * =====================================================================
     */
    public function activate(Request $request, AiImageProfile $visualStyle, VisualStyleService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);

        return BaseResponse::success(new VisualStyleResource($service->activate($visualStyle)), 'Đã activate visual style.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Archive style, tắt cờ dùng cho generation.
     * =====================================================================
     * Input: style. Output: style status=archived.
     * Side effect: UPDATE qua service; style đang phân tích bị chặn.
     * =====================================================================
     */
    public function archive(Request $request, AiImageProfile $visualStyle, VisualStyleService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);

        return BaseResponse::success(new VisualStyleResource($service->archive($visualStyle)), 'Đã archive visual style.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Nhân bản style cùng DNA + shots sang bản draft của actor.
     * =====================================================================
     * Input: style nguồn. Output: 201 style mới status=draft.
     * Side effect: transaction INSERT style + shots qua service.
     * =====================================================================
     */
    public function duplicate(Request $request, AiImageProfile $visualStyle, VisualStyleService $service): JsonResponse
    {
        $this->owner($request, $visualStyle);
        $copy = $service->duplicate($visualStyle, (int) $request->user()->id);

        return BaseResponse::created(new VisualStyleResource($copy), 'Đã nhân bản visual style.');
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
