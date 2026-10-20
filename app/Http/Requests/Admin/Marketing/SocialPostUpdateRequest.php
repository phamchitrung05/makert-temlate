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
            'product_id' => ['prohibited'],
            'campaign_id' => ['sometimes', 'nullable', 'integer', 'exists:campaigns,id'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'facebook_content' => ['sometimes', 'array'],
            'facebook_content.body' => ['required_with:facebook_content', 'string', 'max:5000'],
            'facebook_content.title' => ['nullable', 'string', 'max:255'],
            'facebook_content.bullet_points' => ['nullable', 'array', 'max:8'],
            'facebook_content.bullet_points.*' => ['string', 'max:300'],
            'facebook_content.cta' => ['nullable', 'string', 'max:500'],
            'facebook_content.contact' => ['nullable', 'array'],
            'facebook_content.contact.address' => ['nullable', 'string', 'max:500'],
            'facebook_content.contact.phone' => ['nullable', 'string', 'max:120'],
            'facebook_content.contact.website' => ['nullable', 'string', 'max:500'],
            'facebook_content.hashtags' => ['nullable', 'array', 'max:12'],
            'facebook_content.hashtags.*' => ['string', 'max:80'],
            'facebook_content.link_url' => ['nullable', 'url', 'max:1000'],
            'facebook_image_ids' => ['sometimes', 'array', 'max:10'],
            'facebook_image_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
            'target_account_ids' => ['sometimes', 'array', 'max:20'],
            'target_account_ids.*' => ['integer', 'distinct', Rule::exists('social_accounts', 'id')->whereNull('deleted_at')],
        ];
    }
}
