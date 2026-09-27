<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request cập nhật tag
 * =====================================================================
 *
 * Đối xứng với TagCreateRequest nhưng mọi trường là `sometimes`. Rule unique
 * của `name` loại trừ chính tag đang sửa.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - rules(): rule cập nhật tag
 * - table(): tên bảng cho rule unique
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload cập nhật tag, kèm route param tag
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class TagUpdateRequest extends TaxonomyRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi cập nhật tag
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request và route param `tag`
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule cập nhật tag
     */
    public function rules(): array
    {
        return array_merge($this->sharedRules(false), [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('tags', 'name')->ignore($this->route('tag')?->getKey()),
            ],
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về tên bảng dùng cho rule unique
     * =====================================================================
     *
     * OUTPUT:
     * - string: tags
     */
    protected function table(): string
    {
        return 'tags';
    }
}
