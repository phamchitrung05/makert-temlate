# QA thời gian chờ provider và thử lại thủ công — 2026-10-03

## Thay đổi

- Thêm `ai_providers.request_timeout`: integer 5–600 giây, API resource không lộ key.
- Provider hiện tại nhận 120 giây từ migration; edit bỏ field giữ giá trị đã lưu.
- Mặc định provider mới đọc `config/ai/providers.php` / `AI_PROVIDER_REQUEST_TIMEOUT`.
  Config trong workspace hiện là 200 giây, giữ thay đổi của người dùng.
- Form dùng field số và icon Tabler; preset từ API mang default timeout.
- Text/image resolver, provider test và model discovery dùng thời gian chờ riêng.
- Timeout/mất kết nối HTTP không tự gửi lại. HTTP 429 và GET 5xx giữ retry có giới hạn.
- Ai Content có nút **Thử lại tác vụ** cho run failed; cập nhật trạng thái chỉ gọi GET.
- Retry backend khóa row, giữ UUID/model/input, lấy timeout mới nhất, gia hạn retention,
  từ chối provider/model đã tắt hoặc đổi identity, dispatch sau commit và chống gửi trùng.
- Job HTTP + 120 giây xử lý; queue database/Redis/Beanstalkd lease tối thiểu 900 giây.
- Form tạo provider dùng state riêng với detail panel, không bị đổi sang edit khi catalog tải xong.

## Kiểm thử tự động

- Backend: `AiProviderSettingsApiTest`, `AiImportApiTest`, `AiProviderAdapterTest`,
  `AiImageProviderTest`, `AiCandidateApiTest`: **46 tests, 272 assertions đạt**.
- Kiểm tra lại 2 test default/config sau khi default workspace đổi thành 200: **đạt**.
- Frontend: provider timeout/model editing/settings, content creation/form, agent service:
  **38 tests đạt**.
- Fakes xác nhận GET catalog/POST content/POST image mất kết nối chỉ gọi một lần;
  thử lại thủ công dùng 600 giây mới thay 120, không tạo UUID mới và không requeue trùng.
- Fakes kiểm chứng timeout truyền xuống HTTP options, capability text/image,
  validate range và worker timeout ngắn hơn queue lease.
- Pint các PHP thay đổi đạt. ESLint các file thay đổi không có error; trang providers
  còn warning indentation đã có trước ở markup hiện tại, không format toàn trang.
- Production build đạt; có warning asset mẫu có sẵn không resolve ở build time.
- `git diff --check` đạt.
- Database SQLite cô lập và HTTP/queue fake; không gọi generation có phí để QA.

## Browser local

- `/admin/settings/ai-providers`: field edit hiển thị 120 giây của provider đã lưu.
- Nhập 601 khiến nút lưu bị khóa; lưu 180 thành công và mở lại đọc được 180.
  Khôi phục provider về 120 sau kiểm tra; không thay đổi key/model.
- Form tạo mới lấy 200 giây từ config, giữ đúng chế độ tạo mới.
- Migration mới đã chạy local. Config cache đã clear và worker hiện có đã restart;
  queue có 0 job khi restart, worker chạy nền với `--timeout=720`.
- Ảnh: [AI_PROVIDER_TIMEOUT_2026-10-03.png](AI_PROVIDER_TIMEOUT_2026-10-03.png).

## Giới hạn hiện tại

Trường này là giới hạn HTTP mỗi request; TCP connect timeout vẫn là 5 giây.
Thử lại tác vụ gửi một request AI mới, không nối tiếp phản hồi upstream đã bị ngắt.
Kết quả upstream có thể đã được tạo trước lúc ngắt kết nối, vì vậy thông báo lỗi
nhắc kiểm tra trạng thái ở provider trước khi gửi lại thủ công.
