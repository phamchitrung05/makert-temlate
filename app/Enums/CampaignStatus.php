<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái vòng đời campaign Marketing.
 * =====================================================================
 * Campaign gom product và thông điệp để lập kế hoạch tạo Social Post.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị status từ database hoặc request.
 * - OUTPUT: enum CampaignStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    /**
     * Trả danh sách trạng thái campaign được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
