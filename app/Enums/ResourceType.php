<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa các loại tài nguyên số được phát hành
 * =====================================================================
 *
 * Enum lưu trong cột `resources.type` dưới dạng string. Danh sách này là
 * nguồn chuẩn duy nhất cho type filter ở admin API và public catalog.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để đổ vào select của admin form
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database hoặc từ FormRequest
 * - OUTPUT: ResourceType|null khi tryFrom thất bại
 * =====================================================================
 */
enum ResourceType: string
{
    case Template = 'template';
    case UiKit = 'ui_kit';
    case Css = 'css';
    case Component = 'component';
    case Theme = 'theme';
    case Snippet = 'snippet';
    case Plugin = 'plugin';
    case IconPack = 'icon_pack';
    case Illustration = 'illustration';
    case Ebook = 'ebook';
    case Course = 'course';
    case Other = 'other';

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
        $labels = [
            'template' => 'Template',
            'ui_kit' => 'UI kit',
            'css' => 'CSS',
            'component' => 'Component',
            'theme' => 'Theme',
            'snippet' => 'Snippet',
            'plugin' => 'Plugin',
            'icon_pack' => 'Bộ icon',
            'illustration' => 'Minh hoạ',
            'ebook' => 'Ebook',
            'course' => 'Khoá học',
            'other' => 'Khác',
        ];

        return array_combine(self::values(), $labels);
    }
}
