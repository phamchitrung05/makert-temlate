<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái kết nối tài khoản mạng xã hội.
 * =====================================================================
 * Trạng thái phản ánh sức khỏe token/Page account; disconnect không xóa lịch
 * sử publication đã ghi trước đó.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái kết nối từ OAuth/health check hoặc database.
 * - OUTPUT: enum SocialAccountStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum SocialAccountStatus: string
{
    case Disconnected = 'disconnected';
    case Active = 'active';
    case Expired = 'expired';
    case Error = 'error';
    case Disabled = 'disabled';

    /**
     * Trả danh sách trạng thái account được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
