<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm quyền quản lý và version mutation access
 * =====================================================================
 * Request xóa role và class cha request gán role; service kiểm lại quyền dưới lock.
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules(), versionRules().
 * INPUT/OUTPUT CỦA CLASS: User và expected_version -> version đã validate cho mutation.
 * SIDE EFFECT: Đọc quyền User; không ghi database.
 * EXCEPTION/TRANSACTION: 403 khi thiếu quyền, 422 khi version sai; không mở transaction.
 * =====================================================================
 */
class AccessVersionRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm quyền quản lý tài khoản và role
     * INPUT: Actor Sanctum.
     * OUTPUT: Boolean User có users.manage.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('users.manage');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Yêu cầu version đã đọc trước mutation
     * INPUT: expected_version từ client.
     * OUTPUT: Rules SHA-256.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: 422 nếu thiếu/sai version.
     * =====================================================================
     */
    public function rules(): array
    {
        return $this->versionRules();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chia sẻ rule version giữa các request cập nhật access
     * INPUT: Không có.
     * OUTPUT: Rules SHA-256 cho expected_version.
     * SIDE EFFECT: Không query hoặc ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function versionRules(): array
    {
        return ['expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
    }
}
