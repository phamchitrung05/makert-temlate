<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload tạo Shot Recipe cho Visual Style.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : code/name/override/suffix/ratio từ admin HTTP.
 * - OUTPUT: values allowlisted; enum override do Definition kiểm ở service.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI.
 * =====================================================================
 */
final class StoreShotRequest extends FormRequest
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
     * CHỨC NĂNG: Validate payload tạo shot recipe.
     * =====================================================================
     * Input: HTTP payload. Output: rules cho code/name bắt buộc và các field
     * tùy chọn; override chỉ nhận object — field/enum do service kiểm tiếp.
     * Side effect: không ghi database.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:160'],
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
