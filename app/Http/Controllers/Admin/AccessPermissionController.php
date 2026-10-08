<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccessIndexRequest;
use App\Http\Resources\AccessPermissionResource;
use App\Http\Responses\BaseResponse;
use App\Services\Access\RoleManagementService;
use Illuminate\Http\JsonResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Trả catalog quyền thật và danh sách permission
 * =====================================================================
 * Quyền chỉ đọc từ cấu hình/server; FormRequest kiểm users.view/manage hoặc
 * một action vòng đời role.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), index(), catalog().
 * INPUT/OUTPUT CỦA CLASS: Request đã validate và actor có quyền access -> BaseResponse catalog/bảng quyền.
 * SIDE EFFECT: Query read-only qua service; không ghi database.
 * EXCEPTION/TRANSACTION: FormRequest chặn 403/422; controller không mở transaction.
 * =====================================================================
 */
final class AccessPermissionController extends Controller
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
     * CHỨC NĂNG: Trả bảng quyền phân trang
     * INPUT: Query search/group/page/per_page đã validate.
     * OUTPUT: BaseResponse DataTable.
     * SIDE EFFECT: Read-only eager loaded query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function index(AccessIndexRequest $request): JsonResponse
    {
        return BaseResponse::dataTable(AccessPermissionResource::collection($this->access->permissions($request->validated())));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả nhóm quyền và role options cho editor
     * INPUT: Actor có quyền access hoặc action vòng đời role.
     * OUTPUT: BaseResponse catalog.
     * SIDE EFFECT: Read-only query; không seed.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function catalog(AccessIndexRequest $request): JsonResponse
    {
        return BaseResponse::success($this->access->catalog($request->user()));
    }
}
