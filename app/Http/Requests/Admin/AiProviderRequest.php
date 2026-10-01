<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate connection AI trước khi ghi catalog.
 * METHODS: authorize(), rules(). INPUT: metadata và API key write-only.
 * OUTPUT: dữ liệu đã whitelist; key không bao giờ được trả ngược từ API.
 * =====================================================================
 */
final class AiProviderRequest extends FormRequest
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
     * CHỨC NĂNG: Validate metadata official/gateway và API key write-only
     * =====================================================================
     * INPUT: POST/PATCH provider.
     * OUTPUT: payload được whitelist, API key không được trả ngược.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        $provider = $this->route('provider');
        $isUpdate = is_object($provider) && $provider->exists;

        return [
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', 'string', Rule::in(array_keys((array) config('ai-providers.presets', [])))],
            'base_url' => ['nullable', 'url:https', 'max:2048', 'required_if:driver,openai-compatible'],
            'api_key' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:10000'],
            'discovery_mode' => ['nullable', Rule::in(['models_endpoint', 'manual'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
