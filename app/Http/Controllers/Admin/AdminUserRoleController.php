<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccessIndexRequest;
use App\Http\Requests\Admin\AdminUserRolesRequest;
use App\Http\Resources\AdminRoleUserResource;
use App\Http\Responses\BaseResponse;
use App\Models\User;
use App\Services\Access\RoleManagementService;
use Illuminate\Http\JsonResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xem tài khoản admin và thay danh sách role
 * =====================================================================
 * Phạm vi chỉ tài khoản quản trị, không làm danh sách Customer đang tạm hoãn hoặc CRUD credential.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), index(), show(), update().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): dữ liệu đã validate/admin -> DTO hoặc mutation quyền.
 * SIDE EFFECT: Theo boundary từng method; không gọi dịch vụ bên ngoài.
 * EXCEPTION/TRANSACTION: Quyền/validation/conflict trả 403/422/409; mutation dùng transaction.
 * =====================================================================
 */
final class AdminUserRoleController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận service access
     * INPUT: Service từ container.
     * OUTPUT: Controller sẵn sàng.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly RoleManagementService $access) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả bảng tài khoản admin để gán role
     * INPUT: Query đã validate, actor có users.view/manage.
     * OUTPUT: BaseResponse DataTable User DTO.
     * SIDE EFFECT: Query eager loaded, không trả secret.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function index(AccessIndexRequest $request): JsonResponse
    {
        return BaseResponse::dataTable(AdminRoleUserResource::collection($this->access->users($request->validated())));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tải lại role/version của một tài khoản admin
     * INPUT: User binding và actor có users.view hoặc users.manage.
     * OUTPUT: BaseResponse DTO mới nhất, không credential.
     * SIDE EFFECT: Query eager load roles.permissions và direct permissions.
     * EXCEPTION/TRANSACTION: Không ghi dữ liệu hoặc mở transaction.
     * =====================================================================
     */
    public function show(AccessIndexRequest $request, User $user): JsonResponse
    {
        return BaseResponse::success(new AdminRoleUserResource($user->load(['roles.permissions', 'permissions'])));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu role của tài khoản admin
     * INPUT: User binding và request role_ids/version có users.manage.
     * OUTPUT: BaseResponse DTO mới.
     * SIDE EFFECT: Service sync role và audit; không sửa direct permission.
     * EXCEPTION/TRANSACTION: Transaction/lock và version do service; tự đổi role/quyền cao hơn bị chặn.
     * =====================================================================
     */
    public function update(AdminUserRolesRequest $request, User $user): JsonResponse
    {
        return BaseResponse::success(new AdminRoleUserResource($this->access->assign($request->user(), $user, $request->validated())), 'Admin roles updated.');
    }
}
