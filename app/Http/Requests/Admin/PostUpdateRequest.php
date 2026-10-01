<?php

namespace App\Http\Requests\Admin;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate cập nhật từng phần Post, media và SEO.
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules(); seoRules() từ trait.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request admin -> dữ liệu hợp lệ hoặc lỗi 422.
 * =====================================================================
 */
class PostUpdateRequest extends FormRequest
{
    use \App\Http\Requests\Admin\Concerns\ValidatesAiProvenance;
    use \App\Http\Requests\Admin\Concerns\ValidatesPostSeo;

    /**
     * =====================================================================
     * CHỨC NĂNG: Ủy quyền validation sau middleware posts.manage
     * =====================================================================
     * INPUT: Request đã qua xác thực/permission ở route.
     * OUTPUT: true để Laravel thực hiện validation.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction; authorization thực tế do middleware route bảo vệ.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rules cập nhật từng phần Post và AI lineage
     * =====================================================================
     * INPUT: Không có đối số; dùng rules từ các trait dùng chung.
     * OUTPUT: Mảng rules partial update; title nếu gửi phải không rỗng.
     * SIDE EFFECT: Chỉ tạo rules; Laravel có thể query DB khi kiểm tra exists.
     * EXCEPTION/TRANSACTION: Validation lỗi trả 422; không mở transaction.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            ...$this->seoRules(),
            ...$this->aiProvenanceRules(),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(PostStatus::values())],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
            'media' => ['sometimes', 'array'],
            'media.thumbnail_id' => ['nullable', 'integer', 'min:1'],
            'media.content_image_ids' => ['sometimes', 'array'],
            'media.content_image_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
