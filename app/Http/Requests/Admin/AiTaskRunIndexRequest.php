<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate filter cho trung tâm task AI dùng chung.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): yêu cầu actor đã authenticated.
 * - prepareForValidation(): tách CSV status/task_type thành mảng đã trim.
 * - rules(): giới hạn pagination, lifecycle status và task_type.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query status/task_type/page/per_page.
 * - OUTPUT: allowlist filter đã chuẩn hóa; không truy cập dữ liệu task.
 * - SIDE EFFECT: chỉ biến đổi input validation.
 * =====================================================================
 */
final class AiTaskRunIndexRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ actor đã xác thực mới được đọc task của chính mình.
     * =====================================================================
     * INPUT: request guard hiện tại. OUTPUT: boolean authorization.
     * SIDE EFFECT: không ghi DB. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa CSV query thành mảng trước khi validate.
     * =====================================================================
     * INPUT: status/task_type dạng chuỗi hoặc mảng. OUTPUT: mảng trim bounded.
     * SIDE EFFECT: chỉ merge input request. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $this->input('status')))))]);
        }
        if (is_string($this->input('task_type'))) {
            $this->merge(['task_type' => array_values(array_filter(array_map('trim', explode(',', $this->input('task_type')))))]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate filter task center theo allowlist bounded.
     * =====================================================================
     * INPUT: query page/per_page/status/task_type. OUTPUT: rules Laravel.
     * SIDE EFFECT: không truy cập source hoặc provider. EXCEPTION/TRANSACTION: validation lỗi 422.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'array', 'max:8'],
            'status.*' => ['string', Rule::in(['queued', 'running', 'processing', 'analyzing', 'ready', 'failed', 'cancelled', 'expired'])],
            'task_type' => ['sometimes', 'array', 'max:8'],
            'task_type.*' => ['string', 'max:80'],
        ];
    }
}
