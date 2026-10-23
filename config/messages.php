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

    /*
     * Các domain quản trị còn lại — message trả trong BaseResponse.data.message,
     * frontend đọc qua apiMessage() và chỉ dùng fallback khi API không trả message.
     */
    'visual_style' => [
        'list' => 'Danh sách visual style.',
        'detail' => 'Chi tiết visual style.',
        'created' => 'Tạo visual style thành công.',
        'updated' => 'Cập nhật visual style thành công.',
        'deleted' => 'Đã xóa visual style.',
        'analyze_queued' => 'Đã xếp hàng phân tích phong cách.',
        'dna_updated' => 'Đã cập nhật Style DNA.',
        'activated' => 'Đã kích hoạt visual style.',
        'archived' => 'Đã lưu trữ visual style.',
        'duplicated' => 'Đã nhân bản visual style.',
        'shot_created' => 'Tạo shot recipe thành công.',
        'shot_updated' => 'Cập nhật shot recipe thành công.',
        'shot_deleted' => 'Đã xóa shot recipe.',
        'shots_reordered' => 'Đã sắp xếp lại shot recipes.',
        'generation_queued' => 'Đã xếp hàng tạo ảnh.',
        'not_found' => 'Không tìm thấy visual style.',
        'invalid_status' => 'Trạng thái style không cho phép thao tác này.',
        'version_conflict' => 'Style DNA đã bị thay đổi — vui lòng tải lại rồi sửa tiếp.',
    ],
    'post' => [
        'list' => 'Danh sách bài viết.',
        'authors' => 'Danh sách tác giả bài viết.',
        'detail' => 'Chi tiết bài viết.',
        'created' => 'Tạo bài viết thành công.',
        'updated' => 'Cập nhật bài viết thành công.',
        'deleted' => 'Đã xóa bài viết.',
        'submitted_review' => 'Đã gửi duyệt bài viết.',
        'published' => 'Đã xuất bản bài viết.',
        'rejected' => 'Đã từ chối bài viết.',
        'archived' => 'Đã lưu trữ bài viết.',
        'not_found' => 'Không tìm thấy bài viết.',
    ],
    'social_post' => [
        'list' => 'Danh sách Social Post.',
        'detail' => 'Chi tiết Social Post.',
        'created' => 'Đã tạo Social Post draft.',
        'updated' => 'Đã cập nhật Social Post.',
        'deleted' => 'Đã xóa social post.',
        'generate_queued' => 'Đã xếp hàng tạo caption và ảnh.',
        'regenerate_caption_queued' => 'Đã xếp hàng tạo lại caption.',
        'regenerate_image_queued' => 'Đã xếp hàng tạo lại ảnh.',
        'facebook_content_queued' => 'Đã xếp hàng tạo content Facebook.',
        'facebook_images_queued' => 'Đã xếp hàng tạo gallery Facebook.',
        'slot_regenerate_queued' => 'Đã xếp hàng tạo lại ảnh.',
        'submitted_review' => 'Đã gửi Social Post để duyệt.',
        'approved' => 'Đã approve Social Post.',
        'rejected' => 'Đã reject Social Post.',
        'revision_requested' => 'Đã yêu cầu chỉnh sửa Social Post.',
        'variants' => 'Các variant Social Post.',
        'facebook_content_updated' => 'Đã cập nhật content Facebook.',
        'facebook_gallery_updated' => 'Đã cập nhật gallery Facebook.',
        'facebook_gallery_reordered' => 'Đã sắp xếp gallery Facebook.',
        'publish_queued' => 'Đã xếp hàng publish theo từng Page.',
        'retry_queued' => 'Đã xếp hàng retry publication.',
        'not_found' => 'Không tìm thấy social post.',
    ],
    'media' => [
        'list' => 'Danh sách media.',
        'detail' => 'Chi tiết media.',
        'uploaded' => 'Tải media thành công.',
        'updated' => 'Cập nhật media thành công.',
        'deleted' => 'Đã xóa media.',
        'retry_queued' => 'Đã xếp hàng xử lý lại media.',
        'attach' => 'Đã gắn media vào đối tượng.',
        'detach' => 'Đã gỡ media khỏi đối tượng.',
        'reorder' => 'Đã sắp xếp lại media.',
        'not_found' => 'Không tìm thấy media.',
    ],
    'resource' => [
        'list' => 'Danh sách resource.',
        'detail' => 'Chi tiết resource.',
        'created' => 'Tạo resource thành công.',
        'updated' => 'Cập nhật resource thành công.',
        'deleted' => 'Đã xóa resource.',
        'published' => 'Đã xuất bản resource.',
        'archived' => 'Đã lưu trữ resource.',
        'not_found' => 'Không tìm thấy resource.',
    ],
    'resource_version' => [
        'created' => 'Tạo resource version thành công.',
        'updated' => 'Cập nhật resource version thành công.',
        'deleted' => 'Đã xóa resource version.',
        'ready' => 'Version đã sẵn sàng.',
        'promoted' => 'Đã nâng cấp version.',
        'rollback' => 'Đã khôi phục version.',
        'not_found' => 'Không tìm thấy resource version.',
    ],
    'user' => [
        'list' => 'Danh sách người dùng.',
        'detail' => 'Chi tiết người dùng.',
        'created' => 'Tạo người dùng thành công.',
        'updated' => 'Cập nhật người dùng thành công.',
        'deleted' => 'Đã xóa người dùng.',
        'not_found' => 'Không tìm thấy người dùng.',
    ],
];
