<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate field được chọn khi áp dụng candidate AI.
 * =====================================================================
 * Request được dùng bởi AiImportController::apply() sau khi route đã kiểm tra
 * permission posts.manage. Request chỉ kiểm tra dữ liệu điều khiển apply;
 * candidate/provider/model vẫn do backend lấy từ database.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): giữ boundary permission ở route/controller.
 * - rules(): giới hạn field, target và optimistic-lock timestamp.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : candidate UUID, Post target tùy chọn và field allowlist.
 * - OUTPUT: dữ liệu đã validate; không cho AI quyết định actor/status/slug.
 * SIDE EFFECT: không ghi database.
 * EXCEPTION/TRANSACTION: validation failure trả envelope chuẩn; không mở transaction.
 * =====================================================================
 */
class AiCandidateApplyRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi qua sau khi route đã authorize
     * =====================================================================
     * INPUT: authenticated admin từ route middleware.
     * OUTPUT: true; permission cụ thể được kiểm tra bởi route posts.manage.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo field được phép apply và optimistic locking
     * =====================================================================
     * INPUT: HTTP payload từ admin.
     * OUTPUT: rule array cho fields/target/expected_updated_at.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: validation exception do FormRequest; không transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'distinct', 'in:title,excerpt,content,seo,taxonomy,thumbnail'],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'expected_updated_at' => ['nullable', 'date'],
            'expected_version' => ['sometimes', 'string', 'size:64'],
        ];
    }
}
