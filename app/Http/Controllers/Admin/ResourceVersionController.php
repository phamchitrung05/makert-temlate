<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Resources\CreateResourceVersionAction;
use App\Actions\Resources\MarkResourceVersionReadyAction;
use App\Actions\Resources\UpdateResourceVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resources\ResourceVersionCreateRequest;
use App\Http\Requests\Admin\Resources\ResourceVersionUpdateRequest;
use App\Http\Resources\Resources\ResourceVersionResource;
use App\Http\Responses\BaseResponse;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['resource', 'mediaAssetUsages.mediaAsset.media'])
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
    public function show(ResourceVersion $resourceVersion): JsonResponse
    {
        $resourceVersion->loadMissing(['resource', 'mediaAssetUsages.mediaAsset.media']);

        return BaseResponse::success(
            ResourceVersionResource::make($resourceVersion),
            'Chi tiết resource version.',
        );
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
     * Gỡ toàn bộ media usage rồi xóa Resource Version atomically.
     *
     * Input: Request, ResourceVersion và MediaAssetUsageService.
     * Output: HTTP 204; transaction rollback khi thao tác thất bại.
     */
    public function destroy(
        Request $request,
        ResourceVersion $resourceVersion,
        MediaAssetUsageService $mediaAssetUsageService,
    ): Response {
        $actor = $this->adminUser($request);

        DB::transaction(function () use ($actor, $mediaAssetUsageService, $resourceVersion): void {
            $mediaAssetUsageService->detachAll($actor, $resourceVersion);
            $resourceVersion->delete();
        });

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
