<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload queue phân tích ảnh mẫu thành Style DNA.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : name/model_id/category + file ảnh tham chiếu từ admin HTTP.
 * - OUTPUT: values allowlisted; snapshot model do server resolve.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI; service kiểm quota/queue.
 * =====================================================================
 */
final class AnalyzeVisualStyleRequest extends FormRequest
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
     * CHỨC NĂNG: Validate ảnh mẫu và model Vision trước khi queue.
     * =====================================================================
     * Input: HTTP payload multipart. Output: rules bounded cho ảnh ≤5MB,
     * định dạng web phổ biến và model_id bắt buộc.
     * Side effect: không ghi database; capability model do service kiểm.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model_id' => ['required', 'integer', 'min:1'],
            'image' => ['required_without:media_asset_id', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8192,max_height=8192'],
            'media_asset_id' => ['required_without:image', 'nullable', 'integer', 'min:1', 'exists:media_assets,id'],
        ];
    }
}
