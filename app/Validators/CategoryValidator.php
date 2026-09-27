<?php

namespace App\Validators;

use App\Enums\TaxonomyStatus;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Prettus\Validator\Contracts\ValidatorInterface;
use Prettus\Validator\LaravelValidator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate dữ liệu category trước khi ghi vào repository
 * =====================================================================
 *
 * Validator chạy trong `CategoryRepositoryEloquent::create()` và `::update()`.
 * Rule được nạp vào property `$rules` trong constructor vì
 * `AbstractValidator::getRules()` chỉ đọc property, không gọi method.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nạp bảng rule vào property qua setRules()
 * - buildRules(): dựng rule cho create và update
 * - sharedRules(): các rule dùng chung cho cả hai ngữ cảnh
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : mảng attributes từ request admin category
 * - OUTPUT: Laravel Validator hoặc ValidatorException
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class CategoryValidator extends LaravelValidator
{
    /**
     * @var array<string, array<int, string>>
     */
    protected $rules = [];

    /**
     * =====================================================================
     * CHỨC NĂNG: Nạp bảng rule vào property của validator
     * =====================================================================
     *
     * INPUT:
     * - $validator: factory validation của Laravel
     *
     * SIDE EFFECT:
     * - Gán giá trị vào property `$rules` qua setRules()
     */
    public function __construct(ValidationFactory $validator)
    {
        parent::__construct($validator);

        $this->setRules($this->buildRules());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng bảng rule theo ngữ cảnh create và update
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - array<string, array<string, array<int, string>>>: rule theo RULE_CREATE
     *   và RULE_UPDATE
     */
    private function buildRules(): array
    {
        return [
            ValidatorInterface::RULE_CREATE => array_merge($this->sharedRules(), [
                'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
                'status' => ['required', 'string', 'in:'.implode(',', TaxonomyStatus::values())],
            ]),
            ValidatorInterface::RULE_UPDATE => array_merge($this->sharedRules(), [
                'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:categories,name'],
                'status' => ['sometimes', 'string', 'in:'.implode(',', TaxonomyStatus::values())],
            ]),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gom các rule dùng chung cho cả create và update
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - array<string, array<int, string>>: parent, description và sort_order
     */
    private function sharedRules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
