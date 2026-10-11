<?php

namespace App\Http\Requests\Admin\Resources;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate payload cập nhật Resource Version và media.
 * =====================================================================
 * Request giữ uniqueness theo resource hiện tại và chỉ kiểm tra shape;
 * việc cập nhật thuộc UpdateResourceVersionAction.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): xác định request có thể đi vào boundary.
 * - rules(): khai báo contract cập nhật version và media.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload update và route ResourceVersion hiện tại.
 * - OUTPUT: dữ liệu hợp lệ hoặc lỗi 422; không ghi database/provider.
 * =====================================================================
 */
class ResourceVersionUpdateRequest extends FormRequest
{
    /**
     * Xác nhận request được phép đi qua lớp validation.
     *
     * Input: không có.
     * Output: true; quyền cụ thể được route middleware kiểm tra.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Khai báo validation rules và loại trừ version hiện tại khỏi unique.
     *
     * Input: payload và route resourceVersion.
     * Output: mảng Laravel validation rules.
     */
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
