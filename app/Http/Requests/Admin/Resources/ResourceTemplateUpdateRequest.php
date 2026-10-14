<?php

namespace App\Http\Requests\Admin\Resources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate entry/title/order/link settings của template draft
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): route middleware quyết định permission.
 * - rules(): whitelist settings và screen rows.
 * INPUT/OUTPUT CỦA CLASS: JSON settings -> dữ liệu hợp lệ/422.
 * =====================================================================
 */
final class ResourceTemplateUpdateRequest extends FormRequest
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
     * INPUT: entry_screen_slug và screen settings bounded.
     * OUTPUT: rules validation; không ghi DB.
     * SIDE EFFECT: Không có.
     */
    public function rules(): array
    {
        return [
            'entry_screen_slug' => ['sometimes', 'nullable', 'string', 'max:120'],
            'screens' => ['sometimes', 'array', 'max:1000'],
            'screens.*.id' => ['required', 'integer', 'min:1'],
            'screens.*.title' => ['sometimes', 'string', 'max:180'],
            'screens.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'screens.*.metadata' => ['sometimes', 'array'],
            'screens.*.metadata.link_mapping' => ['sometimes', 'array', 'max:100'],
            'screens.*.metadata.link_mapping.*' => ['string', 'max:300'],
        ];
    }
}
