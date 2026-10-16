<?php

namespace App\Http\Requests\Admin\Marketing;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate product/fact context cho Marketing AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request -> product payload bounded.
 * =====================================================================
 */
final class ProductRequest extends FormRequest
{
    /** Input: admin request. Output: true sau middleware permission. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: product/facts JSON. Output: rules whitelist; không ghi DB. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'short_description' => ['nullable', 'string', 'max:1200'],
            'default_cta' => ['nullable', 'string', 'max:500'],
            'prohibited_claims' => ['sometimes', 'array', 'max:30'],
            'prohibited_claims.*' => ['string', 'max:300'],
            'status' => ['sometimes', Rule::in(ProductStatus::values())],
            'facts' => ['sometimes', 'array', 'max:100'],
            'facts.*.key' => ['required_with:facts', 'string', 'max:80'],
            'facts.*.value' => ['required_with:facts', 'string', 'max:2000'],
            'facts.*.source' => ['required_with:facts', 'string', 'max:255'],
            'facts.*.verified' => ['sometimes', 'boolean'],
            'facts.*.is_allowed_for_ai' => ['sometimes', 'boolean'],
            'reference_asset_ids' => ['sometimes', 'array', 'max:20'],
            'reference_asset_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
        ];
    }
}
