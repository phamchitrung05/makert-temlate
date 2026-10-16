<?php

namespace App\Http\Requests\Admin\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate tạo Social Post và chọn target Page.
 * =====================================================================
 * Caption có thể để trống để AI tạo; mọi platform dùng cùng canonical caption.
 *
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request admin -> payload Social Post bounded.
 * =====================================================================
 */
final class SocialPostStoreRequest extends FormRequest
{
    /**
     * Cho phép request sau middleware social_posts.create.
     *
     * Input: admin request.
     * Output: true; permission thuộc middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate context và model selections cho generation.
     *
     * Input: JSON body tạo Social Post.
     * Output: whitelist payload; không ghi DB/gọi AI.
     */
    public function rules(): array
    {
        return [
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'caption' => ['nullable', 'string', 'max:5000'],
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
            'facebook_image_ids' => ['sometimes', 'array', 'max:4'],
            'facebook_image_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
            'target_account_ids' => ['sometimes', 'array', 'max:20'],
            'target_account_ids.*' => ['integer', 'distinct', Rule::exists('social_accounts', 'id')->whereNull('deleted_at')],
            'generate' => ['sometimes', 'boolean'],
            'writing_profile_id' => ['nullable', 'integer', 'min:1'],
            'text_provider' => ['nullable', 'string', 'max:80'],
            'text_model' => ['nullable', 'string', 'max:190'],
            'text_model_id' => ['nullable', 'integer', 'min:1'],
            'image_provider' => ['nullable', 'string', 'max:80'],
            'image_model' => ['nullable', 'string', 'max:190'],
            'image_model_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
