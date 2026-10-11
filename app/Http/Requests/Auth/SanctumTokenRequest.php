<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate thông tin thiết bị khi issue Sanctum token
 * =====================================================================
 *
 * Device name giúp người dùng nhận biết và revoke token theo thiết bị ở
 * giai đoạn quản lý session. Không nhận abilities từ client; abilities do
 * server quyết định theo loại account.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép account đã authenticated yêu cầu token
 * - rules(): giới hạn device_name
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
 */
class SanctumTokenRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép issue token cho account hiện tại
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; auth middleware quyết định account đã đăng nhập
     * =====================================================================
     * Input: tham số theo chữ ký `authorize()` và state đã hydrate của class.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate device name
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, string>>: device_name optional và giới hạn độ dài
     * =====================================================================
     * Input: tham số theo chữ ký `rules()` và state đã hydrate của class.
     */
    public function rules(): array
    {
        return [
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
