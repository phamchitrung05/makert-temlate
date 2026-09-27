<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa nhóm công nghệ dùng để gắn cho resource
 * =====================================================================
 *
 * Enum lưu trong cột `technologies.type`. Giá trị này quyết định cách hiển
 * thị badge công nghệ và giúp admin lọc resource theo nhóm.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để đổ vào select của admin form
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database
 * - OUTPUT: TechnologyType|null khi tryFrom thất bại
 * =====================================================================
 */
enum TechnologyType: string
{
    case Framework = 'framework';
    case Language = 'language';
    case Css = 'css';
    case BuildTool = 'build_tool';
    case Database = 'database';
    case Runtime = 'runtime';
    case PackageManager = 'package_manager';

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
            'framework' => 'Framework',
            'language' => 'Ngôn ngữ',
            'css' => 'CSS',
            'build_tool' => 'Build tool',
            'database' => 'Cơ sở dữ liệu',
            'runtime' => 'Runtime',
            'package_manager' => 'Package manager',
        ];
    }
}
