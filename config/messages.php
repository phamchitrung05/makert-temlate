<?php

/**
 * Centralized client-facing messages.
 *
 * Chỉ chứa thông điệp do ứng dụng tự định nghĩa. Validation message của
 * Laravel và framework HTTP status vẫn được xử lý bởi framework.
 */
return [
    'common' => [
        'unauthenticated' => 'Bạn cần đăng nhập để tiếp tục.',
        'forbidden' => 'Bạn không có quyền thực hiện thao tác này.',
        'not_found' => 'Không tìm thấy tài nguyên.',
        'too_many_requests' => 'Bạn đã gửi quá nhiều yêu cầu.',
        'validation' => 'Dữ liệu gửi lên không hợp lệ.',
        'bad_request' => 'Yêu cầu không hợp lệ.',
        'server_error' => 'Đã xảy ra lỗi phía máy chủ.',
    ],
    'auth' => [
        'admin' => [
            'login' => [
                'success' => 'Đăng nhập thành công.',
                'invalid_credentials' => 'Thông tin đăng nhập không hợp lệ.',
            ],
        ],
    ],
    'taxonomy' => [
        'category' => [
            'list' => 'Danh sách danh mục.',
            'detail' => 'Chi tiết danh mục.',
            'created' => 'Tạo danh mục thành công.',
            'updated' => 'Cập nhật danh mục thành công.',
        ],
        'tag' => [
            'list' => 'Danh sách tag.',
            'detail' => 'Chi tiết tag.',
            'created' => 'Tạo tag thành công.',
            'updated' => 'Cập nhật tag thành công.',
        ],
        'technology' => [
            'list' => 'Danh sách công nghệ.',
            'detail' => 'Chi tiết công nghệ.',
            'created' => 'Tạo công nghệ thành công.',
            'updated' => 'Cập nhật công nghệ thành công.',
        ],
    ],
];
