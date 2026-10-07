<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccessIndexRequest;
use App\Http\Requests\Admin\AccessVersionRequest;
use App\Http\Requests\Admin\RoleCreateRequest;
use App\Http\Requests\Admin\RoleUpdateRequest;
use App\Http\Resources\RoleResource;
use App\Http\Responses\BaseResponse;
use App\Services\Access\RoleManagementService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối CRUD role admin qua FormRequest và service
 * =====================================================================
 * Đọc cần users.view/manage, mutation cần users.manage; response giữ BaseResponse.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), index(), show(), store(), update(), destroy().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): dữ liệu đã validate/admin -> DTO hoặc mutation quyền.
 * SIDE EFFECT: Theo boundary từng method; không gọi dịch vụ bên ngoài.
 * EXCEPTION/TRANSACTION: Quyền/validation/conflict trả 403/422/409; mutation dùng transaction.
 * =====================================================================
 */
final class RoleController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận service quản lý role
     * INPUT: RoleManagementService từ container.
     * OUTPUT: Controller sẵn sàng.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly RoleManagementService $access) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách role phân trang
     * INPUT: Query đã validate và actor có quyền xem.
     * OUTPUT: BaseResponse DataTable cùng meta can_manage.
     * SIDE EFFECT: Read-only eager loaded query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function index(AccessIndexRequest $request): JsonResponse
    {
        return BaseResponse::dataTable(RoleResource::collection($this->access->roles($request->validated())),
            meta: ['can_manage' => $request->user()->can('users.manage')]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả chi tiết role/version mới nhất
     * INPUT: Role binding và actor có quyền xem.
     * OUTPUT: BaseResponse RoleResource.
     * SIDE EFFECT: Query eager load/count.
     * EXCEPTION/TRANSACTION: 404 nếu role không thuộc guard admin.
     * =====================================================================
     */
    public function show(AccessIndexRequest $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'admin', 404);

        return BaseResponse::success(new RoleResource($this->access->roleDetails($role)));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo role tùy chỉnh
     * INPUT: RoleCreateRequest đã kiểm users.manage.
     * OUTPUT: BaseResponse 201 RoleResource.
     * SIDE EFFECT: Service ghi role/pivot/audit.
     * EXCEPTION/TRANSACTION: Transaction/lock/rollback do service quản lý.
     * =====================================================================
     */
    public function store(RoleCreateRequest $request): JsonResponse
    {
        return BaseResponse::created(new RoleResource($this->access->save($request->user(), $request->validated())), 'Role created.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật role và permission
     * INPUT: RoleUpdateRequest và role binding.
     * OUTPUT: BaseResponse RoleResource mới.
     * SIDE EFFECT: Service ghi role/pivot/audit.
     * EXCEPTION/TRANSACTION: Service kiểm version, root, system name và quyền trong transaction.
     * =====================================================================
     */
    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        return BaseResponse::success(new RoleResource($this->access->save($request->user(), $request->validated(), $role)), 'Role updated.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa custom role chưa dùng
     * INPUT: AccessVersionRequest và role.
     * OUTPUT: HTTP 204.
     * SIDE EFFECT: Service xóa role/pivot, ghi audit.
     * EXCEPTION/TRANSACTION: Service kiểm version/quyền/role đang dùng; lỗi rollback.
     * =====================================================================
     */
    public function destroy(AccessVersionRequest $request, Role $role): Response
    {
        $this->access->remove($request->user(), $role, $request->validated('expected_version'));

        return BaseResponse::noContent();
    }
}
