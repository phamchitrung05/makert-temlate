<?php

namespace App\Validators;

use App\Enums\TechnologyType;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Prettus\Validator\Contracts\ValidatorInterface;
use Prettus\Validator\LaravelValidator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate dữ liệu technology trước khi ghi vào repository
 * =====================================================================
 *
 * Bảng `technologies` có unique index composite trên (name, type) nên rule
 * `unique` phải khớp đúng hai cột đó, ngoài ra type bắt buộc thuộc enum.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nạp bảng rule vào property qua setRules()
 * - buildRules(): dựng rule cho create và update
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : mảng attributes từ request admin technology
 * - OUTPUT: Laravel Validator hoặc ValidatorException
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class TechnologyValidator extends LaravelValidator
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
        $type = implode(',', TechnologyType::values());

        return [
            ValidatorInterface::RULE_CREATE => [
                'name' => ['required', 'string', 'max:255', 'unique:technologies,name,type'],
                'type' => ['required', 'string', 'in:'.$type],
            ],
            ValidatorInterface::RULE_UPDATE => [
                'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:technologies,name,type'],
                'type' => ['sometimes', 'required', 'string', 'in:'.$type],
            ],
        ];
    }
}
