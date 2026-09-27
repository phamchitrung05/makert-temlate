<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa phạm vi truy cập của MediaAsset
 * =====================================================================
 *
 * `public` chỉ áp dụng cho asset được phép tạo URL public. `private` yêu cầu
 * backend policy trước khi cấp temporary URL hoặc stream file.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để hiển thị trong admin
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ request hoặc database
 * - OUTPUT: MediaAssetVisibility tương ứng hoặc null khi tryFrom thất bại
 * =====================================================================
 */
enum MediaAssetVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về toàn bộ giá trị visibility hợp lệ
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: danh sách dùng cho validation rule `in:...`
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về nhãn hiển thị cho từng visibility
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: value => nhãn admin
     */
    public static function options(): array
    {
        return [
            'public' => 'Công khai',
            'private' => 'Riêng tư',
        ];
    }
}
