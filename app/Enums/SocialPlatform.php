<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa nền tảng Social Post được hỗ trợ.
 * =====================================================================
 * V1 chỉ bật Facebook Page; enum giữ boundary để target/publication không lưu
 * tên nền tảng tùy ý từ frontend.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : platform string từ request hoặc database.
 * - OUTPUT: enum SocialPlatform và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum SocialPlatform: string
{
    case Facebook = 'facebook';

    /**
     * Trả danh sách platform được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
