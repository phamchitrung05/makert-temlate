<?php

namespace App\Http\Controllers\Admin\Resource;

use App\Actions\Resources\CreateResourceVersionAction;
use App\Actions\Resources\DeleteResourceVersionAction;
use App\Actions\Resources\ArchiveResourceVersionAction;
use App\Actions\Resources\MarkResourceVersionReadyAction;
use App\Actions\Resources\PromoteResourceVersionAction;
use App\Actions\Resources\UpdateResourceVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resources\ResourceVersionCreateRequest;
use App\Http\Requests\Admin\Resources\ResourceVersionUpdateRequest;
use App\Http\Resources\Resources\ResourceVersionResource;
use App\Http\Responses\BaseResponse;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\Resources\ResourceVersionReadyGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD và lifecycle của Resource Version.
 * =====================================================================
 * Controller eager-load resource/media, giao quyền từ route middleware và
 * ủy quyền ghi dữ liệu cho Action; không chứa quy tắc nghiệp vụ chính.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): liệt kê phiên bản theo resource.
 * - store(): tạo phiên bản mới.
 * - show(): trả chi tiết phiên bản.
 * - update(): cập nhật phiên bản.
 * - destroy(): gỡ media và xóa phiên bản trong transaction.
 * - ready(): chuyển phiên bản sang trạng thái sẵn sàng.
 * - promote(): đặt version ready thành mặc định.
 * - rollback(): promote version ready cũ thành mặc định.
 * - archive(): lưu trữ version không còn mặc định.
 * - adminUser(): lấy admin hiện tại hoặc trả 401.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request, ResourceVersion và các Action/Service liên quan.
 * - OUTPUT: JsonResponse/204; ghi database qua Action hoặc transaction.
 * =====================================================================
 */
class ResourceVersionController extends Controller
{
    /**
     * Liệt kê các phiên bản mới nhất, có thể lọc theo resource.
     *
     * Input: Request có resource_id và per_page tùy chọn.
     * Output: JsonResponse phân trang; chỉ đọc database.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ResourceVersion::query()
            ->with(['resource', 'template.screens.mediaAssetUsages.mediaAsset.media', 'mediaAssetUsages.mediaAsset.media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('resource_id')) {
            $query->where('resource_id', $request->integer('resource_id'));
        }

        return BaseResponse::paginated(
            ResourceVersionResource::collection($query->paginate($this->resolvePerPage($request))),
            'Danh sách resource version.',
        );
    }

    /**
     * Tạo Resource Version và trả payload đã eager-load.
     *
     * Input: ResourceVersionCreateRequest và CreateResourceVersionAction.
     * Output: JsonResponse 201; Action ghi phiên bản và media liên quan.
     */
    public function store(
        ResourceVersionCreateRequest $request,
        CreateResourceVersionAction $action,
    ): JsonResponse {
        $actor = $this->adminUser($request);
        $version = $action->handle($request->validated(), $actor->getKey());

        return BaseResponse::created(ResourceVersionResource::make($version), 'Tạo resource version thành công.');
    }

    /**
     * Trả chi tiết một Resource Version cùng resource và media.
     *
     * Input: ResourceVersion đã được route model binding.
     * Output: JsonResponse; chỉ đọc database.
     */
    public function show(ResourceVersion $resourceVersion, ResourceVersionReadyGate $gate): JsonResponse
    {
        $resourceVersion->loadMissing(['resource', 'template.screens.mediaAssetUsages.mediaAsset.media', 'mediaAssetUsages.mediaAsset.media']);

        $payload = ResourceVersionResource::make($resourceVersion)->resolve(request());
        $canReady = false;
        try {
            $canReady = $resourceVersion->status->value === 'draft' && $gate->assert($resourceVersion) !== null;
        } catch (\Throwable) {
            // Capability chỉ là gợi ý; MarkResourceVersionReadyAction kiểm tra lại dưới lock.
        }
        $payload['capabilities'] = [
            'can_ready' => $canReady,
            'can_edit' => $resourceVersion->status->value === 'draft',
            'can_process' => $resourceVersion->status->value !== 'archived',
        ];

        return BaseResponse::success($payload, 'Chi tiết resource version.');
    }

