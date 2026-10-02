# Ai Content dùng catalog AI Settings — 2026-10-02

## Thay đổi

- Menu dọc đặt `AI Settings` ngay sau `Ai Content` trong `Systerm AI`.
- Menu ngang đặt cả hai mục trong `Systerm AI`; AI Settings giữ action/subject
  `manage/ai_settings`, dùng route name `settings-ai-providers`.
- Page Ai Content tải `GET /api/admin/settings/ai` một lần khi mở, tái sử dụng
  service/composable AI Settings. Chuyển bản nháp không tải lại catalog.
- Provider/model được dùng chung cho nguồn URL, HTML, text và prompt. Source
  lưu provider key và remote model ID; không còn options model mẫu cố định.
- Chỉ hiện provider active/có key và model enabled/available. Model chưa có
  capability vẫn hiện trong catalog, không suy ra capability từ tên.
- Khi đổi provider, model cũ được bỏ; chọn model hợp lệ theo text default đã
  lưu hoặc model đầu tiên khả dụng. Tải lại giữ lựa chọn hợp lệ, loại lựa chọn
  đã bị tắt/xóa khỏi catalog; có loading, lỗi/retry và empty state.

## Kiểm chứng

- `aiContentCatalog.test.js` và `aiProviderSettings.test.js`: 13 tests đạt.
- ESLint các file thay đổi, production build và `git diff --check` đạt.
- Browser xác nhận menu mới và dropdown của `APIKEY.FUN` có 4 model đã sync.
- Chọn `gpt-image-2.5`, tải lại và xác nhận lựa chọn được giữ.
- Đổi sang `gpt CONTENT`, Model cập nhật thành `gpt-6.1-sol`.
- Chuyển nguồn sang Viết tự do vẫn dùng catalog/selection hiện tại.
- Ảnh giao diện: `AI_CONTENT_CATALOG_2026-10-02.png`.

Phạm vi này chỉ nối catalog và lựa chọn. Tạo AI/lưu/xuất bản trên Ai Content
chưa kết nối backend. API key được xử lý ở backend; form chỉ nhận metadata.
