<?php

namespace App\Http\Requests\Admin\Concerns;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chia sẻ validation metadata SEO cho create/update Post.
 * CÁC HÀM/METHOD TRONG FILE: seoRules(): khai báo giới hạn dữ liệu.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): không có -> mảng Laravel rules.
 * =====================================================================
 */
trait ValidatesPostSeo
{
    /** Input: không có. Output: rules; ngưỡng chấm SEO không phải lỗi lưu. */
    protected function seoRules(): array
    {
        return [
            'excerpt' => ['nullable', 'string', 'max:5000'],
            ...\App\Support\SeoRules::rules(),
        ];
    }
}
