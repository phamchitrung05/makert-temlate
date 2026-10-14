<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái xử lý package template
 * =====================================================================
 *
 * Enum được lưu tại `resource_version_templates.processing_status`. Trạng
 * thái xử lý template tách khỏi ResourceVersionStatus: một version có thể
 * còn là draft trong khi package đang được xử lý hoặc bị lỗi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách trạng thái hợp lệ
 * - options(): nhãn tiếng Việt cho admin UI
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database hoặc job processing
 * - OUTPUT: enum status hoặc danh sách options; không có side effect
 * - EXCEPTION/TRANSACTION: enum cast sẽ báo lỗi nếu database chứa value ngoài contract
 * =====================================================================
 */
enum ResourceVersionTemplateProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách status dùng cho validation
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: list<string> status hợp lệ.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả nhãn status cho admin UI
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: array<string, string> value => nhãn tiếng Việt.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function options(): array
    {
        return [
            self::Pending->value => 'Đang chờ',
            self::Processing->value => 'Đang xử lý',
            self::Ready->value => 'Đã xử lý',
            self::Failed->value => 'Lỗi xử lý',
        ];
    }
}
