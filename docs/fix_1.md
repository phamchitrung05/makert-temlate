# FIX 1 — Ổn định AI Content và quy trình duyệt bài

**Ngày lập:** 2026-10-02
**Trạng thái:** Bản nháp chờ duyệt
**Mục tiêu:** Gom các lỗi đã xác nhận trong lúc test, chốt một lần rồi triển khai đồng bộ.

## 1. Nguyên tắc đã thống nhất

- AI chỉ tạo candidate/bản nháp, không tự động xuất bản công khai.
- `ai_imports` theo dõi tác vụ kỹ thuật: queue, provider, tiến độ và lỗi.
- Tạo lớp biên tập riêng `ai_content_drafts`: chờ duyệt, đã duyệt, từ chối.
- Khi duyệt, mặc định tạo `posts.status = draft`; editor kiểm tra lần cuối rồi Publish.
- Queue là bắt buộc. Reverb/Echo dùng cho realtime khi VPS có process manager.
- Polling vẫn tồn tại làm phương án dự phòng khi WebSocket mất kết nối.
- Nội dung URL, text và file HTML luôn là dữ liệu không tin cậy; phải extract và sanitize.

## 2. Các vấn đề cần sửa

### A. Provider và structured output

- [ ] Cùng request nhưng provider có thể trả JSON đúng, `tool_calls`, JSON lỗi hoặc timeout.
- [ ] Backend chưa kiểm tra chặt `finish_reason`; hiện chủ yếu đọc `message.content`.
- [ ] Không gửi `tools`; bổ sung `tool_choice: none` cho provider hỗ trợ.
- [ ] Dùng temperature `0` cho tác vụ structured JSON.
- [ ] Bắt buộc response có `title` và `content_html` dạng chuỗi không rỗng.
- [ ] `finish_reason != stop`, có `tool_calls`, content rỗng hoặc sai schema phải fail rõ ràng.
- [ ] Không merge fallback rồi đánh dấu `ready` khi AI không trả nội dung hợp lệ.
- [ ] Lưu metadata an toàn: response ID, model, finish reason, usage, returned fields và schema error.
- [ ] Không lưu API key/header; raw response đầy đủ chỉ bật ở chế độ debug có giới hạn và redaction.

### B. Prompt và chất lượng nội dung

- [ ] Viết lại prompt: thay đổi cách diễn đạt/cấu trúc nhưng giữ sự thật, code, liên kết và trích dẫn.
- [ ] Bỏ `suggested_category_ids` và `suggested_tag_ids` khỏi output AI.
- [ ] AI chỉ đề xuất tên/chủ đề; backend tự ánh xạ sang taxonomy hiện có.
- [ ] Tính similarity trên text đã normalize; exact copy phải fail hoặc chuyển `quality_failed`.
- [ ] Kiểm tra đúng ngôn ngữ đầu ra.
- [ ] Cho phép tạo lại toàn bài hoặc từng nhóm field sau khi sửa contract regenerate.

### C. Timeout, queue và realtime

- [x] Đã tăng `request_timeout` trong settings lên 120 giây.
- [x] `job_timeout = 180`, database queue `retry_after = 300`.
- [ ] Rà lại worker production để `--timeout < retry_after` và đủ lớn hơn request timeout.
- [ ] Sửa frontend polling sang backoff thay vì gọi mỗi 1,2 giây liên tục.
- [ ] Broadcast event khi queued/processing/ready/failed.
- [ ] Tích hợp Laravel Echo + Reverb; polling fallback khi WebSocket mất kết nối.
- [ ] Cấu hình Supervisor/systemd/Docker cho `queue:work` và `reverb:start` trên VPS.

### D. Nguồn bài viết

- [ ] Sửa extractor: không xoá toàn bộ con khi trang bọc nội dung trong `<form>`.
- [ ] Hỗ trợ `main`, `section`, `article` và lựa chọn container theo mật độ nội dung.
- [ ] Loại menu/footer/ads/script/iframe nhưng giữ semantic HTML của bài.
- [ ] Thêm nguồn file HTML upload và nội dung paste khi website chặn bot.
- [ ] Validate MIME, kích thước, encoding; file chỉ là dữ liệu, không thực thi script/instruction.
- [ ] Hiển thị preview phần nguồn đã extract trước khi gửi AI.

### E. Regenerate và frontend contract

- [ ] Tách payload builder cho create và regenerate.
- [ ] Map output về sáu nhóm backend nhận: `title`, `excerpt`, `content`, `seo`, `taxonomy`, `thumbnail`.
- [ ] Không gửi `prompt_key`, provider, model hoặc optional field khi giá trị rỗng/null.
- [ ] Backend tương thích payload cũ có kiểm soát và chỉ coi giá trị đã điền là override.
- [ ] Giữ parent candidate, tạo child run mới và theo dõi đúng `job_id`.

### F. Provider authentication và 9Router local

