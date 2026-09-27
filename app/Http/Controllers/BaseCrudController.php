<?php

namespace App\Http\Controllers;

use App\Http\Responses\BaseResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Prettus\Repository\Contracts\RepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Template CRUD cho các controller có cùng contract HTTP
 * =====================================================================
 *
 * Base này điều phối index/store/show/update/destroy bằng repository,
 * FormRequest, JsonResource và BaseResponse. Controller domain chỉ cung cấp
 * các class/hook cấu hình. Domain có mutation nhiều bước, state transition
 * hoặc side effect phải override hoặc dùng Action thay vì ép vào CRUD chung.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): trả danh sách phân trang qua repository
 * - store(): validate FormRequest rồi tạo bản ghi
 * - show(): resolve route model và trả chi tiết
 * - update(): resolve model, validate và cập nhật bản ghi
 * - destroy(): resolve model rồi gọi hook xoá
 * - repository(): resolve repository domain từ container
 * - resolveRequest(): tạo và chạy FormRequest động qua container
 * - resolveRouteModel(): nạp model từ route bằng repository
 * - deleteModel(): hook xoá model, cho phép domain override
 * - repositoryInterface(): khai báo repository contract của domain
 * - createRequestClass(): khai báo FormRequest tạo mới
 * - updateRequestClass(): khai báo FormRequest cập nhật
 * - resourceClass(): khai báo JsonResource định hình response
 * - routeParameterName(): khai báo tên route parameter của model
 * - responseMessage(): trả message theo ngữ cảnh CRUD
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request đã qua middleware cùng cấu hình từ controller domain
 * - OUTPUT: JsonResponse/Response theo envelope BaseResponse
 *
 * EXCEPTION/TRANSACTION:
 * - Không tự mở transaction vì CRUD base chỉ thực hiện một mutation model
 * - ValidationException, ModelNotFoundException và ValidatorException được
 *   pipeline exception API chuyển thành BaseResponse phù hợp
 * =====================================================================
 */
