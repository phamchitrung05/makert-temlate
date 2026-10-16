<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái vòng đời product Marketing.
 * =====================================================================
 * Product là nguồn context cho AI Social Post; trạng thái không phụ thuộc Blog
 * Post và không tự quyết định trạng thái của Social Post đã tạo.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị status từ database hoặc request.
 * - OUTPUT: enum ProductStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * Trả danh sách trạng thái product được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
