<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa nhóm file được quản lý trong Media Library
 * =====================================================================
 *
 * Enum là nguồn chuẩn cho validation và filter của MediaAsset. `archive` luôn
 * được xử lý như package private; policy disk/visibility chi tiết sẽ được áp
 * dụng ở upload pipeline, không để frontend tự quyết định.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để hiển thị trong admin
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ request hoặc database
 * - OUTPUT: MediaAssetKind tương ứng hoặc null khi tryFrom thất bại
 * =====================================================================
 */
enum MediaAssetKind: string
{
    case Image = 'image';
    case Document = 'document';
    case Archive = 'archive';
    case Video = 'video';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về toàn bộ giá trị kind hợp lệ
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
     * CHỨC NĂNG: Trả về nhãn hiển thị cho từng kind
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: value => nhãn admin
     */
    public static function options(): array
    {
        return [
            'image' => 'Hình ảnh',
            'document' => 'Tài liệu',
            'archive' => 'Gói nén',
            'video' => 'Video',
        ];
    }
}
