<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái tổng hợp publication Social Post.
 * =====================================================================
 * Status tổng hợp chỉ phản ánh các target đang bật; trạng thái chi tiết từng
 * Page nằm ở SocialPostTarget và SocialPublication.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái publication từ scheduler/publisher.
 * - OUTPUT: enum SocialPostPublicationStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum SocialPostPublicationStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    /**
     * Trả danh sách trạng thái publication tổng hợp được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
