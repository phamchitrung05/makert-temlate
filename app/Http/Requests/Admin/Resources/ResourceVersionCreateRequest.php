<?php

namespace App\Http\Requests\Admin\Resources;

use App\Enums\ResourceVersionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate payload tạo Resource Version và media fields.
 * =====================================================================
 * Request chỉ kiểm tra shape, trạng thái và uniqueness theo resource; việc
 * ghi dữ liệu thuộc CreateResourceVersionAction.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): xác định request có thể đi vào boundary.
 * - rules(): khai báo contract tạo version và media.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload tạo Resource Version.
 * - OUTPUT: dữ liệu hợp lệ hoặc lỗi 422; không ghi database/provider.
 * =====================================================================
 */
class ResourceVersionCreateRequest extends FormRequest
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
     * Khai báo các field hợp lệ khi tạo Resource Version.
     *
     * Input: payload hiện tại, gồm resource_id và media.
     * Output: mảng Laravel validation rules.
     */
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
