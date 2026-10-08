<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate bộ lọc kho bài AI đã duyệt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules(), after().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : search, khoảng ngày lưu kho và phân trang từ màn hình AI Approved.
 * - OUTPUT: query đã chuẩn hóa hoặc HTTP 422; không ghi database.
 * =====================================================================
 */
final class AiArticleArchiveIndexRequest extends FormRequest
{
    /** Input: request sau middleware posts.manage. Output: cho phép controller xử lý. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: query filter. Output: rules giới hạn giá trị và kích thước trang. */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** Input: hai ngày đã qua format validation. Output: lỗi nếu khoảng ngày đảo chiều. */
    public function after(): array
    {
        return [function ($validator): void {
            $from = $this->input('created_from');
            $to = $this->input('created_to');

            if (is_string($from) && is_string($to) && $from > $to) {
                $validator->errors()->add('created_to', 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.');
            }
        }];
    }
}
