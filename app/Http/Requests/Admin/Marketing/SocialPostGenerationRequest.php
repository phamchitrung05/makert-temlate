<?php

namespace App\Http\Requests\Admin\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate lựa chọn model/profile khi tạo lại Social Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request -> model/profile selection bounded.
 * =====================================================================
 */
final class SocialPostGenerationRequest extends FormRequest
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
     * Validate provider/model IDs được truyền vào generation.
     *
     * Input: JSON body tùy chọn.
     * Output: payload whitelist; không gọi provider.
     */
    public function rules(): array
    {
        return [
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
