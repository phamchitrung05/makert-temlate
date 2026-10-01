<?php

namespace App\Http\Requests\Admin;

use App\Enums\ResourceVersionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG: Validate payload tạo Resource Version và media fields.
 * =====================================================================
 * INPUT: Không có.
 * OUTPUT: Không trả dữ liệu.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
class ResourceVersionCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'integer', 'exists:resources,id'],
            'version' => [
                'required',
                'string',
                'max:64',
                Rule::unique('resource_versions', 'version')->where(
                    fn ($query) => $query->where('resource_id', $this->integer('resource_id')),
                ),
            ],
            'changelog' => ['nullable', 'string'],
            'requirements' => ['nullable', 'array'],
            'media' => ['sometimes', 'array'],
            'media.package_id' => ['nullable', 'integer', 'min:1'],
            'media.documentation_ids' => ['sometimes', 'array'],
            'media.documentation_ids.*' => ['integer', 'distinct', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in([
                ResourceVersionStatus::Draft->value,
                ResourceVersionStatus::Archived->value,
            ])],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
