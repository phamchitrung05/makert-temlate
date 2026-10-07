<?php

namespace App\Http\Requests\Admin;

use App\Services\Access\RoleManagementService;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate tên role và permission IDs theo catalog admin
 * =====================================================================
 * Base chung cho RoleCreateRequest và RoleUpdateRequest; users.manage tạo/sửa. Guard/name/system invariants được service kiểm dưới lock.
 * CÁC HÀM/METHOD TRONG FILE: prepareForValidation(), rules(); authorize() kế thừa.
 * INPUT/OUTPUT CỦA CLASS: User/name/permission_ids -> payload tên/catalog đã validate.
 * SIDE EFFECT: Trim name và query validation role/permission; không ghi database.
 * EXCEPTION/TRANSACTION: 403 khi thiếu quyền, 422 khi field sai; không mở transaction.
 * =====================================================================
 */
abstract class RoleSaveRequest extends AccessVersionRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa tên role
     * INPUT: name HTTP nếu là string.
     * OUTPUT: Tên đã trim.
     * SIDE EFFECT: Merge input request; không query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate payload role create/update
     * INPUT: name và permission_ids.
     * OUTPUT: Rules tên slug, unique guard admin và quyền catalog.
     * SIDE EFFECT: Validation query role/permission; không ghi DB.
     * EXCEPTION/TRANSACTION: 422 khi field sai; service kiểm race/conflict trong transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_-]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'admin')->ignore($this->route('role')?->id)],
            'permission_ids' => ['present', 'array', 'max:100'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')
                ->where(fn ($query) => $query->where('guard_name', 'admin')->whereIn('name', RoleManagementService::permissionNames()))],
        ];
    }
}
