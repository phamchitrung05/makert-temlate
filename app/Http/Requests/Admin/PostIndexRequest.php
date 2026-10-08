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
 * - rules(): khai báo bộ lọc search, taxonomy, status, tác giả, ngày và pagination.
 * - after(): kiểm tra khoảng ngày bắt đầu/kết thúc.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query string từ màn hình danh sách Post.
 * - OUTPUT: dữ liệu đã validate hoặc lỗi HTTP 422.
 * =====================================================================
 */
final class PostIndexRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request list Post sau route permission middleware
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: bool true; route đã kiểm tra posts.view/posts.manage.
     * SIDE EFFECT: không query/ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate filter, sort page và per_page của Post list
     * =====================================================================
     * INPUT: query string search/taxonomy/status/author/date/page/per_page.
     * OUTPUT: mảng validation rules; dữ liệu không hợp lệ trả HTTP 422.
     * SIDE EFFECT: không query/ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'tag_id' => ['sometimes', 'nullable', 'integer', 'exists:tags,id'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PostStatus::values())],
            'author_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bảo đảm khoảng ngày filter không đảo chiều.
     * =====================================================================
     * INPUT: created_from/created_to đã qua format validation.
     * OUTPUT: không trả dữ liệu; thêm lỗi vào request khi from > to.
     * SIDE EFFECT: chỉ thêm lỗi Validator, không query hoặc ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function after(): array
    {
        return [function ($validator): void {
            $from = $this->input('created_from');
            $to = $this->input('created_to');

            if (is_string($from) && is_string($to)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)
                && $from > $to) {
                $validator->errors()->add('created_to', 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.');
            }
        }];
    }
}
