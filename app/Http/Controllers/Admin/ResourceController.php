<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Resources\ArchiveResourceAction;
use App\Actions\Resources\CreateResourceAction;
use App\Actions\Resources\PublishResourceAction;
use App\Actions\Resources\UpdateResourceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResourceCreateRequest;
use App\Http\Requests\Admin\ResourceUpdateRequest;
use App\Http\Resources\ResourceItem;
use App\Http\Resources\ResourceSummary;
use App\Http\Responses\BaseResponse;
use App\Models\Resource;
use App\Models\User;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Repositories\Criteria\ResourceCategoryCriteria;
use App\Repositories\Criteria\ResourceStatusCriteria;
use App\Repositories\Criteria\ResourceTypeCriteria;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD resource cho admin
 * =====================================================================
 *
 * Controller chỉ làm ba việc: đọc input, gọi Action/Repository, trả về
 * BaseResponse. Toàn bộ validate nằm trong ResourceValidator do repository
 * gọi, còn transaction và ghi slug nằm trong các Action. Quyền truy cập do
 * middleware `permission:` trên route đảm bảo, không kiểm tra lại ở đây.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): danh sách resource có phân trang, tìm kiếm và lọc phía server
 * - store(): tạo resource mới qua CreateResourceAction
 * - show(): trả chi tiết một resource
 * - update(): cập nhật resource qua UpdateResourceAction
 * - destroy(): xoá mềm resource
 * - publish(): chuyển resource sang published
 * - archive(): chuyển resource sang archived
 * - applyFilters(): gắn criteria lọc theo status, type và category_id
 * - scopeListing(): eager load quan hệ admin danh sách cần hiển thị
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua auth:sanctum, abilities:admin và permission
 * - OUTPUT: JsonResponse hoặc Response theo envelope BaseResponse
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; các Action quản lý transaction cho mutation nhiều bước
 * - Repository chỉ cung cấp data access và validator, không tự mở transaction
 * - ValidatorException và DomainException được fromException() chuyển thành
 *   response lỗi có cấu trúc
 * =====================================================================
 */
