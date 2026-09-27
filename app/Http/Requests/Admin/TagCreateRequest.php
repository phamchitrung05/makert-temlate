<?php

namespace App\Http\Requests\Admin;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request tạo tag
 * =====================================================================
 *
 * Cổng kiểm tra cho POST /api/admin/tags. Tag là taxonomy phẳng nên không
 * có rule riêng ngoài nhóm dùng chung của TaxonomyRequest.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - rules(): rule tạo tag
 * - table(): tên bảng cho rule unique
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload tạo tag từ admin
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class TagCreateRequest extends TaxonomyRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi tạo tag
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule tạo tag
     */
    public function rules(): array
    {
        return $this->sharedRules(true);
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