    /**
     * Cập nhật Resource Version qua Action chuyên trách.
     *
     * Input: request hợp lệ, ResourceVersion và UpdateResourceVersionAction.
     * Output: JsonResponse; Action ghi database và media nếu có.
     */
    public function update(
        ResourceVersionUpdateRequest $request,
        ResourceVersion $resourceVersion,
        UpdateResourceVersionAction $action,
    ): JsonResponse {
        $version = $action->handle(
            $resourceVersion,
            $request->validated(),
            $this->adminUser($request)->getKey(),
        );

        return BaseResponse::success(ResourceVersionResource::make($version), 'Cập nhật resource version thành công.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa mềm ResourceVersion qua action có guard retention
     * =====================================================================
     * INPUT: Request đã qua resource_versions.manage, version và delete action.
     * OUTPUT: HTTP 204 sau khi action xóa mềm thành công.
     * SIDE EFFECT: Action tháo usage/snapshot và ghi deleted_at/audit.
     * EXCEPTION/TRANSACTION: Action khóa version và rollback nếu default,
     * ready hoặc lịch sử download yêu cầu retention; lỗi validation trả 422.
     * =====================================================================
     */
    public function destroy(
        Request $request,
        ResourceVersion $resourceVersion,
        DeleteResourceVersionAction $action,
    ): Response {
        $action->handle($resourceVersion, $this->adminUser($request)->getKey());

        return BaseResponse::noContent();
    }

    /**
     * Đánh dấu Resource Version đã sẵn sàng qua Action.
     *
     * Input: Request, ResourceVersion và MarkResourceVersionReadyAction.
     * Output: JsonResponse; Action ghi trạng thái và audit.
     */
    public function ready(
        Request $request,
        ResourceVersion $resourceVersion,
        MarkResourceVersionReadyAction $action,
    ): JsonResponse {
        $version = $action->handle($resourceVersion, $this->adminUser($request)->getKey());

        return BaseResponse::success(ResourceVersionResource::make($version), 'Resource version đã sẵn sàng.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đặt version ready thành version mặc định của resource
     * =====================================================================
     * INPUT: Request, ResourceVersion và PromoteResourceVersionAction.
     * OUTPUT: JsonResponse chứa version mặc định mới.
     * SIDE EFFECT: Khóa resource, demote default cũ, ghi audit/cache.
     * EXCEPTION/TRANSACTION: ValidationException nếu version chưa đủ gate.
     * =====================================================================
     */
    public function promote(
        Request $request,
        ResourceVersion $resourceVersion,
        PromoteResourceVersionAction $action,
    ): JsonResponse {
        $version = $action->handle($resourceVersion, $this->adminUser($request)->getKey());

        return BaseResponse::success(ResourceVersionResource::make($version), 'Resource version đã được đặt mặc định.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback bằng cách promote version ready non-default
     * =====================================================================
     * INPUT: Request, ResourceVersion cũ và PromoteResourceVersionAction.
     * OUTPUT: JsonResponse chứa version mặc định sau rollback.
     * SIDE EFFECT: Khóa resource, đổi default và ghi audit/cache.
     * EXCEPTION/TRANSACTION: ValidationException nếu rollback stale/không hợp lệ.
     * =====================================================================
     */
    public function rollback(
        Request $request,
        ResourceVersion $resourceVersion,
        PromoteResourceVersionAction $action,
    ): JsonResponse {
        $version = $action->handle($resourceVersion, $this->adminUser($request)->getKey(), true);

        return BaseResponse::success(ResourceVersionResource::make($version), 'Resource version đã rollback.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Archive version không còn là default
     * =====================================================================
     * INPUT: Request, ResourceVersion và ArchiveResourceVersionAction.
     * OUTPUT: JsonResponse version archived, package/usage vẫn giữ nguyên.
     * SIDE EFFECT: Ghi status/audit và invalidate catalog/preview cache.
     * EXCEPTION/TRANSACTION: ValidationException nếu default, download hoặc
     * processing active còn tồn tại.
     * =====================================================================
     */
    public function archive(
        Request $request,
        ResourceVersion $resourceVersion,
        ArchiveResourceVersionAction $action,
    ): JsonResponse {
        $version = $action->handle($resourceVersion, $this->adminUser($request)->getKey());

        return BaseResponse::success(ResourceVersionResource::make($version), 'Resource version đã được archive.');
    }

    /**
     * Resolve admin hiện tại cho các thao tác cần actor.
     *
     * Input: Request chứa user đã xác thực.
     * Output: User admin; abort 401 nếu guard không trả về User.
     */
    private function adminUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }
}
