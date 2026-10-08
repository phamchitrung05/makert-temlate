<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo trạng thái vòng đời của Post trong admin.
 * =====================================================================
 *
 * Trạng thái review được tách khỏi thao tác CRUD để backend có thể kiểm
 * soát quyền submit, publish, reject và archive độc lập.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 * - isPublic(): cho biết Post có được hiển thị công khai hay chưa.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị status từ database hoặc request đã validate.
 * - OUTPUT: enum PostStatus và các helper dùng chung cho domain/API.
 * =====================================================================
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả các giá trị status hợp lệ cho validation và filter
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: giá trị scalar theo thứ tự khai báo enum.
     *
     * SIDE EFFECT:
     * - Không đọc/ghi database và không mở transaction.
     * =====================================================================
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định Post có ở trạng thái công khai hay không
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true chỉ với trạng thái Published.
     *
     * SIDE EFFECT:
     * - Không thay đổi model hoặc database.
     * =====================================================================
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
