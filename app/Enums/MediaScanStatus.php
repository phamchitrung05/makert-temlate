<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái scan bảo mật của media
 * =====================================================================
 *
 * Giá trị được lưu trong custom properties của Spatie Media. `rejected` là
 * file vi phạm policy; `error` là lỗi kỹ thuật có thể retry, không được coi là
 * file sạch.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ trạng thái hợp lệ
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị trạng thái từ job scan
 * - OUTPUT: MediaScanStatus enum hoặc value string khi serialize
 * =====================================================================
 */
enum MediaScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Rejected = 'rejected';
    case Error = 'error';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về toàn bộ trạng thái scan hợp lệ
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: value dùng cho custom property và test
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
