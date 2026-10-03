<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate dữ liệu editor của candidate AI.
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload chưa tin cậy -> field biên tập whitelist.
 * =====================================================================
 */
class AiCandidateUpdateRequest extends FormRequest
{
    /** Input: actor. Output: true; controller kiểm tra owner/quyền và trạng thái. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: JSON editor. Output: rule typed; lỗi validation trả 422. */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string', 'size:64'],
            'title' => ['required', 'string', 'max:255'],
            'content_html' => ['required', 'string', 'max:200000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'focus_keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
