<?php

namespace App\Http\Requests\Admin;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request cập nhật role với version bắt buộc
 * =====================================================================
 * Controller update dùng request riêng; service kiểm version và scope quyền
 * trong transaction. Rules tên/catalog và authorization kế thừa RoleSaveRequest.
 * CÁC HÀM/METHOD TRONG FILE: rules(); authorize(), prepareForValidation() kế thừa.
 * INPUT/OUTPUT CỦA CLASS: name/permission_ids/expected_version -> payload hợp lệ.
 * SIDE EFFECT: Query validation; không ghi database.
 * EXCEPTION/TRANSACTION: 403 khi thiếu quyền, 422 khi field sai; không transaction.
 * =====================================================================
 */
final class RoleUpdateRequest extends RoleSaveRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Yêu cầu version khi cập nhật role
     * INPUT: Payload HTTP update role đã có route binding.
     * OUTPUT: Rules tên/catalog và version SHA-256.
     * SIDE EFFECT: Query validation role/permission; không ghi database.
     * EXCEPTION/TRANSACTION: 422 nếu field sai; không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [...parent::rules(), ...$this->versionRules()];
    }
}
