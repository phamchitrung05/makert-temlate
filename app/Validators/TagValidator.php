<?php

namespace App\Validators;

use App\Enums\TaxonomyStatus;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Prettus\Validator\Contracts\ValidatorInterface;
use Prettus\Validator\LaravelValidator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate dữ liệu tag trước khi ghi vào repository
 * =====================================================================
 *
 * Validator chạy trong `TagRepositoryEloquent::create()` và `::update()`.
 * Bảng `tags` không có unique index trên `name`, nên rule `unique` do validator
 * đảm bảo ở tầng ứng dụng thay vì tầng database.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nạp bảng rule vào property qua setRules()
 * - buildRules(): dựng rule cho create và update
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : mảng attributes từ request admin tag
 * - OUTPUT: Laravel Validator hoặc ValidatorException
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class TagValidator extends LaravelValidator
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
            ValidatorInterface::RULE_CREATE => [
                'name' => ['required', 'string', 'max:255', 'unique:tags,name'],
                'description' => ['nullable', 'string'],
                'status' => ['required', 'string', 'in:'.implode(',', TaxonomyStatus::values())],
            ],
            ValidatorInterface::RULE_UPDATE => [
                'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:tags,name'],
                'description' => ['nullable', 'string'],
                'status' => ['sometimes', 'string', 'in:'.implode(',', TaxonomyStatus::values())],
            ],
        ];
    }
}
