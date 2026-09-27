<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái của một phiên bản resource
 * =====================================================================
 *
 * Enum lưu trong cột `resource_versions.status`. Chỉ bản `ready` mới được
 * phép gắn package và được tải xuống; `draft` dùng khi admin đang soạn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để đổ vào select của admin form
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database
 * - OUTPUT: ResourceVersionStatus|null khi tryFrom thất bại
 * =====================================================================
 */
enum ResourceVersionStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về toàn bộ giá trị hợp lệ của enum
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: danh sách value dùng cho validation rule `in:...`
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về danh sách giá trị kèm nhãn tiếng Việt
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: value => nhãn để hiển thị trong admin form
     */
    public static function options(): array
    {
        return [
            'draft' => 'Bản nháp',
            'ready' => 'Sẵn sàng',
            'archived' => 'Lưu trữ',
        ];
    }
}
