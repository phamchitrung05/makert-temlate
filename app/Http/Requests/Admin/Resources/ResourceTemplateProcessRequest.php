<?php

namespace App\Http\Requests\Admin\Resources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate yêu cầu enqueue/rebuild Resource Template
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): route middleware quyết định permission.
 * - rules(): contract bounded của process request.
 * INPUT/OUTPUT CỦA CLASS: payload force tùy chọn -> dữ liệu hợp lệ/422.
 * =====================================================================
 */
final class ResourceTemplateProcessRequest extends FormRequest
{
    /**
     * INPUT: authenticated admin đã qua route middleware.
     * OUTPUT: true; permission được kiểm ở route.
     * SIDE EFFECT: Không có.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * INPUT: force flag tùy chọn.
     * OUTPUT: rules validation; không ghi DB.
     * SIDE EFFECT: Không có.
     */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
