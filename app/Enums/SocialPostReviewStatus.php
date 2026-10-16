<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa trạng thái duyệt nội dung Social Post.
 * =====================================================================
 * Chỉ bài approved mới đủ điều kiện đưa vào publication; chỉnh caption/asset
 * sau approved phải quay lại draft ở service lifecycle phase sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): trả danh sách giá trị dùng cho validation/filter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái review từ database hoặc action nghiệp vụ.
 * - OUTPUT: enum SocialPostReviewStatus và danh sách giá trị hợp lệ.
 * =====================================================================
 */
enum SocialPostReviewStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case RevisionRequested = 'revision_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Trả danh sách trạng thái review được phép lưu.
     *
     * Input: Không có.
     * Output: list<string> dùng cho validation và filter; không ghi database.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
