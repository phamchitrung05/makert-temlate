<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload cập nhật metadata Visual Style.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : name/category/is_enabled từ admin HTTP.
 * - OUTPUT: values allowlisted; DNA không sửa qua endpoint này.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI.
 * =====================================================================
 */
final class UpdateVisualStyleRequest extends FormRequest
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
     * CHỨC NĂNG: Validate payload cập nhật metadata style.
     * =====================================================================
     * Input: HTTP payload. Output: rules cho name/category/is_enabled/shots;
     * service chặn bật is_enabled khi style chưa active và sync is_active từng shot.
     * Side effect: không ghi database.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'shots' => ['sometimes', 'array'],
            'shots.*.id' => ['required', 'integer', 'exists:visual_style_shots,id'],
            'shots.*.is_active' => ['required', 'boolean'],
        ];
    }
}
