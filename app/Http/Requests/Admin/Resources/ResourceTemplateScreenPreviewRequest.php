<?php

namespace App\Http\Requests\Admin\Resources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate screenshot asset attach cho template screen
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): route middleware quyết định permission.
 * - rules(): yêu cầu media asset image bounded.
 * INPUT/OUTPUT CỦA CLASS: media_asset_id -> dữ liệu hợp lệ/422.
 * =====================================================================
 */
final class ResourceTemplateScreenPreviewRequest extends FormRequest
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
     * INPUT: media_asset_id.
     * OUTPUT: rules validation; không ghi DB.
     * SIDE EFFECT: Không có.
     */
    public function rules(): array
    {
        return [
            'media_asset_id' => ['nullable', 'integer', 'min:1', 'exists:media_assets,id'],
        ];
    }
}
