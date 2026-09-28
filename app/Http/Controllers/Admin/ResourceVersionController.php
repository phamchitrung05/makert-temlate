<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Resources\CreateResourceVersionAction;
use App\Actions\Resources\MarkResourceVersionReadyAction;
use App\Actions\Resources\UpdateResourceVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResourceVersionCreateRequest;
use App\Http\Requests\Admin\ResourceVersionUpdateRequest;
use App\Http\Resources\ResourceVersionResource;
use App\Http\Responses\BaseResponse;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Admin CRUD và lifecycle cho Resource Version. */
class ResourceVersionController extends Controller
{
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

    public function store(
        ResourceVersionCreateRequest $request,
        CreateResourceVersionAction $action,
    ): JsonResponse {
        $actor = $this->adminUser($request);
        $version = $action->handle($request->validated(), $actor->getKey());

        return BaseResponse::created(ResourceVersionResource::make($version), 'Tạo resource version thành công.');
    }

    public function show(ResourceVersion $resourceVersion): JsonResponse
    {
        $resourceVersion->loadMissing(['resource', 'mediaAssetUsages.mediaAsset.media']);

        return BaseResponse::success(
            ResourceVersionResource::make($resourceVersion),
            'Chi tiết resource version.',
        );
    }

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

    public function ready(
        Request $request,
        ResourceVersion $resourceVersion,
        MarkResourceVersionReadyAction $action,
    ): JsonResponse {
        $version = $action->handle($resourceVersion, $this->adminUser($request)->getKey());

        return BaseResponse::success(ResourceVersionResource::make($version), 'Resource version đã sẵn sàng.');
    }

    private function adminUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }
}
