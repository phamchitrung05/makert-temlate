<?php

namespace App\Http\Requests\Admin\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate chỉnh sửa caption/context/targets Social Post.
 * =====================================================================
 * Mọi sửa nội dung sau approve sẽ được lifecycle service đưa về draft.
 *
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request -> payload update whitelist.
 * =====================================================================
 */
final class SocialPostUpdateRequest extends FormRequest
{
    /**
     * Cho phép request sau middleware social_posts.update.
     *
     * Input: admin request.
     * Output: true; không ghi database.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate field update và target account.
     *
     * Input: JSON body.
     * Output: payload bounded; không gọi AI.
     */
    public function rules(): array
    {
        return [
            'campaign_id' => ['sometimes', 'nullable', 'integer', 'exists:campaigns,id'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_account_ids' => ['sometimes', 'array', 'max:20'],
            'target_account_ids.*' => ['integer', 'distinct', Rule::exists('social_accounts', 'id')->whereNull('deleted_at')],
        ];
    }
}
