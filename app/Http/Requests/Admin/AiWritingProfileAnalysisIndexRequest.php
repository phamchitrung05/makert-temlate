<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate bộ lọc danh sách tác vụ phân tích văn phong.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), prepareForValidation(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : page/per_page/status từ popup hàng đợi.
 * - OUTPUT: bộ lọc bounded, chỉ cho phép lifecycle status đã công khai.
 * - SIDE EFFECT: không ghi DB/gọi provider.
 * =====================================================================
 */
final class AiWritingProfileAnalysisIndexRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra quyền quản lý analysis.
     * =====================================================================
     * Input: actor HTTP. Output: boolean quyền hiện hành.
     * Side effect: chỉ đọc permission.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user()?->can('ai_settings.manage') ?? false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa status đơn thành danh sách để API hỗ trợ cả query string và array.
     * =====================================================================
     * Input: status hoặc status[]. Output: status[] không rỗng.
     * Side effect: chỉ thay đổi dữ liệu validation của request.
     * =====================================================================
     */
    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        if (is_string($status)) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $status))))]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn pagination và lifecycle filter.
     * =====================================================================
     * Input: query params. Output: allowlist status queued/analyzing/ready/failed/cancelled.
     * Side effect: không gọi database hoặc queue.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'array', 'max:5'],
            'status.*' => ['string', Rule::in(['queued', 'analyzing', 'ready', 'failed', 'cancelled'])],
        ];
    }
}
