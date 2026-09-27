<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái conversion ảnh của media
 * =====================================================================
 *
 * Giá trị được lưu trong custom properties của Spatie Media. Conversion chỉ
 * được đánh dấu `ready` sau khi toàn bộ conversion cần thiết chạy thành công.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ trạng thái hợp lệ
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị trạng thái từ conversion job
 * - OUTPUT: MediaConversionStatus enum hoặc value string khi serialize
 * =====================================================================
 */
enum MediaConversionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về toàn bộ trạng thái conversion hợp lệ
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
