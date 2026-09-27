<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái kích hoạt của category và tag
 * =====================================================================
 *
 * Enum lưu trong cột `categories.status` và `tags.status`. Taxonomy ở trạng
 * thái inactive vẫn còn trong database để giữ quan hệ cũ nhưng bị loại khỏi
 * filter và select ở admin.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để đổ vào select của admin form
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database
 * - OUTPUT: TaxonomyStatus|null khi tryFrom thất bại
 * =====================================================================
 */
enum TaxonomyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

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
            'active' => 'Đang hoạt động',
            'inactive' => 'Ngừng hoạt động',
        ];
    }
}