abstract class BaseCrudController extends BaseController
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách model có phân trang
     * =====================================================================
     *
     * INPUT:
     * - $request: query hỗ trợ per_page và các tham số RequestCriteria
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với collection và meta.pagination
     *
     * SIDE EFFECT:
     * - Chỉ truy vấn database; không ghi dữ liệu
     * =====================================================================
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->repository()->paginate($this->resolvePerPage($request));
        $resourceClass = $this->resourceClass();

        return BaseResponse::paginated(
            $resourceClass::collection($paginator),
            $this->responseMessage('list'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate request và tạo một model bằng repository
     * =====================================================================
     *
     * INPUT:
     * - Request hiện tại được resolve thành class từ createRequestClass()
     *
     * OUTPUT:
     * - JsonResponse HTTP 201 chứa JsonResource của model vừa tạo
     *
     * SIDE EFFECT:
     * - INSERT một model; model event có thể phát side effect đã khai báo
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; chỉ dùng cho mutation một model
     * - ValidationException hoặc ValidatorException khi dữ liệu không hợp lệ
     * =====================================================================
     */
    public function store(): JsonResponse
    {
        $model = $this->repository()->create(
            $this->resolveRequest($this->createRequestClass())->validated(),
        );
        $resourceClass = $this->resourceClass();

        return BaseResponse::created(
            $resourceClass::make($model),
            $this->responseMessage('created'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả chi tiết model lấy từ route parameter
     * =====================================================================
     *
     * INPUT:
     * - $model: id thô hoặc Model đã được route binding nạp sẵn
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 chứa JsonResource chi tiết
     *
     * SIDE EFFECT:
     * - Có thể SELECT database khi route chưa bind thành Model
     * =====================================================================
     */
    public function show(mixed $model): JsonResponse
    {
        $resolved = $this->resolveRouteModel($model);
        $resourceClass = $this->resourceClass();

        return BaseResponse::success(
            $resourceClass::make($resolved),
            $this->responseMessage('detail'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate request và cập nhật model lấy từ route
     * =====================================================================
     *
     * INPUT:
     * - $model: id thô hoặc Model đã được route binding nạp sẵn
     * - Request hiện tại được resolve thành class từ updateRequestClass()
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 chứa model sau cập nhật
     *
     * SIDE EFFECT:
     * - UPDATE một model; cập nhật route parameter thành Model cho FormRequest
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; chỉ dùng cho mutation một model
     * - ValidationException, ModelNotFoundException hoặc ValidatorException
     * =====================================================================
     */
    public function update(mixed $model): JsonResponse
    {
        $resolved = $this->resolveRouteModel($model);
        $updated = $this->repository()->update(
            $this->resolveRequest($this->updateRequestClass())->validated(),
            $resolved->getKey(),
        );
        $resourceClass = $this->resourceClass();

        return BaseResponse::success(
            $resourceClass::make($updated),
            $this->responseMessage('updated'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xoá model lấy từ route bằng hook của CRUD base
     * =====================================================================
     *
     * INPUT:
     * - $model: id thô hoặc Model đã được route binding nạp sẵn
     *
     * OUTPUT:
     * - Response HTTP 204 không có body
     *
     * SIDE EFFECT:
     * - Soft delete hoặc hard delete tùy model/repository domain
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; ModelNotFoundException nếu id không tồn tại
     * =====================================================================
     */
    public function destroy(mixed $model): Response
    {
        $this->deleteModel($this->resolveRouteModel($model));

        return BaseResponse::noContent();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve repository contract của controller domain
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc class-string từ repositoryInterface()
     *
     * OUTPUT:
     * - RepositoryInterface: repository đã bind trong container
     * =====================================================================
     */
    protected function repository(): RepositoryInterface
    {
        return app($this->repositoryInterface());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo và chạy FormRequest động bằng Laravel container
     * =====================================================================
     *
     * INPUT:
     * - $class: class-string của FormRequest create hoặc update
     *
     * OUTPUT:
     * - FormRequest: request đã authorize và validate thành công
     *
     * SIDE EFFECT:
     * - Ném ValidationException 422 khi payload không hợp lệ
     * =====================================================================
     */
    protected function resolveRequest(string $class): FormRequest
    {
        /** @var FormRequest $request */
        $request = app($class);
        $request->validateResolved();

        return $request;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve id route thành Model bằng repository domain
     * =====================================================================
     *
     * INPUT:
     * - $value: id thô từ route hoặc Model đã được binding
     *
     * OUTPUT:
     * - Model: model tồn tại và được đặt lại vào route parameter
     *
     * SIDE EFFECT:
     * - SELECT database khi nhận id; cập nhật route parameter để FormRequest
     *   update đọc được model và loại trừ đúng id trong rule unique
     *
     * EXCEPTION/TRANSACTION:
     * - ModelNotFoundException từ repository khi id không tồn tại
     * =====================================================================
     */
    protected function resolveRouteModel(mixed $value): Model
    {
        $model = $value instanceof Model ? $value : $this->repository()->find($value);

        if (! $model instanceof Model) {
            throw new \LogicException('CRUD repository phải trả về một Eloquent model.');
        }

        $route = request()->route();

        if ($route instanceof Route) {
            $route->setParameter($this->routeParameterName(), $model);
        }

        return $model;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xoá model bằng repository domain
     * =====================================================================
     *
     * INPUT:
     * - $model: model đã resolve từ route
     *
     * OUTPUT:
     * - void
     *
     * SIDE EFFECT:
     * - Category/Tag dùng SoftDeletes; Technology bị hard delete do model
     *   không dùng SoftDeletes. Controller con có thể override khi cần rule.
     * =====================================================================
     */
    protected function deleteModel(Model $model): void
    {
        $this->repository()->delete($model->getKey());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo repository contract của domain
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string<RepositoryInterface>: interface đã bind trong container
     * =====================================================================
     */
    abstract protected function repositoryInterface(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo FormRequest dùng cho thao tác tạo
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string<FormRequest>: request create của domain
     * =====================================================================
     */
    abstract protected function createRequestClass(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo FormRequest dùng cho thao tác cập nhật
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string<FormRequest>: request update của domain
     * =====================================================================
     */
    abstract protected function updateRequestClass(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo JsonResource định hình response CRUD
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - class-string<JsonResource>: resource của domain
     * =====================================================================
     */
    abstract protected function resourceClass(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo tên route parameter chứa id/model
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - string: tên parameter, ví dụ category, tag hoặc technology
     * =====================================================================
     */
    abstract protected function routeParameterName(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả message client theo ngữ cảnh CRUD
     * =====================================================================
     *
     * INPUT:
     * - $context: list, detail, created hoặc updated
     *
     * OUTPUT:
     * - string|null: message client-facing của domain
     * =====================================================================
     */
    abstract protected function responseMessage(string $context): ?string;
}
