<?php

namespace App\Http\Requests\Admin;

use App\Enums\MediaAssetField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate payload attach MediaAsset vào model nghiệp vụ
 * =====================================================================
 *
 * Request chỉ kiểm tra shape và đảm bảo field cùng morph alias. Kiểm tra model
 * tồn tại, kind, cardinality và permission được giữ ở resolver/usage service.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request tới controller đã gắn permission
 * - rules(): khai báo field, linkable và thứ tự
 * - withValidator(): kiểm tra field thuộc đúng morph alias
 * - morphAliases(): lấy alias được khai báo trong enum field
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload attach gồm field, linkable_type/id và sort_order
 * - OUTPUT: payload hợp lệ hoặc HTTP 422 errors theo field
 * =====================================================================
 */
class MediaAssetUsageRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request tới endpoint attach
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; permission media.attach được kiểm tra ở route/policy
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule payload attach
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, mixed>>: rule attach usage
     */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(MediaAssetField::values())],
            'linkable_type' => ['required', 'string', Rule::in($this->morphAliases())],
            'linkable_id' => ['required', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
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
