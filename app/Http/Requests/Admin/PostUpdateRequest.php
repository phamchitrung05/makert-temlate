<?php

namespace App\Http\Requests\Admin;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate cập nhật từng phần Post, media và SEO.
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules(); seoRules() từ trait.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request admin -> dữ liệu hợp lệ hoặc lỗi 422.
 * =====================================================================
 */
class PostUpdateRequest extends FormRequest
{
    use \App\Http\Requests\Admin\Concerns\ValidatesPostSeo;
    use \App\Http\Requests\Admin\Concerns\ValidatesAiProvenance;

    /** Input: request đã qua middleware posts.manage. Output: cho phép validation. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: không có. Output: rules partial update, title có gửi thì không được rỗng. */
    public function rules(): array
    {
        return [
            ...$this->seoRules(),
            ...$this->aiProvenanceRules(),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(PostStatus::values())],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
            'media' => ['sometimes', 'array'],
            'media.thumbnail_id' => ['nullable', 'integer', 'min:1'],
            'media.content_image_ids' => ['sometimes', 'array'],
            'media.content_image_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
