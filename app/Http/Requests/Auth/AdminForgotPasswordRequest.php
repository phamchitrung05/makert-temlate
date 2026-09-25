<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate email yêu cầu reset password admin
 * =====================================================================
 * =====================================================================
 */
class AdminForgotPasswordRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép gửi yêu cầu reset password
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; rate limit nằm ở route middleware
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate email admin
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule email bắt buộc và hợp lệ
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
