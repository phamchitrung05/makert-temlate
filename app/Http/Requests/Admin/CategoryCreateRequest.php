<?php

namespace App\Http\Requests\Admin;

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
 * - Các rule parent/menu/media và kiểm tra cây kế thừa từ CategoryRequest.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload tạo danh mục từ admin
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class CategoryCreateRequest extends CategoryRequest
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

}
