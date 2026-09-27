<?php

namespace App\Http\Requests\Admin;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\MediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate metadata cập nhật cho MediaAsset
 * =====================================================================
 *
 * Request này chỉ nhận metadata nghiệp vụ; file vật lý và custom properties
 * xử lý upload không được ghi đè từ endpoint cập nhật. Việc kiểm tra quyền
 * cập nhật vẫn do policy hoặc permission middleware của Task 5 đảm nhiệm.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tới controller đã gắn permission
 * - rules(): khai báo các trường metadata có thể cập nhật
 * - withValidator(): chặn archive chuyển sang visibility public
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload PATCH metadata của MediaAsset và route model
 * - OUTPUT: metadata hợp lệ hoặc HTTP 422 errors theo field
 * =====================================================================
 */
class MediaAssetMetadataUpdateRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request tới controller cập nhật metadata
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; permission media.upload được kiểm tra ở route/policy
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule metadata được phép thay đổi
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule PATCH dạng partial update
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'visibility' => [
                'sometimes',
                'string',
                'in:'.implode(',', MediaAssetVisibility::values()),
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra visibility mới có phù hợp với kind asset không
     * =====================================================================
     *
     * INPUT: Validator đã chạy xong rule shape và route model mediaAsset.
     * OUTPUT: Bổ sung lỗi nếu archive bị yêu cầu chuyển sang public.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mediaAsset = $this->route('mediaAsset');
            $kind = $mediaAsset instanceof MediaAsset ? $mediaAsset->kind : null;
            $visibility = MediaAssetVisibility::tryFrom((string) $this->input('visibility'));

            if ($kind === MediaAssetKind::Archive && $visibility === MediaAssetVisibility::Public) {
                $validator->errors()->add('visibility', 'Archive/package bắt buộc ở private disk.');
            }
        });
    }
}
