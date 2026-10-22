<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload tạo Visual Style thủ công (không ảnh).
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : name/category/dna tùy chọn từ admin HTTP.
 * - OUTPUT: values allowlisted; actor/status do server quyết định.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI.
 * =====================================================================
 */
final class StoreVisualStyleRequest extends FormRequest
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
     * CHỨC NĂNG: Validate payload tạo style thủ công.
     * =====================================================================
     * Input: HTTP payload. Output: rules cho name/category/dna object;
     * dna chi tiết được VisualStyleDnaDefinition kiểm tiếp ở service.
     * Side effect: không ghi database.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'dna' => ['sometimes', 'array'],
        ];
    }
}
