<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate thay danh sách role tài khoản admin
 * =====================================================================
 * Đầu vào chỉ role IDs/version; không nhận permission trực tiếp, credential hoặc dữ liệu customer.
 * CÁC HÀM/METHOD TRONG FILE: rules(); authorize() kế thừa.
 * INPUT/OUTPUT CỦA CLASS: User/role_ids/expected_version -> payload role admin hợp lệ.
 * SIDE EFFECT: Query validation role; không ghi database.
 * EXCEPTION/TRANSACTION: 403 khi thiếu quyền, 422 khi field sai; không mở transaction.
 * =====================================================================
 */
final class AdminUserRolesRequest extends AccessVersionRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Validate role IDs thuộc admin guard
     * INPUT: role_ids và expected_version.
     * OUTPUT: Rules cho mảng role có thể rỗng, distinct và version.
     * SIDE EFFECT: Query exists; không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: 422 nếu role IDs sai guard/không tồn tại.
     * =====================================================================
     */
    public function rules(): array
    {
        return [...parent::rules(),
            'role_ids' => ['present', 'array', 'max:100'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'admin')],
        ];
    }
}
