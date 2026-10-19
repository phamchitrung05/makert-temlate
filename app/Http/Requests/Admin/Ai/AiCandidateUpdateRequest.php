<?php

namespace App\Http\Requests\Admin\Ai;

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
        $candidate = $this->route('aiImport');
        $isSocial = data_get($candidate?->input_json, 'target_type', 'post') === 'social_post';

        return [
            'expected_version' => ['required', 'string', 'size:64'],
            'title' => $isSocial ? ['sometimes', 'nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'content_html' => ['required', 'string', 'max:200000'],
            'excerpt' => $isSocial ? ['sometimes', 'nullable', 'string', 'max:1000'] : ['sometimes', 'nullable', 'string', 'max:5000'],
            'cta' => ['sometimes', 'nullable', 'string', 'max:500'],
            'hashtags' => ['sometimes', 'array', 'max:12'],
            'hashtags.*' => ['string', 'max:80'],
            'link_url' => ['sometimes', 'nullable', 'url', 'max:1000'],
            'gallery_prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'negative_prompt' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', 'max:10'],
            'slot_count' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'seo_title' => $isSocial ? ['prohibited'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => $isSocial ? ['prohibited'] : ['sometimes', 'nullable', 'string', 'max:5000'],
            'focus_keyword' => $isSocial ? ['prohibited'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'category_ids' => $isSocial ? ['prohibited'] : ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'tag_ids' => $isSocial ? ['prohibited'] : ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('status', 'active')->whereNull('deleted_at')],
        ];
    }
}
