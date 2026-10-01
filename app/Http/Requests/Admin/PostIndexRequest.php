<?php

namespace App\Http\Requests\Admin;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate bộ lọc danh sách Post trong khu vực quản trị.
 * =====================================================================
 *
 * Request giữ contract phân trang/lọc ở HTTP boundary để controller chỉ
 * dựng query từ dữ liệu đã được chuẩn hóa. Không chứa business rule của Post.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request sau middleware permission.
 * - rules(): khai báo bộ lọc search, taxonomy, status và pagination.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query string từ màn hình danh sách Post.
 * - OUTPUT: dữ liệu đã validate hoặc lỗi HTTP 422.
 * =====================================================================
 */
final class PostIndexRequest extends FormRequest
{
    /**
     * Cho phép request đi qua controller đã được bảo vệ bởi middleware.
     *
     * Input: Không có.
     * Output: true; quyền được kiểm tra ở route/policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Khai báo các field filter mà API Post hỗ trợ.
     *
     * Input: Không có.
     * Output: mảng validation rules cho query list.
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'tag_id' => ['sometimes', 'nullable', 'integer', 'exists:tags,id'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PostStatus::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
