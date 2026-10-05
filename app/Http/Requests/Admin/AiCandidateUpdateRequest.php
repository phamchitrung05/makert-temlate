<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate dữ liệu editor của candidate AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload chưa tin cậy -> field biên tập whitelist.
 * =====================================================================
 */
class AiCandidateUpdateRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ ownership và quyền tại controller candidate
     * =====================================================================
     * INPUT: request admin đã authenticated.
     * OUTPUT: true; controller kiểm tra owner, quyền và trạng thái ready.
     * SIDE EFFECT: không gọi provider hoặc ghi database.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Allowlist dữ liệu biên tập và taxonomy chọn thủ công
     * =====================================================================
     * INPUT: JSON editor chưa tin cậy.
     * OUTPUT: rules cho version, HTML, SEO và ID taxonomy active.
     * SIDE EFFECT: validation chỉ đọc catalog; không gọi provider hoặc ghi Post.
     * =====================================================================
     */
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
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('status', 'active')->whereNull('deleted_at')],
        ];
    }
}
