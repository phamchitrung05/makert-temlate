# Ai Content — form tạo bài mới và nút tạo AI

Bắt đầu 2026-10-02, hoàn tất kiểm chứng sau 00:00 ngày 2026-10-03.

## Thay đổi

- Cột phải luôn là form tạo bài Post mới. Bỏ editor/SEO/media của bài đang chọn;
  bấm dòng danh sách không thay đổi form. Dialog chi tiết/duyệt bổ sung sau.
- Header Tạo content AI mới reset nguồn và thông báo, giữ provider/model;
  không chèn bản nháp giả. Chặn reset và gửi trùng khi đang tạo.
- Nút cũ có literal `disabled`, chưa gắn handler. Nút mới gọi session API hiện có,
  kiểm tra nguồn/catalog/capability và hiển thị lý do khi chưa thể gửi.
- Ưu tiên model có `text_generation` lúc khởi tạo. Giữ lựa chọn model ảnh rõ ràng
  của người dùng, đồng thời báo nó không hỗ trợ viết bài; không suy từ tên model.
- URL chuyển thành input URL; nội dung/đề bài chuyển thành input text. File
  .html/.htm tối đa 5 MB được đọc vào template DOM trơ, loại script/navigation
  và lấy text article/main; giữ tiêu đề trong header của article. Text tối đa
  200.000 ký tự. Độ dài/ngôn ngữ/tiêu đề/SEO/rewrite đi vào instructions.
- Thumbnail lấy từ nguồn URL theo API hiện có; không gọi thêm model tạo ảnh.
- POST create lưu run; page GET polling theo progress cho tới ready/failed.
  Mất kết nối có nút đọc lại status, không tạo lại run. Timer có giới hạn và
  cleanup khi rời page; response cũ không cập nhật page đã dispose.
- GET `/api/admin/ai-agent/sessions` trả summary phân trang theo owner, chỉ root
  create chưa hết hạn. Không trả nội dung nguồn/body/input snapshot/key/URL query.
  Page đọc mọi trang, lọc/phân trang cục bộ và merge lifecycle mới vào danh sách.

## Kiểm chứng

- Frontend: 34 tests đạt (creation 12, form 2, catalog 7, settings 7, service 6).
  Bao gồm mapping 4 nguồn, model ảnh/unknown, duplicate submit, queued→ready/failed,
  lỗi validation, nối lại polling, dispose và list refresh không đè kết quả mới.
- Backend: `AiImportApiTest` 11 tests / 63 assertions đạt, fake queue + DB cô lập.
  Collection kiểm tra auth/permission, owner, root/child/image/expiry, pagination
  validation và không lộ body/input/credential.
- ESLint file thay đổi, targeted Pint, production build và `git diff --check` đạt.
- Browser localhost: chọn mặc định `APIKEY.FUN GPT CONTENT` / `gpt-6.1-sol`;
  URL rỗng khóa nút, URL hợp lệ bật nút; bấm dòng list giữ nguyên nguồn.
- Header reset làm nguồn trống, giữ model và giữ nguyên số bản ghi trong list.
- Chọn `APIKEY.FUN` / `gpt-image-2`: báo không hỗ trợ viết nội dung và khóa nút.
  Chuyển về provider text, nhập đề bài: nút bật. Đã kiểm tra DOM và ảnh thực tế.
- Không gửi tạo bài trả phí để QA. Luồng gọi API/polling được kiểm bằng fake service,
  create endpoint dùng fake queue; không xác nhận chất lượng/provider response live.
- Ảnh: `AI_CONTENT_CREATE_2026-10-02.png` (toàn trang) và
  `AI_CONTENT_CREATE_FORM_2026-10-02.png` (form/nút tại viewport mặc định).

## Phạm vi còn lại

- Page này hiện tạo bài Post, chưa bật Resource/Sound/Image adapter.
- Dialog chi tiết/chỉnh sửa/duyệt và kho nháp dài hạn được bổ sung sau.
- Run dùng retention backend hiện có, mặc định **2 ngày**; chưa là bảng nháp lưu lâu dài.
- Cần queue worker chạy để xử lý bài; page hiển thị queued nếu worker chưa nhận run.
