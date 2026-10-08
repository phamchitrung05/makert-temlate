<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate lý do từ chối Post đang chờ review.
 * =====================================================================
 *
 * Request là HTTP boundary cho endpoint reject; quyền `posts.review` hoặc
 * `posts.manage` được kiểm tra ở route middleware trước khi request tới đây.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorize(): cho phép request sau middleware permission.
 * - rules(): bắt buộc reason có độ dài hữu ích để audit.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload reject từ admin.
 * - OUTPUT: reason đã validate hoặc lỗi HTTP 422.
 * =====================================================================
 */
final class PostRejectRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi tiếp tới controller
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true; permission đã được route middleware kiểm tra.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule lý do từ chối
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, array<int, string>>: reason bắt buộc, giới hạn 1000 ký tự.
     *
     * SIDE EFFECT:
     * - Không đọc/ghi database và không mở transaction.
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
