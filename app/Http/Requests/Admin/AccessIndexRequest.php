<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate query xem role, permission và tài khoản admin
 * =====================================================================
 * Request là boundary quyền xem users.view hoặc users.manage; customer không được truy cập.
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS: Query HTTP và User đã xác thực -> filters đã validate.
 * SIDE EFFECT: Đọc quyền User và query validation role; không ghi database.
 * EXCEPTION/TRANSACTION: 403 khi thiếu quyền, 422 khi filter sai; không mở transaction.
 * =====================================================================
 */
final class AccessIndexRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm quyền đọc back-office
     * INPUT: User hiện tại từ Sanctum.
     * OUTPUT: Boolean quyền users.view hoặc users.manage.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && ($this->user()->can('users.view') || $this->user()->can('users.manage'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn filter và phân trang
     * INPUT: Query HTTP.
     * OUTPUT: Rules search/status/group/role_id/page/per_page.
     * SIDE EFFECT: Validation exists query guard admin.
     * EXCEPTION/TRANSACTION: 422 nếu query sai.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'group' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('permissions.catalog', [])))],
            'role_id' => ['sometimes', 'nullable', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'admin')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
