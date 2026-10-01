<?php

namespace App\Http\Requests\Admin;

use App\Enums\AiCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate model ID/capability thủ công, không suy đoán ảnh từ tên.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT: model metadata và route provider/model.
 * OUTPUT: validation rules cho remote ID unique theo provider và capability allowlist.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
final class AiModelRequest extends FormRequest
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
     * CHỨC NĂNG: Validate remote model ID và capability enum distinct
     * =====================================================================
     * INPUT: model catalog.
     * OUTPUT: model payload unique theo provider và capability allowlist.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        $provider = $this->route('provider');
        $model = $this->route('model');

        return [
            'remote_model_id' => [
                'required', 'string', 'max:190', 'regex:/^[^\\x00-\\x1F]+$/',
                Rule::unique('ai_models', 'remote_model_id')
                    ->where(fn ($query) => $query->where('ai_provider_id', is_object($provider) ? $provider->getKey() : $provider))
                    ->ignore(is_object($model) ? $model->getKey() : null),
            ],
            'label' => ['nullable', 'string', 'max:190'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', 'distinct', Rule::in(array_column(AiCapability::cases(), 'value'))],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }
}
