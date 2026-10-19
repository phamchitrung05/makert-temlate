<?php

namespace App\Http\Requests\Admin\Access;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate tạo/sửa credential và hồ sơ admin, tách việc gán role.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: rules(); authorize() kế thừa users.manage.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): hồ sơ/password xác nhận -> payload hợp lệ hoặc 403/422.
 * Không ghi DB; update yêu cầu account_version để tránh ghi đè thay đổi đồng thời.
 * =====================================================================
 */
final class AdminUserSaveRequest extends AccessVersionRequest
{
    /** Input: payload POST/PATCH và User binding. Output: rules hồ sơ, password mạnh/xác nhận và unique; không ghi DB. */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $presence = $creating ? 'required' : 'sometimes';
        $user = $this->route('user');

        return [
            ...($creating ? [] : parent::rules()),
            'name' => [$presence, 'string', 'max:255'],
            'email' => [$presence, 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'username' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users')->ignore($user)],
            'status' => [$presence, Rule::in(['active', 'inactive', 'suspended'])],
            'password' => [$presence, 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'role_ids' => ['prohibited'],
            'permission_ids' => ['prohibited'],
        ];
    }
}
