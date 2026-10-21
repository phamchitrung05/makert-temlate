<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload sắp xếp lại thứ tự Shot Recipe.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ordered_ids — danh sách id shot theo thứ tự mới từ admin HTTP.
 * - OUTPUT: values allowlisted; đầy đủ/chính chủ do service đối chiếu.
 * - SIDE EFFECT: validation, không ghi DB/gọi AI.
 * =====================================================================
 */
final class ReorderShotsRequest extends FormRequest
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
     * CHỨC NĂNG: Validate danh sách id shot theo thứ tự mới.
     * =====================================================================
     * Input: HTTP payload. Output: ordered_ids là mảng id integer không trùng;
     * service kiểm danh sách phải khớp đúng toàn bộ shot của style.
     * Side effect: không ghi database.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1', 'max:100'],
            'ordered_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
