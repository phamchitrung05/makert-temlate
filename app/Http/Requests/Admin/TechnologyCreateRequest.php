<?php

namespace App\Http\Requests\Admin;

use App\Enums\TechnologyType;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request tạo công nghệ
 * =====================================================================
 *
 * Cổng kiểm tra cho POST /api/admin/technologies. Bảng technologies có
 * unique index composite trên (name, type) nên rule unique phải khớp đúng
 * hai cột đó, khác với category và tag chỉ unique theo name.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - rules(): rule tạo công nghệ
 * - typeSpecificRules(): bổ sung type thuộc enum
 * - table(): tên bảng cho rule unique
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload tạo công nghệ từ admin
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class TechnologyCreateRequest extends TaxonomyRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi tạo công nghệ
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule tạo công nghệ
     */
    public function rules(): array
    {
        // Bảng technologies không có cột status, nên chỉ dùng rule chung cho
        // name và các rule riêng của từng loại taxonomy.
        return array_merge([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('technologies', 'name', 'type'),
            ],
        ], $this->typeSpecificRules(true));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bổ sung rule riêng cho nhóm công nghệ
     * =====================================================================
     *
     * INPUT:
     * - $isCreate: true khi request là POST tạo mới
     *
     * OUTPUT:
     * - array<string, array<int, string>>: type thuộc enum TechnologyType
     */
    protected function typeSpecificRules(bool $isCreate): array
    {
        return [
            'type' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'in:'.implode(',', TechnologyType::values()),
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về tên bảng dùng cho rule unique
     * =====================================================================
     *
     * OUTPUT:
     * - string: technologies
     */
    protected function table(): string
    {
        return 'technologies';
    }
}
