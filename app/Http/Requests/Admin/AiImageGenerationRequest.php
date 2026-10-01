<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate prompt/model override cho tác vụ tạo ảnh độc lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT: HTTP request prompt và model override.
 * OUTPUT: validation rules/whitelisted payload; permission được route middleware kiểm tra.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
final class AiImageGenerationRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request sau khi route đã kiểm tra media.upload
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
     * CHỨC NĂNG: Validate prompt và model override tạo ảnh
     * =====================================================================
     * INPUT: prompt + optional provider/model/model_id.
     * OUTPUT: payload được whitelist trước khi resolver chọn model.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'title' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:190'],
            'model_id' => ['nullable', 'integer', 'min:1'],
            'regenerate' => ['sometimes', 'boolean'],
        ];
    }
}
