<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa vòng đời trạng thái của một resource
 * =====================================================================
 *
 * Enum lưu trong cột `resources.status` dưới dạng string. Vòng đời hợp lệ
 * là draft → pending_review → published → archived, kèm nhánh rejected và
 * suspended cho moderation. Action PublishResourceAction chỉ chuyển trạng
 * thái sang `published` khi resource đang ở trạng thái hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách toàn bộ giá trị hợp lệ
 * - options(): mảng giá trị => nhãn để đổ vào select của admin form
 * - isPublished(): kiểm tra resource có đang public hay không
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : giá trị string từ database
 * - OUTPUT: ResourceStatus|null khi tryFrom thất bại
 * =====================================================================
 */
enum ResourceStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
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
        $labels = [
            'draft' => 'Bản nháp',
            'pending_review' => 'Chờ duyệt',
            'published' => 'Đã xuất bản',
            'rejected' => 'Bị từ chối',
            'suspended' => 'Tạm dừng',
            'archived' => 'Lưu trữ',
        ];

        return array_combine(self::values(), $labels);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra resource có đang ở trạng thái published
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true chỉ khi trạng thái hiện tại là published
     */
    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
