# Post workflow P-01 — 2026-10-08

## Phạm vi

- Post mới luôn được tạo ở `draft`; `status` và `published_at` không còn được
  đổi qua CRUD.
- Workflow có các bước `draft/rejected → pending_review → published` và
  `archive` từ mọi trạng thái chưa archive. Từ chối yêu cầu lý do và đưa Post
  về `rejected` để chỉnh sửa/gửi review lại.
- API lifecycle:
  - `POST /api/admin/posts/{post}/submit-review`
  - `POST /api/admin/posts/{post}/publish`
  - `POST /api/admin/posts/{post}/reject` với `reason`
  - `POST /api/admin/posts/{post}/archive`
- Permission Post được tách thành `posts.view`, `posts.create`, `posts.update`,
  `posts.delete`, `posts.review`, `posts.publish`, `posts.archive`;
  `posts.manage` vẫn là alias toàn quyền để tương thích role cũ.
- Mọi transition khóa dòng Post, ghi `updated_by` và activity log trong
  transaction. `published_at` lấy từ server và được giữ lại khi archive.

## Kiểm chứng

- `PostWorkflowTest`: **3 tests / 33 assertions** đạt.
- Regression `PostSeoSlugTest`, `PostGalleryTest`, `AiContentReviewApiTest`:
  **29 tests / 313 assertions** đạt.
- Frontend `postForm.test.js`, `postService.test.js`:
  **20 tests** đạt.
- Toàn bộ backend: **493 tests / 3470 assertions** đạt.
- Toàn bộ frontend: **51 test files / 362 tests** đạt.
- Scoped ESLint cho các file Vue/JS của Post và Pint cho PHP đã thay đổi đạt.
- Production build đạt. Bộ test vẫn có cảnh báo component Vue chưa được stub;
  build có cảnh báo asset `section-title-icon.png` chưa resolve, không gây lỗi.
- `git diff --check` đạt, không có lỗi khoảng trắng.

## Ghi chú vận hành

Migration [`2026_10_08_090000_add_post_workflow_permissions.php`](../../database/migrations/2026_10_08_090000_add_post_workflow_permissions.php)
tạo permission mới cho guard `admin` và bổ sung cho role mặc định `admin/editor`.
Frontend chỉ ẩn/hiện nút sớm theo session; middleware route và action backend
luôn là ranh giới phân quyền cuối cùng.
