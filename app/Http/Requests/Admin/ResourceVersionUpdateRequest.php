<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG: Validate payload cập nhật Resource Version và media fields.
 * =====================================================================
 * INPUT: Không có.
 * OUTPUT: Không trả dữ liệu.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
class ResourceVersionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $version = $this->route('resourceVersion');
        $versionId = is_object($version) ? $version->getKey() : $version;
        $resourceId = is_object($version) ? $version->resource_id : null;

        return [
            'version' => [
                'sometimes',
                'string',
                'max:64',
                Rule::unique('resource_versions', 'version')
                    ->where(fn ($query) => $query->where('resource_id', $resourceId))
                    ->ignore($versionId),
            ],
            'changelog' => ['nullable', 'string'],
            'requirements' => ['nullable', 'array'],
            'media' => ['sometimes', 'array'],
            'media.package_id' => ['nullable', 'integer', 'min:1'],
            'media.documentation_ids' => ['sometimes', 'array'],
            'media.documentation_ids.*' => ['integer', 'distinct', 'min:1'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
