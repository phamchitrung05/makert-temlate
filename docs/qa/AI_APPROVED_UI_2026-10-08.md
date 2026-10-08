# Nghiệm thu AI-01 — màn hình AI Approved

**Ngày:** 08/10/2026, Asia/Bangkok. **Phạm vi:** đọc archive bài AI đã duyệt dài hạn.

## Kết quả

- Đã thêm API `GET /api/admin/ai-agent/approved-archives` và `GET /api/admin/ai-agent/approved-archives/{id}` dưới quyền `posts.manage`.
- Đã thêm menu `AI Approved` trong điều hướng dọc/ngang của `Systerm AI`, route `ai-approved`.
- Trang có panel filter riêng phía trên datatable: tìm tiêu đề và khoảng ngày lưu kho bằng `AppDateTimePicker`; bảng phân trang server hiển thị target type/ID, model/provider, văn phong, ngày duyệt và Post liên kết khi target là Post.
- Dialog chỉ đọc bản AI gốc và nguồn snapshot đã sanitize, không cho sửa/xóa archive. Nếu Post bị xóa, snapshot vẫn xem được; target khác vẫn hiển thị identity generic.
- Kho không tạo bước lưu mới: archive được tạo trong transaction duyệt/Apply hiện có. Candidate chưa duyệt vẫn theo retention 2 ngày.

## Identity đa model

`ai_article_archives` dùng cặp `target_type` + `applied_target_id` thay vì foreign key cố định vào `posts`. API không lọc cứng Post; Post chỉ là target đầu tiên có workflow approve hoàn chỉnh. Resource/Sound sẽ dùng chung màn hình khi AI-04 bổ sung adapter và review/apply workflow.

## Kiểm thử

- `php artisan test tests/Feature/AiArticleArchiveApiTest.php`: **4 test, 20 assertions đạt**.
- `php artisan test tests/Feature/AiArticleArchiveTest.php tests/Feature/AiArticleArchiveApiTest.php`: **37 test, 347 assertions đạt**.
- `npx vitest run tests/frontend/aiArticleArchivesService.test.js tests/frontend/aiContentComparison.test.js`: **4 test đạt**.
- ESLint các file AI-01 đạt.
- `npm run build`: build production đạt; còn cảnh báo asset cũ `section-title-icon.png` không resolve lúc build và sẽ resolve lúc runtime.

## Chưa thuộc AI-01

Chấm điểm từng bài, báo cáo định kỳ, evaluator và lưu điểm vẫn theo Q-01; Apply cho Resource/Sound vẫn theo AI-04. Archive là read-only và không thay đổi Post hiện tại.
