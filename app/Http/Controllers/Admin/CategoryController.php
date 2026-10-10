<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Taxonomy\SaveCategoryAction;
use App\Http\Requests\Admin\CategoryCreateRequest;
use App\Http\Requests\Admin\CategoryUpdateRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\BaseResponse;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Services\MediaAssetUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD danh mục cho admin
 * =====================================================================
 *
 * Controller kế thừa contract taxonomy; ghi Category và thumbnail đi qua
 * action có transaction. List trả slug/số bài và detail trả ảnh để form sửa.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(), show(): đọc Category với dữ liệu dùng trong giao diện.
 * - store(), update(): validate và lưu qua SaveCategoryAction.
 * - deleteModel(): ngăn xóa Category còn con, gỡ usage rồi xóa mềm.
 * - resourceClass(): trả DTO riêng cho Category.
 * - messageKey(): trả về khoá `category` để tra message
 * - repositoryInterface(): repository của Category
 * - createRequestClass(): trả về CategoryCreateRequest
 * - updateRequestClass(): trả về CategoryUpdateRequest
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua auth:sanctum, abilities:admin và permission
 * - OUTPUT: JsonResponse hoặc Response theo envelope BaseResponse
 * =====================================================================
 */
class CategoryController extends TaxonomyController
{
    /** Input: query phân trang. Output: danh sách Category có slug và posts_count. */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->repository()
            ->scopeQuery(fn ($query) => $query->with('slugs')->withCount('posts')->orderBy('id'))
            ->paginate($this->resolvePerPage($request));

        return BaseResponse::paginated(CategoryResource::collection($paginator), $this->responseMessage('list'));
    }

    /** Input: route id/model. Output: Category có slug và ảnh đã lưu để sửa form. */
    public function show(mixed $model): JsonResponse
    {
        $category = $this->resolveRouteModel($model)
            ->load(['slugs', 'mediaAssetUsages.mediaAsset.media'])->loadCount('posts');

        return BaseResponse::success(CategoryResource::make($category), $this->responseMessage('detail'));
    }

    /** Input: request tạo. Output: HTTP 201 sau khi lưu Category và media thành công. */
    public function store(): JsonResponse
    {
        $category = app(SaveCategoryAction::class)->handle(
            $this->resolveRequest($this->createRequestClass())->validated(),
            request()->user('admin'),
        );

        return BaseResponse::created(CategoryResource::make($category), $this->responseMessage('created'));
    }

    /** Input: route Category và request sửa. Output: HTTP 200 với Category mới nhất. */
    public function update(mixed $model): JsonResponse
    {
        $category = $this->resolveRouteModel($model);
        $updated = app(SaveCategoryAction::class)->handle(
            $this->resolveRequest($this->updateRequestClass())->validated(),
            request()->user('admin'),
            $category,
        );

        return BaseResponse::success(CategoryResource::make($updated), $this->responseMessage('updated'));
    }

    /**
     * Input: Category đã resolve. Output: xóa mềm và gỡ media usage, giữ asset thư viện.
     * Exception: lỗi 422 nếu còn Category con để cây không trỏ tới cha đã xóa.
     */
    protected function deleteModel(Model $model): void
    {
        DB::transaction(function () use ($model): void {
            if ($model->children()->exists()) {
                throw ValidationException::withMessages([
                    'category' => 'Hãy chuyển hoặc xóa các danh mục con trước khi xóa danh mục này.',
                ]);
            }

            app(MediaAssetUsageService::class)->detachAll(request()->user('admin'), $model);
            $this->repository()->delete($model->getKey());
        });
    }

    /** Input: không có. Output: resource riêng bổ sung field của Category. */
    protected function resourceClass(): string
    {
        return CategoryResource::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy khoá taxonomy dùng để tra message trong config
     * =====================================================================
     *
     * OUTPUT:
     * - string: category
     */
    protected function messageKey(): string
    {
        return 'category';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định interface repository của danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: CategoryRepositoryInterface
     */
    protected function repositoryInterface(): string
    {
        return CategoryRepositoryInterface::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho tạo danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: CategoryCreateRequest
     */
    protected function createRequestClass(): string
    {
        return CategoryCreateRequest::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ định FormRequest dùng cho cập nhật danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - class-string: CategoryUpdateRequest
     */
    protected function updateRequestClass(): string
    {
        return CategoryUpdateRequest::class;
    }
}
