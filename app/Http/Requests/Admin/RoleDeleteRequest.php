<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Access\RoleManagementService;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate version và quyền xóa role admin
 * =====================================================================
 * Request dùng riêng cho endpoint DELETE role để phân biệt roles.delete với
 * request gán role tài khoản vốn vẫn yêu cầu users.manage.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): kiểm roles.delete hoặc quyền quản trị access cũ.
 * - rules(): kế thừa expected_version.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : actor và expected_version.
 * - OUTPUT: payload version hợp lệ cho controller.
 * - SIDE EFFECT: Không ghi database.
 * =====================================================================
 */
final class RoleDeleteRequest extends AccessVersionRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm quyền xóa role admin
     * INPUT: User admin đã xác thực.
     * OUTPUT: true khi actor có roles.delete hoặc users.manage.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && RoleManagementService::canDeleteRole($this->user());
    }
}
