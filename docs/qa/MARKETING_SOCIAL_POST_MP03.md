# QA MP-03 — AI Content → Social Post

Ngày: 20/10/2026. Trạng thái: DONE LOCAL.

## Luồng đã triển khai

- `POST /api/admin/ai-agent/sessions` với `target_type=social_post` gọi
  `SocialPostAiAdapter`, tạo draft/variant và xếp các component trong một transaction.
- Dùng chung `SocialPostGenerationService`, context builder, prompt/schema và workers
  với Social tạo trực tiếp/scheduler. Chỉ chạy content/gallery được chọn; không tạo Blog Post.
- Giữ nguồn text, ngôn ngữ, brief, độ dài từ config, writing/image profile và model.
  Regenerate giữ lại snapshot nguồn và lựa chọn model nếu không có override.
- Chống gửi trùng theo actor/payload trong cửa sổ idempotency; quota và async queue guard.
  Model không hợp lệ rollback draft/imports. Không gọi provider trong HTTP request.
- Gallery fan-out đúng 1–10 slot; attach theo thứ tự, partial failure giữ phần đã hoàn tất.
- Tạo lại riêng từng ảnh: tăng attempt, giữ generation của bundle và ảnh cũ cho đến khi thay thế.
  Job từ attempt/generation cũ hoặc đã hủy không được ghi đè kết quả mới.
- Ảnh provider trả về sau cancel/stale hoặc lỗi attach được dọn nếu chưa có usage.
  Ảnh cũ đã thuộc lịch sử phiên bản vẫn được giữ.
- UI chọn model ảnh, mở Social draft, polling đến terminal và retry từng ảnh.
  Popup queue mở đúng Social Post thay vì dialog candidate Post.

## Kiểm chứng

Backend: **58 tests / 370 assertions đạt** với filter
`Social|AiContentWorkspaceApiTest|AiTaskRun`.
Sử dụng SQLite cô lập, Queue fake và provider fake; chặn HTTP ngoài fixture.
`SocialAiContentIntegrationTest` kiểm API, rollback, quyền, dedupe theo owner,
selected outputs, snapshot, schema, fan-out, attach ordered, retry từng slot,
partial failure, cancel/stale và orphan cleanup.

Fixture reference asset cũ được cập nhật để xác nhận Social không kế thừa Product;
không khôi phục dependency đã bỏ ngày 18/10.

Frontend: **71 tests đạt** trong 7 file: aiContentCreation, aiContentSourceForm,
aiContentCatalog, socialPostList, socialPostService, aiTaskQueue,
socialPostGenerationDialog. Có kiểm deep link, gửi đúng image model, polling,
ngừng polling khi đóng và bỏ response đến muộn của bài khác.

Vite production build đạt (lượt cuối 41,68 giây). Còn cảnh báo asset có sẵn
`section-title-icon.png` chưa resolve khi build; không thuộc MP-03.
ESLint/Pint scoped và `git diff --check` được kiểm cho thay đổi.

## Giới hạn

Chưa chạy browser smoke thủ công, provider ảnh thật, Facebook Page thật hoặc
nhiều worker trên host. Các bước này giữ ở MP-00/MP-08/H-01.
Không chạy lại toàn bộ backend/frontend repository trong đợt này.
MP-03 không tự submit review hoặc publish bài.
