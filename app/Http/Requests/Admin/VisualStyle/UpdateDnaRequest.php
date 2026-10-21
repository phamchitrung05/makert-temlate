<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload lưu Style DNA đã chỉnh tay.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dna object + expected_version optimistic lock từ admin HTTP.
 * - OUTPUT: values allowlisted; enum/required do VisualStyleDnaDefinition kiểm.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI.
 * =====================================================================
 */
final class UpdateDnaRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra quyền quản lý AI settings tại FormRequest.
     * =====================================================================
     * Input: actor authenticated. Output: true khi có ai_settings.manage.
     * Side effect: đọc permissions; middleware route kiểm tra cùng quyền.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user()?->can('ai_settings.manage') ?? false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate DNA object và expected_version bắt buộc.
     * =====================================================================
     * Input: HTTP payload. Output: rules cho dna/expected_version; chi tiết
     * enum từng section được Definition validate ở service.
     * Side effect: không ghi database.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'dna' => ['required', 'array'],
            'dna.composition' => ['required', 'array'],
            'dna.camera' => ['required', 'array'],
            'dna.lighting' => ['required', 'array'],
            'dna.environment' => ['required', 'array'],
            'dna.color_mood' => ['required', 'array'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
