<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate payload đặt lại password admin
 * =====================================================================
 * =====================================================================
 */
class AdminResetPasswordRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép gửi payload reset password
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; token được Password Broker kiểm tra ở controller
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate token, email và password mới
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, string>>: dữ liệu đủ cho admin password broker
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'min:12'],
        ];
    }
}
