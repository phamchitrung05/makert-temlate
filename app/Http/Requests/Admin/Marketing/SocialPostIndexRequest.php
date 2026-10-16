<?php

namespace App\Http\Requests\Admin\Marketing;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate filter và phân trang danh sách Social Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request filter -> dữ liệu bounded hoặc 422.
 * =====================================================================
 */
final class SocialPostIndexRequest extends FormRequest
{
    /**
     * Cho phép request sau khi route đã kiểm tra social_posts.view.
     *
     * Input: request admin đã authenticated.
     * Output: true; không ghi database.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Khai báo filter không cho phép query tùy ý từ client.
     *
     * Input: query string.
     * Output: rules filter/pagination; không gọi provider.
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:160'],
            'campaign_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'platform' => ['nullable', Rule::in(SocialPlatform::values())],
            'generation_status' => ['nullable', Rule::in(SocialPostGenerationStatus::values())],
            'review_status' => ['nullable', Rule::in(SocialPostReviewStatus::values())],
            'publication_status' => ['nullable', Rule::in(SocialPostPublicationStatus::values())],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
