<?php

namespace App\Http\Requests\Admin;

use App\Enums\TaxonomyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Base chung cho request taxonomy
 * =====================================================================
 *
 * Category, Tag và Technology có cùng một nhóm rule: name bắt buộc, unique
 * theo bảng riêng, description tùy chọn và status thuộc enum. Base class
 * gom phần chung, lớp con chỉ khai báo tên bảng và rule bắt buộc.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tiếp tới controller
 * - sharedRules(): các rule dùng chung cho create và update
 * - table(): tên bảng dùng cho rule unique
 * - createRules(): rule bắt buộc khi tạo mới
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload taxonomy từ admin
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
abstract class TaxonomyRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi tiếp tới controller
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; quyền đã được middleware `permission:taxonomy.manage,admin`
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gom các rule dùng chung cho create và update
     * =====================================================================
     *
     * INPUT:
     * - $isCreate: true khi request là POST tạo mới
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule name, description, status
     *   và các trường riêng của từng loại taxonomy
     */
    protected function sharedRules(bool $isCreate): array
    {
        return array_merge([
            'name' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique($this->table(), 'name'),
            ],
            'description' => ['nullable', 'string'],
            'status' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'in:'.implode(',', TaxonomyStatus::values()),
            ],
        ], $this->typeSpecificRules($isCreate));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule riêng của từng loại taxonomy
     * =====================================================================
     *
     * INPUT:
     * - $isCreate: true khi request là POST tạo mới
     *
     * OUTPUT:
     * - array<string, array<int, string>>: rule bổ sung, mặc định rỗng
     */
    protected function typeSpecificRules(bool $isCreate): array
    {
        return [];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về tên bảng dùng cho rule unique
     * =====================================================================
     *
     * OUTPUT:
     * - string: categories, tags hoặc technologies
     */
    abstract protected function table(): string;
}
