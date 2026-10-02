<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate phân trang danh sách tác vụ viết bài AI của admin.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query page/per_page sau middleware auth và posts.manage.
 * - OUTPUT: query hợp lệ hoặc HTTP 422; không ghi DB hoặc gọi provider.
 * =====================================================================
 */
class AiSessionIndexRequest extends FormRequest
{
    /** Input: request đã qua middleware. Output: cho phép validation; quyền do route kiểm tra. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: query phân trang. Output: rules giới hạn kích thước; không có side effect. */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
