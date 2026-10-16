<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái tạo caption/ảnh của Social Post.
 * =====================================================================
 * Generation status độc lập với review và publication để giữ draft khi một
 * nhánh AI thành công còn nhánh khác thất bại.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái worker/AI task từ database.
 * - OUTPUT: enum SocialPostGenerationStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum SocialPostGenerationStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Partial = 'partial';
    case Failed = 'failed';

    /**
     * Trả danh sách trạng thái generation được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
