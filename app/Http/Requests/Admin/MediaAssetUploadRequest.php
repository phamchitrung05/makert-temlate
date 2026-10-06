<?php

namespace App\Http\Requests\Admin;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate payload HTTP upload MediaAsset
 * =====================================================================
 *
 * Request kiểm tra shape và enum ở cổng HTTP. MIME, extension, size thực tế
 * và nội dung file tiếp tục được MediaUploadValidator kiểm tra bằng bytes sau
 * khi request vượt qua FormRequest.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tới controller đã gắn permission
 * - rules(): rule file, kind, visibility, field và metadata
 * - withValidator(): kiểm tra tương thích kind/field và private archive
 * - maxUploadSizeKb(): lấy giới hạn lớn nhất cho rule HTTP
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : multipart upload từ admin
 * - OUTPUT: payload shape hợp lệ hoặc HTTP 422 errors theo field
 * =====================================================================
 */
class MediaAssetUploadRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request tới controller upload
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
     * CHỨC NĂNG: Khai báo rule shape của multipart upload
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, mixed>>: rules cho file và metadata
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.$this->maxUploadSizeKb()],
            'kind' => ['required', 'string', 'in:'.implode(',', MediaAssetKind::values())],
            'title' => ['required', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['nullable', 'string', 'in:'.implode(',', MediaAssetVisibility::values())],
            'field' => ['nullable', 'string', 'in:'.implode(',', MediaAssetField::values())],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra rule liên kết giữa kind, field và visibility
     * =====================================================================
     *
     * INPUT: Validator đã chạy rules shape cơ bản.
     * OUTPUT: Bổ sung errors nếu field sai kind hoặc archive public.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = MediaAssetKind::tryFrom((string) $this->input('kind'));
            $field = MediaAssetField::tryFrom((string) $this->input('field'));
            $visibility = MediaAssetVisibility::tryFrom((string) $this->input('visibility'));

            if ($kind !== null && $field !== null && $field->kind() !== $kind) {
                $validator->errors()->add('field', 'Field không tương thích với kind của file.');
            }

            if ($kind === MediaAssetKind::Archive && $visibility === MediaAssetVisibility::Public) {
                $validator->errors()->add('visibility', 'Archive/package bắt buộc ở private disk.');
            }
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tính max upload HTTP từ các kind trong config
     * =====================================================================
     *
     * OUTPUT:
     * - int: giới hạn KB lớn nhất; validator bytes thực tế sẽ kiểm tra chặt hơn
     */
    private function maxUploadSizeKb(): int
    {
        $settings = app(ProjectSettingsService::class);

        return (int) collect(array_keys(config('media-assets.kinds', [])))
            ->map(fn (string $kind) => $settings->mediaPolicy($kind)['max_size_kb'])->max();
    }
}
