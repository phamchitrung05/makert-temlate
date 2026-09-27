<?php

namespace App\Http\Requests\Admin;

use App\Enums\TechnologyType;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request cập nhật công nghệ
 * =====================================================================
 *
 * Đối xứng với TechnologyCreateRequest nhưng mọi trường là `sometimes`. Rule
 * unique composite trên (name, type) loại trừ chính bản ghi đang sửa.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - rules(): rule cập nhật công nghệ
 * - typeSpecificRules(): bổ sung type thuộc enum
 * - table(): tên bảng cho rule unique
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload cập nhật công nghệ, kèm route param technology
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class TechnologyUpdateRequest extends TaxonomyRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi cập nhật công nghệ
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request và route param `technology`
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule cập nhật công nghệ
     */
    public function rules(): array
    {
        // Bảng technologies không có cột status, nên chỉ dùng rule chung cho
        // name và các rule riêng của từng loại taxonomy.
        return array_merge([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('technologies', 'name', 'type')
                    ->ignore($this->route('technology')?->getKey()),
            ],
        ], $this->typeSpecificRules(false));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bổ sung rule riêng cho nhóm công nghệ
     * =====================================================================
     *
     * INPUT:
     * - $isCreate: không dùng, giữ đúng chữ ký của lớp cha
     *
     * OUTPUT:
     * - array<string, array<int, string>>: type thuộc enum TechnologyType
     */
    protected function typeSpecificRules(bool $isCreate): array
    {
        return [
            'type' => ['sometimes', 'string', 'in:'.implode(',', TechnologyType::values())],
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
