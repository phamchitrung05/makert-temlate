<?php

namespace App\Http\Requests\Admin\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate lý do reject/request revision Social Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request -> reason bounded hoặc 422.
 * =====================================================================
 */
final class SocialPostReviewRequest extends FormRequest
{
    /**
     * Cho phép request sau middleware social_posts.review.
     *
     * Input: admin request.
     * Output: true; permission thuộc middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate reason bắt buộc cho reject/request revision.
     *
     * Input: reason text.
     * Output: whitelist reason; không ghi database.
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
