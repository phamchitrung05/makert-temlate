# Post filters P-04 — 2026-10-08

## Phạm vi

- Lọc danh sách Post Admin theo tác giả qua `author_id`, map vào cột hiện có
  `posts.created_by`.
- Lọc theo ngày tạo qua `created_from` và `created_to`, đều là ngày inclusive
  trên `posts.created_at`.
- `GET /api/admin/posts/authors` trả option `{ id, name, email }` của các user
  đang có Post; không trả credential.
- Giao diện đặt bộ lọc trong panel riêng phía trên bảng và dùng
  `AppDateTimePicker` cho hai mốc ngày. Bảng hiển thị thêm cột tác giả.

## Validation và hành vi

- `author_id` phải tồn tại trong `users`.
- Ngày dùng định dạng `Y-m-d`.
- Nếu `created_from` lớn hơn `created_to`, API trả HTTP 422 với lỗi
  `created_to`; giao diện không gửi query đảo chiều.
- Xóa filter sẽ tải lại danh sách từ trang 1.
- Không có thay đổi migration và không có `post_type`/Gallery trong P-04.

## Kiểm chứng

- `php artisan test --filter=PostFilterTest`: **3 tests / 14 assertions** đạt.
- `npx vitest run tests/frontend/postService.test.js`: **4 tests** đạt.
- ESLint các file frontend P-04: đạt.
- Pint các file PHP P-04: đạt.
