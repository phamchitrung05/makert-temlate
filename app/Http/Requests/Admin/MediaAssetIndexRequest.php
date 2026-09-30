<?php

namespace App\Http\Requests\Admin;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaConversionStatus;
use App\Enums\MediaScanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate bộ lọc danh sách MediaAsset
 * =====================================================================
 *
 * Request khóa các giá trị filter/sort trước khi query database. Filter field
 * được đối chiếu với kind để picker không thể yêu cầu một tổ hợp không hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request tới controller đã gắn permission
 * - rules(): khai báo filter, pagination và sort
 * - withValidator(): kiểm tra tương thích field/kind
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query string của endpoint list Media Library
 * - OUTPUT: query đã chuẩn hóa hoặc HTTP 422 errors theo filter
 * =====================================================================
 */
class MediaAssetIndexRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request tới endpoint list
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; permission media.view được kiểm tra ở route/policy
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule filter và phân trang
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, mixed>>: rule cho query list
     */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::in(MediaAssetKind::values())],
            'field' => ['sometimes', 'string', Rule::in(MediaAssetField::values())],
            'visibility' => ['sometimes', 'string', Rule::in(MediaAssetVisibility::values())],
            'search' => ['sometimes', 'string', 'max:100'],
            'scan_status' => ['sometimes', 'string', Rule::in(MediaScanStatus::values())],
            'conversion_status' => ['sometimes', 'string', Rule::in(MediaConversionStatus::values())],
            'sort' => [
                'sometimes',
                'string',
                Rule::in(['created_at', 'updated_at', 'title', 'kind']),
            ],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra field và kind có cùng contract hay không
     * =====================================================================
     *
     * INPUT: Validator đã chạy xong các rule primitive.
     * OUTPUT: Bổ sung lỗi `field` nếu kind không phù hợp.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = MediaAssetKind::tryFrom((string) $this->input('kind'));
            $field = MediaAssetField::tryFrom((string) $this->input('field'));

            if ($kind !== null && $field !== null && $field->kind() !== $kind) {
                $validator->errors()->add('field', 'Field không tương thích với kind của asset.');
            }
        });
    }
}