- [ ] Mở rộng provider từ API key-only sang `auth_type`: API key, không xác thực hoặc OAuth2 khi provider có API OAuth2 chính thức.
- [ ] API key có thể optional theo loại xác thực; không dùng cookie/session của trang chat thay cho API chính thức.
- [ ] Cho phép endpoint loopback/private chỉ trong môi trường local và phải có cấu hình allowlist rõ ràng; production tiếp tục chặn SSRF.
- [ ] Đồng bộ model từ endpoint OpenAI-compatible `/v1/models` và lưu capability cần thiết: tools, reasoning, vision, context window, max output.
- [ ] Health check phải gọi chat completion thật; không coi việc model xuất hiện trong `/models` là bằng chứng model còn hoạt động.
- [ ] Loại model đã ngừng phục vụ hoặc trả 404 khỏi danh sách chọn; có cache và thời hạn làm mới trạng thái.
- [ ] Với model reasoning, dùng giới hạn output đủ lớn để tránh kết luận sai do toàn bộ token bị dùng cho phần suy luận.
- [ ] Ghi nhận khả năng router/provider chèn system prompt; so sánh usage và raw metadata đã redaction khi chẩn đoán.

## 3. Trang quản lý bài viết AI

Luồng đề xuất:

```text
URL / text / HTML file
        ↓
ai_imports: queued → processing → ready / failed
        ↓
ai_content_drafts: pending_review
        ↓
approve → posts.status=draft → editor Publish
reject  → giữ lịch sử và lý do từ chối
```

Trang `AI Drafts` cần tối thiểu:

- [ ] Danh sách đang xử lý, chờ duyệt, đã duyệt, từ chối và lỗi.
- [ ] Xem nguồn và kết quả AI cạnh nhau; hiển thị similarity.
- [ ] Chỉnh sửa candidate trước khi duyệt.
- [ ] Tạo lại toàn bài hoặc từng phần.
- [ ] Duyệt sang Post draft; lưu liên kết giữa AI draft và Post.
- [ ] Hiển thị provider/model, prompt version, thời gian, token và lỗi an toàn.
- [ ] Không trộn trạng thái kỹ thuật với trạng thái biên tập.

## 4. Thứ tự triển khai dự kiến

1. Chốt contract response, prompt, similarity và chính sách lỗi/fallback.
2. Sửa provider parser/validator, logging metadata và test adapter.
3. Sửa extractor, thêm HTML file input và test nguồn thực tế.
4. Sửa regenerate contract và regression tests backend/frontend.
5. Thêm migration/model/API cho `ai_content_drafts` và thao tác approve/reject.
6. Xây trang `AI Drafts`, preview/compare/edit/apply.
7. Thêm event broadcast, Echo/Reverb và polling fallback.
8. Bổ sung provider auth/9Router local, model sync và health check.
9. Cấu hình Supervisor trên VPS, smoke test queue/reboot/reconnect.

## 5. Tiêu chí hoàn thành

- AI không được báo `ready` nếu thiếu `title` hoặc `content_html`.
- Response `tool_calls`, sai JSON, sai schema và timeout có mã lỗi riêng.
- Bài exact-copy không được coi là kết quả AI hợp lệ.
- URL, text và file HTML đều tạo được draft hoặc báo lỗi nguồn rõ ràng.
- Regenerate tạo child candidate và không trả 422 do sai tên field.
- Người dùng xem, sửa, duyệt hoặc từ chối bài AI trên trang riêng.
- Duyệt chỉ tạo Post draft; không tự publish công khai.
- Realtime hoạt động trên VPS; mất Reverb vẫn xem được tiến độ qua polling backoff.
- Có test cho provider response, extractor, queue lifecycle, approval và frontend contract.

## 6. Các điểm cần chốt khi duyệt plan

- [ ] Ngưỡng similarity tối đa: đề xuất `<= 60%` hay mức khác?
- [ ] Exact-copy sẽ fail ngay hay cho phép editor duyệt thủ công?
- [ ] Approve tạo Post draft (khuyến nghị) hay publish công khai ngay?
- [ ] Có lưu raw response đã redaction trong thời gian ngắn để debug không?
- [ ] Triển khai Reverb ngay đợt này hay sau khi đưa dự án lên VPS?
- [ ] Giới hạn file HTML: đề xuất 5 MB; có cho phép `.mhtml` không?
- [ ] Chọn model text mặc định/fallback nào thay cho deterministic khi dùng chế độ Auto?
- [ ] Có đưa hỗ trợ 9Router local vào đợt FIX 1 hay tách thành đợt cấu hình provider riêng?
- [ ] OAuth2 chỉ triển khai cho provider nào có tài liệu và endpoint OAuth chính thức?

## 7. Ghi chú test đã xác nhận

- `glm-5.3-cn` có lần trả JSON hợp lệ, có lần trả `tool_calls` dù request không gửi tools.
- Test kết nối ngắn không đại diện cho tác vụ tạo bài thật.
- Bài Mailbox phản hồi hợp lệ sau khoảng 61 giây, vượt timeout cũ 30 giây.
- StarTravel URL lỗi vì toàn trang nằm trong `<form>` và extractor xoá cả cây con.
- Dùng file HTML cục bộ, AI hoàn tất sau khoảng 49 giây; text similarity khoảng 72%.
- Default/fallback text model hiện chưa được chốt; chế độ Auto có thể rơi về deterministic.
- 9Router local tại `http://localhost:20128/v1` trả danh sách 148 model; endpoint `/models` đọc được không cần token ở thời điểm test.
- Nhóm `ag` có 16 model: 12 model chat hoạt động, 3 alias trả thông báo Gemini 3.5 đã ngừng và 1 model trả HTTP 404.
- Prompt kiểm tra chỉ một câu nhưng một số response báo hơn 2.000 prompt token, cho thấy có khả năng tầng router/provider tự chèn system prompt.
