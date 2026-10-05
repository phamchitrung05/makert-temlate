<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Validation\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra tham chiếu ảnh HTML tại request lưu Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - after(): kiểm ID/URL/quyền sau validation cơ bản; action kiểm lại khi ghi.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : content và actor của FormRequest.
 * - OUTPUT: callback xác thực hoặc lỗi 422/403; không ghi media usage.
 * =====================================================================
 */
trait ValidatesContentMedia
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm ảnh inline sau khi kiểu dữ liệu request đã hợp lệ.
     * =====================================================================
     * INPUT: Không có; callback nhận Validator và đọc content/User của request.
     * OUTPUT: Mảng callback; bỏ qua khi có lỗi cơ bản hoặc không cập nhật content.
     * SIDE EFFECT: Đọc asset và policy; không gọi provider hoặc ghi database.
     * =====================================================================
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! is_string($this->input('content'))) {
                return;
            }
            app(ContentMediaReferenceService::class)->validate($this->input('content'), $this->user());
        }];
    }
}
