<?php

namespace App\Http\Requests\Admin;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request cập nhật resource
 * =====================================================================
 *
 * Đối xứng với ResourceCreateRequest nhưng mọi trường đều là `sometimes` vì
 * PUT chỉ gửi phần thay đổi. Rule `unique` của `code` loại trừ chính bản ghi
 * đang sửa bằng `Rule::unique()->ignore()`, tránh báo trùng với chính nó.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tiếp tới controller
 * - rules(): khai báo rule cho ngữ cảnh cập nhật
 * - resourceAttributes(): trả dữ liệu đã loại bỏ mảng id taxonomy
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload cập nhật resource từ admin, kèm route param resource
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class ResourceUpdateRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi tiếp tới controller
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; quyền đã được middleware `permission:resources.update,admin`
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi cập nhật resource
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request và route param `resource`
     *
     * OUTPUT:
     * - array<string, array<int, string>>: mọi trường đều `sometimes`
     */
    public function rules(): array
    {
        $resourceId = $this->route('resource')?->getKey();

        return [
            'type' => ['sometimes', 'string', 'in:'.implode(',', ResourceType::values())],
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', ResourceStatus::values())],
            'visibility' => ['sometimes', 'string', 'in:'.implode(',', ResourceVisibility::values())],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('resources', 'code')->ignore($resourceId),
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về dữ liệu đã loại bỏ các mảng id taxonomy
     * =====================================================================
     *
     * Các mảng `category_ids`, `tag_ids` và `technology_ids` do repository
     * validator xử lý nên không nằm trong `validated()`. Action đọc trực tiếp
     * từ `$request->all()` cho phần taxonomy.
     *
     * OUTPUT:
     * - array: dữ liệu resource đã validate
     */
    public function resourceAttributes(): array
    {
        return $this->safe()->except([
            'category_ids',
            'tag_ids',
            'technology_ids',
        ]);
    }
}
