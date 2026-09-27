<?php

namespace App\Validators;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use Prettus\Validator\Contracts\ValidatorInterface;
use Prettus\Validator\LaravelValidator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate dữ liệu resource trước khi ghi vào repository
 * =====================================================================
 *
 * Validator chạy tự động trong `ResourceRepositoryEloquent::create()` và
 * `::update()`. Khi rule fail, repository ném `ValidatorException`; controller
 * bắt qua `BaseResponse::fromException()` và trả 422.
 *
 * Rules được nạp vào property `$rules` ngay trong constructor thay vì khai
 * báo ở property, vì giá trị `in:...` phải nối động từ enum nên không thể
 * nằm trong constant expression. `AbstractValidator::getRules()` chỉ đọc
 * property này, không gọi method `rules()`.
 *
 * RULE_UPDATE nhận id hiện tại qua `setId()` nên rule `unique` tự bỏ qua chính
 * bản ghi đang sửa, không cần viết thêm rule thủ công.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nạp bảng rule vào property qua setRules()
 * - rules(): bảng rule tách theo ngữ cảnh create và update
 * - sharedRules(): các rule dùng chung cho cả hai ngữ cảnh
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : mảng attributes từ request admin resource
 * - OUTPUT: Laravel Validator hoặc ValidatorException
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class ResourceValidator extends LaravelValidator
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
     * - Không có; rules dựng động từ enum trong rules()
     *
     * SIDE EFFECT:
     * - Gán giá trị vào property `$rules` qua setRules()
     */
    public function __construct(\Illuminate\Contracts\Validation\Factory $validator)
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
                'type' => ['required', 'string', 'in:'.implode(',', ResourceType::values())],
                'title' => ['required', 'string', 'max:255'],
                'status' => ['required', 'string', 'in:'.implode(',', ResourceStatus::values())],
                'visibility' => ['required', 'string', 'in:'.implode(',', ResourceVisibility::values())],
            ]),
            ValidatorInterface::RULE_UPDATE => array_merge($this->sharedRules(), [
                'type' => ['sometimes', 'string', 'in:'.implode(',', ResourceType::values())],
                'title' => ['sometimes', 'required', 'string', 'max:255'],
                'status' => ['sometimes', 'string', 'in:'.implode(',', ResourceStatus::values())],
                'visibility' => ['sometimes', 'string', 'in:'.implode(',', ResourceVisibility::values())],
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
     * - array<string, array<int, string>>: rule không phụ thuộc ngữ cảnh
     */
    private function sharedRules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:255', 'unique:resources,code'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_featured' => ['sometimes', 'boolean'],
            'demo_url' => ['nullable', 'url', 'max:255'],
            'documentation_url' => ['nullable', 'url', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'url', 'max:255'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'technology_ids' => ['sometimes', 'array'],
            'technology_ids.*' => ['integer', 'exists:technologies,id'],
        ];
    }
}
