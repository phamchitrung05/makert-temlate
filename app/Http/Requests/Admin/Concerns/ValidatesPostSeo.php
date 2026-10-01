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
    /**
     * =====================================================================
     * CHỨC NĂNG: Gom validation SEO dùng chung cho create/update Post
     * =====================================================================
     * INPUT: Không có đối số.
     * OUTPUT: Mảng rules excerpt/SEO; không đưa điểm SEO vào validation.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function seoRules(): array
    {
        return [
            'excerpt' => ['nullable', 'string', 'max:5000'],
            ...\App\Support\SeoRules::rules(),
        ];
    }
}
