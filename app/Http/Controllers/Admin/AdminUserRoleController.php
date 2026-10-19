<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Access\AccessIndexRequest;
use App\Http\Requests\Admin\Access\AccessVersionRequest;
use App\Http\Requests\Admin\Access\AdminUserRolesRequest;
use App\Http\Requests\Admin\Access\AdminUserSaveRequest;
use App\Http\Resources\Access\AdminRoleUserResource;
use App\Http\Responses\BaseResponse;
use App\Models\User;
use App\Services\Access\RoleManagementService;
use Illuminate\Http\JsonResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý hồ sơ/credential và danh sách role tài khoản admin.
 * =====================================================================
 * Phạm vi chỉ tài khoản quản trị; không trả credential, giữ lịch sử khi xóa mềm.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), index(), show(), update(), store(), updateAccount(), destroy().
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

    /** Input: hồ sơ/password đã validate và users.manage. Output: DTO 201 không secret; service ghi tài khoản và audit trong transaction. */
    public function store(AdminUserSaveRequest $request): JsonResponse
    {
        return BaseResponse::success(new AdminRoleUserResource($this->access->saveUser($request->user(), $request->validated())), 'Admin created.', 201);
    }

    /** Input: User binding, hồ sơ/password optional và account_version. Output: DTO mới; service khóa, kiểm phạm vi và thu hồi token khi đổi credential/status. */
    public function updateAccount(AdminUserSaveRequest $request, User $user): JsonResponse
    {
        return BaseResponse::success(new AdminRoleUserResource($this->access->saveUser($request->user(), $request->validated(), $user)), 'Admin updated.');
    }

    /** Input: User binding và account_version. Output: success hoặc lỗi scope/version; service xóa mềm và thu hồi token trong transaction. */
    public function destroy(AccessVersionRequest $request, User $user): JsonResponse
    {
        $this->access->removeUser($request->user(), $user, $request->validated('expected_version'));

        return BaseResponse::success(null, 'Admin deleted.');
    }
}
