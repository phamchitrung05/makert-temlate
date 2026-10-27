<?php

namespace App\Http\Requests\Admin\Marketing;

use App\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate campaign message và product selection.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request -> campaign payload bounded.
 * =====================================================================
 */
final class CampaignRequest extends FormRequest
{
    /** Input: admin request. Output: true sau middleware permission. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: campaign JSON. Output: rules whitelist; không gọi AI. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:1500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(CampaignStatus::values())],
            'product_ids' => ['sometimes', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'keywords_json' => ['nullable', 'array'],
            'hashtags' => ['nullable', 'array'],
            'writing_profile_ids' => ['nullable', 'array'],
            'writing_profile_ids.*' => ['integer', 'exists:ai_writing_profiles,id'],
            'image_profile_ids' => ['nullable', 'array'],
            'image_profile_ids.*' => ['integer', 'exists:ai_image_profiles,id'],
            'subject_media_ids' => ['nullable', 'array', 'max:6'],
            'subject_media_ids.*' => ['integer', 'exists:media_assets,id'],
            'subject_description' => ['nullable', 'string', 'max:2000'],
            'platforms_json' => ['nullable', 'array'],
            'schedule_config_json' => ['nullable', 'array'],
            'advanced_config_json' => ['nullable', 'array'],
        ];
    }
}
