<?php

namespace App\Http\Requests\Admin;

use App\Enums\MediaAssetField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate payload reorder usage của MediaAsset
 * =====================================================================
 *
 * Request kiểm tra danh sách usage theo thứ tự mới. Service phía sau vẫn là
 * nơi xác nhận toàn bộ id thuộc cùng model/field và field cho phép multiple.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request tới controller đã gắn permission
 * - rules(): khai báo field, linkable và usage_ids
 * - withValidator(): kiểm tra field thuộc đúng morph alias
 * - morphAliases(): lấy alias được khai báo trong enum field
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload field, linkable_type/id và usage_ids theo thứ tự mới
 * - OUTPUT: payload hợp lệ hoặc HTTP 422 errors theo field
 * =====================================================================
 */
class MediaAssetReorderRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request tới endpoint reorder
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; permission media.attach được kiểm tra ở route
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule danh sách usage theo thứ tự mới
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, mixed>>: rule reorder usage
     */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(MediaAssetField::values())],
            'linkable_type' => ['required', 'string', Rule::in($this->morphAliases())],
            'linkable_id' => ['required', 'integer', 'min:1'],
            'usage_ids' => ['required', 'array', 'min:1'],
            'usage_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra field và morph alias có cùng model không
     * =====================================================================
     *
     * INPUT: Validator đã chạy xong rule primitive.
     * OUTPUT: Bổ sung lỗi nếu field không thuộc linkable_type.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $field = MediaAssetField::tryFrom((string) $this->input('field'));
            $linkableType = (string) $this->input('linkable_type');

            if ($field !== null && $linkableType !== '' && $field->linkableMorphAlias() !== $linkableType) {
                $validator->errors()->add('linkable_type', 'Field không thuộc model linkable đã chọn.');
            }
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy danh sách morph alias được phép nhận từ API
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: alias duy nhất từ MediaAssetField
     */
    private function morphAliases(): array
    {
        return collect(MediaAssetField::cases())
            ->map(fn (MediaAssetField $field): string => $field->linkableMorphAlias())
            ->unique()
            ->values()
            ->all();
    }
}
