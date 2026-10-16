<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái một publication theo target.
 * =====================================================================
 * Enum dùng chung cho target và lần gọi publisher để retry từng Page mà không
 * làm mất kết quả của target khác.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái queue/publisher từ database.
 * - OUTPUT: enum SocialPublicationStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum SocialPublicationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Retryable = 'retryable';

    /**
     * Trả danh sách trạng thái publication theo target được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
