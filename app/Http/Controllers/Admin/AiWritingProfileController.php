<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiWritingProfileRequest;
use App\Http\Resources\AiWritingProfileResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiWritingProfile;
use App\Services\Ai\Settings\AiSettingsService;
use App\Services\Ai\WritingProfiles\WritingProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API quản lý profile đã duyệt và options cho form tạo bài.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): danh sách profile quản trị có search và pagination.
 * - options(): trả profile active/enabled và default cho người dùng tạo bài.
 * - store(): tạo profile qua WritingProfileService.
 * - show(): trả chi tiết profile thuộc quyền quản trị.
 * - update(): cập nhật profile theo optimistic version.
 * - destroy(): xóa mềm profile và xử lý default qua service.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : admin HTTP đã qua auth/permission và FormRequest.
 * - OUTPUT: BaseResponse envelope, optimistic 409 và validation 422.
 * - SIDE EFFECT: CRUD service ghi profile; không gọi model tại controller.
 * =====================================================================
 */
final class AiWritingProfileController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Danh sách quản lý profile có phân trang.
     * =====================================================================
     * Input: per_page/page/query tùy chọn. Output: paginated profile resources.
     * Side effect: chỉ đọc database.
     * =====================================================================
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'nullable', 'string', 'max:160']]);
        $profiles = AiWritingProfile::query()->when(filled($filters['search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25);

        return BaseResponse::paginated(AiWritingProfileResource::collection($profiles));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả chỉ profile đang bật cho người có quyền tạo bài/AI.
     * =====================================================================
     * Input: actor HTTP. Output: default ID và items id/name/description/version.
     * Side effect: đọc database/Settings, không trả toàn bộ prompt/profile bài mẫu.
     * =====================================================================
     */
    public function options(Request $request, AiSettingsService $settings): JsonResponse
    {
        abort_unless($request->user()->canAny(['ai_settings.manage', 'posts.manage', 'resources.create']), 403);

        return BaseResponse::success([
            'default_writing_profile_id' => $settings->all()['default_writing_profile_id'] ?? null,
            'items' => AiWritingProfile::query()->where('status', 'active')->where('is_enabled', true)->orderBy('name')->orderBy('id')->get(['id', 'name', 'description', 'version'])->toArray(),
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu profile khi người dùng đã xem/sửa và bấm lưu.
     * =====================================================================
     * Input: values và actor. Output: profile resource HTTP 201.
     * Side effect: gọi CRUD service; không phân tích lại.
     * =====================================================================
     */
    public function store(AiWritingProfileRequest $request, WritingProfileService $service): JsonResponse
    {
        $profile = $service->create($request->validated(), $request->user()->getKey());

        return BaseResponse::created((new AiWritingProfileResource($profile))->resolve($request), 'Đã lưu mẫu văn phong.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xem profile để chỉnh sửa.
     * =====================================================================
     * Input: model route binding. Output: profile allowlisted.
     * Side effect: chỉ đọc database.
     * =====================================================================
     */
    public function show(Request $request, AiWritingProfile $profile): JsonResponse
    {
        return BaseResponse::success((new AiWritingProfileResource($profile))->resolve($request));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sửa hoặc bật/tắt profile với optimistic version.
     * =====================================================================
     * Input: payload partial và version. Output: profile tăng version hoặc HTTP 409.
     * Side effect: cập nhật database qua service.
     * =====================================================================
     */
    public function update(AiWritingProfileRequest $request, AiWritingProfile $profile, WritingProfileService $service): JsonResponse
    {
        $updated = $service->update($profile, $request->validated(), $request->user()->getKey());

        return BaseResponse::success((new AiWritingProfileResource($updated))->resolve($request), 'Đã cập nhật mẫu văn phong.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa profile sau kiểm optimistic version.
     * =====================================================================
     * Input: version/profile/actor. Output: HTTP 204.
     * Side effect: xóa profile, gỡ mặc định; run snapshots không đổi.
     * =====================================================================
     */
    public function destroy(AiWritingProfileRequest $request, AiWritingProfile $profile, WritingProfileService $service): \Symfony\Component\HttpFoundation\Response
    {
        $service->delete($profile, (int) $request->validated('version'), $request->user()->getKey());

        return BaseResponse::noContent();
    }
}
