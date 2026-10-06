<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate phiên bản và lý do từ chối bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), prepareForValidation(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload -> lý do có nội dung và version.
 * SIDE EFFECT: không ghi database; actor/quyền/thời điểm do server quyết định.
 * =====================================================================
 */
class AiCandidateRejectRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho validation đi tiếp sau middleware admin/posts.manage.
     * INPUT: authenticated admin request từ route.
     * OUTPUT: true; service kiểm owner/permission, không ghi database.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa lý do trước khi kiểm required.
     * INPUT: reason chưa tin cậy trong payload.
     * OUTPUT: string đã trim nếu đúng kiểu, không mutation database.
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
     * CHỨC NĂNG: Khai báo lý do bắt buộc và hai hash của bản đã xem.
     * INPUT: HTTP payload từ chối; actor/thời điểm không do client quyết định.
     * OUTPUT: rules length/hash/reason; lỗi validation trả 422 qua FormRequest.
     * SIDE EFFECT: không ghi DB hoặc mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string', 'size:64'],
            'expected_review_version' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
