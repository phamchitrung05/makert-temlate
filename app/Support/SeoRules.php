<?php

namespace App\Support;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validation SEO dùng chung, không phụ thuộc model nội dung.
 * CÁC HÀM/METHOD TRONG FILE: rules(): danh mục field/rule được phép ghi.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): không có -> rules Laravel; không ghi DB.
 * =====================================================================
 */
final class SeoRules
{
    /** Input: không có. Output: rules cho payload partial; null xóa giá trị ghi đè. */
    public static function rules(): array
    {
        return [
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:5000'],
            'canonical_url' => ['nullable', 'url:http,https', 'max:2048'],
            'robots_index' => ['sometimes', 'boolean'],
            'robots_follow' => ['sometimes', 'boolean'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:5000'],
            'og_image_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
