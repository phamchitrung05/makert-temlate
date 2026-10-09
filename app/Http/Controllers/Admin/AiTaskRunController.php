<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiTaskRunIndexRequest;
use App\Http\Resources\AiTaskRunResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiTaskRun;
use App\Services\Ai\Runs\AiTaskRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API list/detail/cancel tracker AI theo actor hiện tại.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): reconcile task active, lọc status/task_type và trả pagination.
 * - show(): kiểm owner/quyền, reconcile một tracker rồi serialize metadata.
 * - cancel(): ủy quyền cho adapter hủy đúng source/generation và trả trạng thái.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : filter task_type/status, UUID task và actor xác thực.
 * - OUTPUT: summary phân trang hoặc status sau hủy; không trả payload nghiệp vụ.
 * - SIDE EFFECT: đọc tracker hoặc hủy đúng task owner qua service.
 * - AUTHORIZATION: mọi action đều scope user_id, owner khác nhận 404.
 * =====================================================================
 */
final class AiTaskRunController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê tracker của actor với filter bounded và pagination
     * =====================================================================
     * INPUT: FormRequest filter và admin actor.
     * OUTPUT: tracker summary đã scope theo module/quyền, phân trang mới nhất trước.
     * SIDE EFFECT: reconcile các task active với nguồn nghiệp vụ trước khi đọc.
     * EXCEPTION/TRANSACTION: service mở transaction ngắn từng nguồn; không gọi provider.
     * =====================================================================
     */
    public function index(AiTaskRunIndexRequest $request, AiTaskRunService $service): JsonResponse
    {
        $filters = $request->validated();
        abort_unless($service->canUse($request->user()), 403);
        $service->reconcileActive($request->user());
        $tasks = $service->visibleTo($request->user())
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', $status))
            ->when($filters['task_type'] ?? null, fn ($query, $types) => $query->whereIn('task_type', $types))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 100, ['*'], 'page', $filters['page'] ?? 1);

        return BaseResponse::paginated(AiTaskRunResource::collection($tasks));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc một tracker đúng owner/quyền module
     * =====================================================================
     * INPUT: UUID task và admin actor.
     * OUTPUT: detail metadata không chứa payload nghiệp vụ.
     * SIDE EFFECT: reconcile task với nguồn trước khi serialize.
     * EXCEPTION/TRANSACTION: owner/module sai nhận 404/403 theo service.
     * =====================================================================
     */
    public function show(Request $request, AiTaskRun $taskRun, AiTaskRunService $service): JsonResponse
    {
        abort_unless((int) $taskRun->user_id === (int) $request->user()->getKey(), 404);
        abort_unless($service->visibleTo($request->user())->whereKey($taskRun->id)->exists(), 403);
        $taskRun = $service->reconcile($taskRun);

        return BaseResponse::success((new AiTaskRunResource($taskRun))->resolve($request));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hủy task dùng chung và cập nhật đúng nguồn/generation
     * =====================================================================
     * INPUT: UUID task thuộc actor.
     * OUTPUT: tracker cancelled hoặc terminal hiện tại.
     * SIDE EFFECT: service khóa nguồn, cập nhật lifecycle và dọn thumbnail nếu cần.
     * EXCEPTION/TRANSACTION: owner/quyền/stale generation nhận lỗi an toàn; không thu hồi HTTP đã gửi.
     * =====================================================================
     */
    public function cancel(Request $request, AiTaskRun $taskRun, AiTaskRunService $service): JsonResponse
    {
        abort_unless((int) $taskRun->user_id === (int) $request->user()->getKey(), 404);

        return BaseResponse::success((new AiTaskRunResource($service->cancel($taskRun, $request->user())))->resolve($request));
    }
}
