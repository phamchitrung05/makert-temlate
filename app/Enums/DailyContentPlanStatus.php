<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái slot tạo Social Post hằng ngày.
 * =====================================================================
 * Daily plan giữ idempotency cho scheduler; nó không tự publish bài.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái scheduler/job từ database.
 * - OUTPUT: enum DailyContentPlanStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum DailyContentPlanStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    /**
     * Trả danh sách trạng thái daily plan được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
