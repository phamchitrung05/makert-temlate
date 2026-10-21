<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload cập nhật Shot Recipe của Visual Style.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : field shot cần sửa từ admin HTTP.
 * - OUTPUT: values allowlisted; enum override do Definition kiểm ở service.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI.
 * =====================================================================
 */
final class UpdateShotRequest extends FormRequest
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
     * CHỨC NĂNG: Validate payload cập nhật shot recipe (partial).
     * =====================================================================
     * Input: HTTP payload. Output: rules giống store nhưng mọi field tùy chọn;
     * code đổi mới vẫn phải đúng định dạng.
     * Side effect: không ghi database.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^[a-z0-9_\-]+$/'],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'composition_override' => ['sometimes', 'nullable', 'array'],
            'camera_override' => ['sometimes', 'nullable', 'array'],
            'prompt_suffix' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'suggested_aspect_ratio' => ['sometimes', 'nullable', 'string', 'max:16'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
