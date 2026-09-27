<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request tạo danh mục
 * =====================================================================
 *
 * Cổng kiểm tra cho POST /api/admin/categories. Rule name là bắt buộc khi
 * tạo mới và phải unique trong bảng `categories`; parent_id phải tồn tại
 * để cây danh mục không trỏ tới bản ghi không có thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - rules(): rule tạo danh mục
 * - typeSpecificRules(): bổ sung parent_id và sort_order
 * - table(): tên bảng cho rule unique
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload tạo danh mục từ admin
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class CategoryCreateRequest extends TaxonomyRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi tạo danh mục
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule tạo danh mục
     */
    public function rules(): array
    {
        return $this->sharedRules(true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bổ sung rule riêng cho cây danh mục
     * =====================================================================
     *
     * INPUT:
     * - $isCreate: không dùng, giữ đúng chữ ký của lớp cha
     *
     * OUTPUT:
     * - array<string, array<int, string>>: parent_id và sort_order
     */
    protected function typeSpecificRules(bool $isCreate): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về tên bảng dùng cho rule unique
     * =====================================================================
     *
     * OUTPUT:
     * - string: categories
     */
    protected function table(): string
    {
        return 'categories';
    }
}
