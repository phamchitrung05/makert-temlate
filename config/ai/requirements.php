<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Các yêu cầu bắt buộc khi tạo và kiểm tra bài viết AI.
 * =====================================================================
 * Cấu hình được snapshot vào pipeline_snapshot khi xếp hàng create/regenerate.
 * Chỉnh file này sẽ áp dụng cho tác vụ mới; retry của tác vụ cũ giữ nguyên
 * snapshot để các bước trong cùng một lần chạy không đổi luật giữa chừng.
 *
 * CÁC HÀM/METHOD TRONG FILE: Không có; chỉ trả mảng cấu hình.
 * INPUT/OUTPUT: cấu hình server -> yêu cầu prompt và quality gate.
 * SIDE EFFECT: không gọi AI, không đọc hoặc ghi database.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
return [
    'links' => [
        // Link có nội dung văn bản trong thân bài phải giữ đúng href nguồn.
        'require_body_links' => true,

        // Anchor ảnh/CDN không phải link tham khảo. Bật true nếu nghiệp vụ cần giữ.
        'require_image_links' => false,
        'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tif', 'tiff'],

        // Mục lục/nav và taxonomy cùng site có thể bỏ khi viết lại.
        'require_navigation_links' => false,
        'require_taxonomy_links' => false,
        'taxonomy_paths' => ['/blog'],
        'taxonomy_prefixes' => ['/blog/category', '/blog/tag'],
    ],
    'content' => [
        'preserve_source_code' => true,
        'preserve_important_numbers' => true,
    ],
    'evidence' => [
        'require_important_fact_coverage' => true,
    ],
    'editor' => [
        'blocking_severities' => ['blocking'],
    ],
];
