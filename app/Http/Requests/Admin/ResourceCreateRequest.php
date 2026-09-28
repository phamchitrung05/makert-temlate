<?php

namespace App\Http\Requests\Admin;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request tạo resource
 * =====================================================================
 *
 * Request này là cổng kiểm tra đầu tiên cho POST /api/admin/resources. Nó
 * chặn giá trị enum sai trước khi dữ liệu chạm vào model, vì
 * `BaseRepository::create()` của l5-repository gọi `forceFill()` để đọc dữ
 * liệu đã cast trước khi chạy ResourceValidator — nếu không chặn ở đây thì
 * lỗi người dùng sẽ thành HTTP 500 thay vì 422.
 *
 * Rule nghiệp vụ còn lại (unique của `code`, exists của các `*_ids`) vẫn do
 * `ResourceValidator` đảm nhiệm trong repository, để mọi đường ghi dữ liệu
 * ngoài HTTP cũng được bảo vệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request đi tới controller
 * - rules(): khai báo rule bắt buộc khi tạo mới
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload tạo resource từ admin
 * - OUTPUT: dữ liệu đã qua kiểm tra, hoặc 422 với errors theo field
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; chỉ validate
 * =====================================================================
 */
class ResourceCreateRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi tiếp tới controller
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; quyền đã được middleware `permission:resources.create,admin`
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule khi tạo resource
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc giá trị từ request
     *
     * OUTPUT:
     * - array<string, array<int, string>>: type, title, status và visibility
     *   là bắt buộc; các trường còn lại là tùy chọn
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', ResourceType::values())],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.implode(',', ResourceStatus::values())],
            'visibility' => ['required', 'string', 'in:'.implode(',', ResourceVisibility::values())],
            'media' => ['sometimes', 'array'],
            'media.cover_id' => ['nullable', 'integer', 'min:1'],
            'media.preview_ids' => ['sometimes', 'array'],
            'media.preview_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về dữ liệu đã loại bỏ các mảng id taxonomy
     * =====================================================================
     *
     * Các mảng `category_ids`, `tag_ids` và `technology_ids` do repository
     * validator xử lý nên không nằm trong `validated()`. Action đọc trực tiếp
     * từ `$request->all()` cho phần taxonomy.
     *
     * OUTPUT:
     * - array: dữ liệu resource đã validate
     */
    public function resourceAttributes(): array
    {
        return $this->safe()->except([
            'category_ids',
            'tag_ids',
            'technology_ids',
            'media',
        ]);
    }
}
