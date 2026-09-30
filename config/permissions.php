<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Danh mục permission và role permission của admin.
 * =====================================================================
 *
 * Đây là nguồn cấu hình duy nhất cho danh mục permission nghiệp vụ của admin
 * guard. Role không được khai báo trong file này; trang quản trị role sau này
 * sẽ đọc danh mục và tự gán permission cho từng role.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; file chỉ trả về cấu hình permission.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : danh mục permission được khai báo tĩnh.
 * - OUTPUT: danh mục permission có thể cấp cho role.
 * =====================================================================
 */
return [
    // Quy ước action dùng chung:
    // - view: xem danh sách và chi tiết dữ liệu.
    // - create: tạo bản ghi mới.
    // - update: chỉnh sửa bản ghi đã tồn tại.
    // - delete: xóa bản ghi.
    // - publish: chuyển nội dung sang trạng thái công khai/xuất bản.
    // - archive: đưa nội dung vào lưu trữ, không còn hoạt động chính.
    // - manage: toàn quyền nghiệp vụ của nhóm, tùy module có thể bao gồm
    //   view/create/update/delete và các thao tác đặc thù.
    'catalog' => [
        // Resource: xem danh sách/chi tiết, tạo mới, cập nhật, xóa,
        // xuất bản và lưu trữ resource.
        'resources' => [
            'view', 'create', 'update', 'delete', 'publish', 'archive',
        ],

        // Resource version: quản lý toàn bộ vòng đời version của resource.
        'resource_versions' => ['manage'],

        // Post: manage cho phép thao tác quản trị bài viết; create/update
        // được dùng riêng cho tạo mới/cập nhật và cấp quyền preview slug.
        'posts' => ['manage', 'create', 'update'],

        // Taxonomy: quản lý danh mục, tag và technology.
        'taxonomy' => ['manage'],

        // Media: xem, tải lên, gắn vào model, xóa và retry xử lý media lỗi.
        'media' => ['view', 'upload', 'attach', 'delete', 'retry'],

        // Users: xem người dùng và quản trị tài khoản/quyền người dùng.
        'users' => ['view', 'manage'],

        // Analytics: xem báo cáo và số liệu phân tích.
        'analytics' => ['view'],

        // Settings: quản lý cấu hình hệ thống.
        'settings' => ['manage'],
    ],
];
