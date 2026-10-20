<?php

namespace App\Http\Requests\Admin\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate quyết định duyệt candidate thành bản nháp mới.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), prepareForValidation(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload -> fields/taxonomy/version/lý do allowlist.
 * Quyền/owner do review service kiểm tra; không nhận actor/thời điểm/target từ client.
 * =====================================================================
 */
class AiCandidateApproveRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi qua; review service kiểm owner/quyền/target.
     * INPUT: authenticated admin từ route middleware.
     * OUTPUT: true; không tự kiểm permission tại đây.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa ghi chú duyệt trước khi validate.
     * INPUT: reason tùy chọn trong HTTP payload.
     * OUTPUT: reason đã trim nếu là string; không ghi database.
     * =====================================================================
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo field, taxonomy, hai version và lý do hợp lệ khi duyệt.
     * INPUT: payload duyệt; không tin actor/status/target từ client.
     * OUTPUT: rules; Post bắt buộc chọn title, Social chỉ nhận output platform.
     * SIDE EFFECT: không ghi DB; validation lỗi trả 422 qua FormRequest.
     * =====================================================================
     */
    public function rules(): array
    {
        $candidate = $this->route('aiImport');
        $isSocial = data_get($candidate?->input_json, 'target_type', 'post') === 'social_post';

        return [
            'fields' => $isSocial
                ? ['required', 'array', 'min:1']
                : ['required', 'array', 'min:1', 'contains:title'],
            'fields.*' => ['string', 'distinct', 'in:title,excerpt,content,seo,taxonomy,thumbnail,social_content,social_gallery,social_excerpt,social_video'],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'expected_version' => ['required', 'string', 'size:64'],
            'expected_review_version' => ['required', 'string', 'size:64'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
