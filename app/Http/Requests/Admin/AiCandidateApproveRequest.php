<?php

namespace App\Http\Requests\Admin;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate quyết định duyệt thành Post draft mới.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: prepareForValidation(), rules(); authorize() kế thừa.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload -> fields/version/lý do allowlist.
 * Quyền/owner do review service kiểm tra; không nhận actor/thời điểm từ client.
 * =====================================================================
 */
class AiCandidateApproveRequest extends AiCandidateApplyRequest
{
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
     * CHỨC NĂNG: Khai báo field/title, hash và reason hợp lệ cho Post nháp mới.
     * INPUT: payload duyệt, không tin actor/status từ client.
     * OUTPUT: rules hai version bắt buộc; cấm target_id/expected_updated_at.
     * SIDE EFFECT: không ghi DB; validation lỗi trả 422 qua FormRequest.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'fields' => ['required', 'array', 'min:1', 'contains:title'],
            'expected_version' => ['required', 'string', 'size:64'],
            'expected_review_version' => ['required', 'string', 'size:64'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'target_id' => ['prohibited'],
            'expected_updated_at' => ['prohibited'],
        ];
    }
}
