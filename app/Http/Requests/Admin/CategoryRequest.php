<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Rule chung cho cấu trúc cây, menu và media Category.
 * CÁC HÀM/METHOD TRONG FILE:
 * - typeSpecificRules(): validate parent, thứ tự, menu và thumbnail.
 * - after(): ngăn chọn chính Category hoặc hậu duệ làm cha.
 * - table(): bảng dùng cho rule unique của taxonomy.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload Category -> dữ liệu hợp lệ hoặc lỗi 422.
 * =====================================================================
 */
abstract class CategoryRequest extends TaxonomyRequest
{
    /** Input: ngữ cảnh tạo/sửa. Output: rule các field riêng của Category. */
    protected function typeSpecificRules(bool $isCreate): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'show_on_menu' => ['sometimes', 'boolean'],
            'media' => ['sometimes', 'array'],
            'media.thumbnail_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Kiểm tra chuỗi cha sau validation cơ bản để cây không có vòng lặp.
     * Input: route Category đang sửa và parent_id mới.
     * Output: callback bổ sung lỗi parent_id; chỉ đọc database.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('parent_id') || ! $this->filled('parent_id')) {
                return;
            }

            $category = $this->route('category');
            $categoryId = $category instanceof Category ? $category->getKey() : null;
            $parentId = (int) $this->input('parent_id');
            $visited = [];

            while ($parentId) {
                if ($parentId === $categoryId || isset($visited[$parentId])) {
                    $validator->errors()->add('parent_id', 'Không thể chọn chính danh mục hoặc danh mục con làm danh mục cha.');

                    return;
                }

                $visited[$parentId] = true;
                $parentId = (int) Category::query()->whereKey($parentId)->value('parent_id');
            }
        }];
    }

    /** Input: không có. Output: tên bảng categories cho rule unique. */
    protected function table(): string
    {
        return 'categories';
    }
}
