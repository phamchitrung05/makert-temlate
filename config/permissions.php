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

        // Roles: quyền riêng cho tạo, chỉnh sửa và xóa vai trò quản trị.
        // `users.manage` vẫn là quyền quản trị access tổng quát để tương thích
        // với các role hiện tại; các action này cho phép cấp quyền theo phạm vi hẹp.
        'roles' => ['view', 'create', 'update', 'delete'],

        // Analytics: xem báo cáo và số liệu phân tích.
        'analytics' => ['view'],

        // Settings: quản lý cấu hình hệ thống.
        'settings' => ['view', 'manage'],

        // AI Settings: quản lý provider, model catalog và thông số AI.
        'ai_settings' => ['manage'],

        // Category, Tag và Technology là ba model taxonomy có CRUD riêng.
        // `taxonomy.manage` vẫn giữ để tương thích với route/API hiện tại.
        'categories' => ['view', 'create', 'update', 'delete'],
        'tags' => ['view', 'create', 'update', 'delete'],
        'technologies' => ['view', 'create', 'update', 'delete'],

        // Customer và identity thuộc mô hình tài khoản; quyền được khai báo
        // sẵn cho các boundary quản trị customer sẽ triển khai tiếp theo.
        'customers' => ['view', 'create', 'update', 'delete'],
        'customer_identities' => ['view', 'create', 'update', 'delete'],

        // Media model và bảng usage có quyền CRUD riêng bên cạnh nhóm `media`
        // đang được policy hiện tại sử dụng.
        'media_assets' => ['view', 'create', 'update', 'delete'],
        'media_asset_usages' => ['view', 'create', 'update', 'delete'],

        // Model phụ trợ cho SEO dùng chung trong Resource, Post và taxonomy.
        'seo_metadata' => ['view', 'create', 'update'],

        // Vòng đời AI import và các bước xử lý bất đồng bộ.
        'ai_imports' => [
            'view', 'create', 'update', 'delete', 'cancel', 'retry', 'regenerate', 'apply',
        ],
        'ai_import_steps' => ['view', 'create', 'update', 'delete'],

        // Catalog provider/model AI và các thao tác kiểm tra, đồng bộ.
        'ai_providers' => [
            'view', 'create', 'update', 'delete', 'disable', 'test', 'sync',
        ],
        'ai_models' => ['view', 'create', 'update', 'delete', 'test'],

        // Profile văn phong, phân tích và archive kết quả AI.
        'ai_writing_profiles' => ['view', 'create', 'update', 'delete'],
        'ai_writing_profile_analyses' => ['view', 'create', 'update', 'delete'],
        'ai_article_archives' => ['view', 'create', 'update', 'delete', 'restore'],
        'ai_provenance' => ['view', 'create', 'delete'],
    ],
];
