<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request cập nhật danh mục
 * =====================================================================
 *
 * Đối xứng với CategoryCreateRequest nhưng mọi trường là `sometimes` vì PUT
 * chỉ gửi phần thay đổi. Rule unique của `name` loại trừ chính danh mục đang
 * sửa để không báo trùng với bản thân nó.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - rules(): rule cập nhật danh mục
 * - Các rule parent/menu/media và kiểm tra cây kế thừa từ CategoryRequest.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload cập nhật danh mục, kèm route param category
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class CategoryUpdateRequest extends CategoryRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi cập nhật danh mục
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request và route param `category`
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule cập nhật danh mục, trong đó
     *   `name` loại trừ chính danh mục đang sửa
     */
    public function rules(): array
    {
        return array_merge($this->sharedRules(false), [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->ignore($this->route('category')?->getKey()),
            ],
        ]);
    }

}
