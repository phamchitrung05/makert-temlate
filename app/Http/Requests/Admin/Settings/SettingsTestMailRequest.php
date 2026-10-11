<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate địa chỉ nhận email kiểm tra Settings.
 * =====================================================================
 * Request chỉ nhận recipient hợp lệ và kiểm tra quyền trước khi service gửi
 * email; không nhận nội dung mail, password hoặc thông tin transport.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): kiểm tra quyền settings.manage.
 * - rules(): khai báo contract recipient.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP payload recipient và user admin hiện tại.
 * - OUTPUT: dữ liệu đã validate hoặc lỗi 403/422.
 * =====================================================================
 */
final class SettingsTestMailRequest extends FormRequest
{
    /** Input: admin. Output: có quyền Settings hay không. */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** Output: chỉ cho phép địa chỉ email; không nhận nội dung hoặc credential.
     * Input: tham số theo chữ ký `rules()` và state đã hydrate của class.
     */
    public function rules(): array
    {
        return ['recipient' => ['required', 'email:rfc', 'max:254']];
    }
}
