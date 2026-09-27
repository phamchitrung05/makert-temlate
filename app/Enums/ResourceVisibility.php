<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa mức hiển thị của resource với người dùng
 * =====================================================================
 *
 * Enum lưu trong cột `resources.visibility`. `public` cho phép guest xem,
 * `members` yêu cầu tài khoản customer đã đăng nhập, `private` chỉ hiện
 * trong admin. Scope PubliclyVisibleResourceCriteria chỉ lấy `public`.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để đổ vào select của admin form
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database
 * - OUTPUT: ResourceVisibility|null khi tryFrom thất bại
 * =====================================================================
 */
enum ResourceVisibility: string
{
    case Public = 'public';
    case Members = 'members';
    case Private = 'private';

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
            'public' => 'Công khai',
            'members' => 'Thành viên',
            'private' => 'Riêng tư',
        ];
    }
}
