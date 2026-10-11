<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate credential đăng nhập admin
 * =====================================================================
 *
 * Request này chỉ phục vụ endpoint cấp Sanctum token cho admin. Customer
 * OAuth không đi qua request này và không được gửi password vào endpoint admin.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tới controller; permission kiểm tra sau khi login
 * - rules(): validate email, password và device_name
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
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
     * Input: tham số theo chữ ký `authorize()` và state đã hydrate của class.
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
     * - array<string, array<int, string>>: rule cho email, password và device_name
     * =====================================================================
     * Input: tham số theo chữ ký `rules()` và state đã hydrate của class.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