class ResourceController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách resource có phân trang và bộ lọc
     * =====================================================================
     *
     * INPUT:
     * - $request: query hỗ trợ per_page, search, orderBy, sortedBy, status,
     *   type và category_id
     * - $repository: repository resource đã được bind trong container
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với data là ResourceSummary và meta.pagination
     *
     * SIDE EFFECT:
     * - Truy vấn database; không ghi dữ liệu
     */
    public function index(Request $request, ResourceRepositoryInterface $repository): JsonResponse
    {
        $this->applyFilters($repository, $request);

        $paginator = $this->scopeListing(
            $repository,
            $this->resolvePerPage($request),
        );

        return BaseResponse::paginated(
            ResourceSummary::collection($paginator),
            'Danh sách tài nguyên.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo resource mới
     * =====================================================================
     *
     * INPUT:
     * - $request: payload resource đã được ResourceValidator kiểm tra
     * - $action: action tạo resource kèm slug và taxonomy
     *
     * OUTPUT:
     * - JsonResponse HTTP 201 với ResourceItem vừa tạo
     */
    public function store(
        ResourceCreateRequest $request,
        CreateResourceAction $action,
        ResourceRepositoryInterface $repository,
    ): JsonResponse {
        $resource = $action->handle($request->all(), $request->user()->id, $repository);

        return BaseResponse::created(
            ResourceItem::make($resource),
            'Tạo tài nguyên thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả chi tiết một resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource đã được route model binding nạp sẵn
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với ResourceItem đầy đủ
     */
    public function show(Resource $resource): JsonResponse
    {
        $resource->loadMissing([
            'author',
            'seoMetadata',
            'categories',
            'tags',
            'technologies',
            'mediaAssetUsages.mediaAsset.media',
        ]);

        return BaseResponse::success(
            ResourceItem::make($resource),
            'Chi tiết tài nguyên.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần sửa
     * - $request: payload đã được ResourceValidator kiểm tra
     * - $action: action cập nhật resource kèm slug và taxonomy
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với ResourceItem sau cập nhật
     */
    public function update(
        Resource $resource,
        ResourceUpdateRequest $request,
        UpdateResourceAction $action,
        ResourceRepositoryInterface $repository,
    ): JsonResponse {
        $updated = $action->handle($resource, $request->all(), $request->user()->id, $repository);

        return BaseResponse::success(
            ResourceItem::make($updated),
            'Cập nhật tài nguyên thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xoá mềm resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần xoá
     *
     * OUTPUT:
     * - Response HTTP 204 không có body
     *
     * SIDE EFFECT:
     * - SET deleted_at; bản ghi vẫn còn để giữ lịch sử download
     */
    public function destroy(Resource $resource, MediaAssetUsageService $mediaAssetUsageService): Response
    {
        DB::transaction(function () use ($resource, $mediaAssetUsageService): void {
            $actor = request()->user();
            if (! $actor instanceof User) {
                abort(Response::HTTP_UNAUTHORIZED);
            }

            $mediaAssetUsageService->detachAll($actor, $resource);
            $resource->delete();
        });

        return BaseResponse::noContent();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Publish resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần publish
     * - $action: action kiểm tra trạng thái nguồn rồi chuyển trạng thái
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với ResourceItem sau khi publish
     */
    public function publish(Resource $resource, PublishResourceAction $action): JsonResponse
    {
        $published = $action->handle($resource, request()->user()->id);
        $published->loadMissing([
            'author',
            'seoMetadata',
            'categories',
            'tags',
            'technologies',
            'mediaAssetUsages.mediaAsset.media',
        ]);

        return BaseResponse::success(
            ResourceItem::make($published),
            'Xuất bản tài nguyên thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Archive resource
     * =====================================================================
     *
     * INPUT:
     * - $resource: resource cần archive
     * - $action: action chuyển resource sang archived
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với ResourceItem sau khi archive
     */
    public function archive(Resource $resource, ArchiveResourceAction $action): JsonResponse
    {
        $archived = $action->handle($resource, request()->user()->id);
        $archived->loadMissing([
            'author',
            'seoMetadata',
            'categories',
            'tags',
            'technologies',
            'mediaAssetUsages.mediaAsset.media',
        ]);

        return BaseResponse::success(
            ResourceItem::make($archived),
            'Lưu trữ tài nguyên thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn criteria lọc theo query của endpoint index
     * =====================================================================
     *
     * INPUT:
     * - $repository: repository cần cấu hình trước khi truy vấn
     * - $request: query chứa status, type và category_id
     *
     * SIDE EFFECT:
     * - Push Criteria vào repository; chỉ đọc query, không ghi database
     */
    private function applyFilters(ResourceRepositoryInterface $repository, Request $request): void
    {
        if ($request->filled('status')) {
            $repository->pushCriteria(new ResourceStatusCriteria(
                (string) $request->string('status'),
            ));
        }

        if ($request->filled('type')) {
            $repository->pushCriteria(new ResourceTypeCriteria(
                (string) $request->string('type'),
            ));
        }

        if ($request->filled('category_id')) {
            $repository->pushCriteria(new ResourceCategoryCriteria(
                (int) $request->integer('category_id'),
            ));
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Eager load quan hệ rồi phân trang danh sách
     * =====================================================================
     *
     * INPUT:
     * - $repository: repository đã cấu hình criteria
     * - $perPage: số bản ghi mỗi trang
     *
     * OUTPUT:
     * - LengthAwarePaginator: các ResourceSummary đã nạp sẵn quan hệ
     */
    private function scopeListing(ResourceRepositoryInterface $repository, int $perPage)
    {
        $paginator = $repository->paginate($perPage);

        return $paginator->setCollection(
            $paginator->getCollection()->loadMissing(['author', 'seoMetadata', 'categories', 'tags', 'technologies']),
        );
    }
}
