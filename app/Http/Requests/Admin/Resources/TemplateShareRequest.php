<?php

namespace App\Http\Requests\Admin\Resources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate capability/expiry cho share link template
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): JSON share -> dữ liệu bounded/422.
 * =====================================================================
 */
final class TemplateShareRequest extends FormRequest
{
    /** Input: admin route middleware. Output: true. */
    public function authorize(): bool { return true; }

    /** Input: capability và days. Output: validation rules. */
    public function rules(): array
    {
        return [
            'capability' => ['sometimes', 'string', 'in:preview,download'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }
}
