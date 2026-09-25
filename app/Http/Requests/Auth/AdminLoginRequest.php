<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate credential đăng nhập admin
 * =====================================================================
 *
 * Request này chỉ phục vụ admin guard. Customer OAuth không đi qua request
 * này và không được gửi password vào endpoint admin.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tới controller; permission kiểm tra sau khi login
 * - rules(): validate email, password và remember
 * =====================================================================
 */
class AdminLoginRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép gửi request login admin
     * =====================================================================
     *
     * OUTPUT:
     * - bool: luôn true vì endpoint tự xử lý credential và rate limit
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule validate login admin
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule cho email, password và remember
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
