<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate setting AI typed; model compatibility được service kiểm tra.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT: settings IDs và tuning từ HTTP request.
 * OUTPUT: validation rules typed; compatibility model được service kiểm tra lại.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
final class AiSettingsRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request sau khi route đã kiểm tra ai_settings.manage
     * =====================================================================
     * INPUT: request authenticated.
     * OUTPUT: true để Laravel chạy rules; permission thuộc middleware route.
     * SIDE EFFECT: không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate model IDs và tuning typed
     * =====================================================================
     * INPUT: defaults/fallback IDs và request tuning.
     * OUTPUT: values được whitelist; compatibility được service kiểm tra lại.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'default_text_model_id' => ['nullable', 'integer', 'min:1'],
            'default_image_model_id' => ['nullable', 'integer', 'min:1'],
            'fallback_text_model_id' => ['nullable', 'integer', 'min:1'],
            'fallback_image_model_id' => ['nullable', 'integer', 'min:1'],
            'default_temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'request_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
        ];
    }
}
