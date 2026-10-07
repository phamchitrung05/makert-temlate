# AI Content Review — nghiệm thu API/UI và tests, 2026-10-05

Chủ dự án chọn làm mục 2–3–4, tạm hoãn mục 1 (`ai_content_drafts`/lưu dài hạn).
Thay đổi dùng `ai_imports` và Spatie Activitylog hiện có; không thêm migration
hoặc thay retention. [Hợp đồng API](../api/AI_ARTICLE_PIPELINE_API.md),
[tiến độ và backlog](../plans/PLAN.md#backlog-đang-dùng).

## Kết quả nghiệp vụ

- Admin `posts.manage` chỉ xem/duyệt run của mình, target Post. Quyết định lưu
  `pending_review/approved/rejected` trong JSON editorial; actor/thời điểm do server lấy.
- Duyệt tạo một Post draft qua actions/media/SEO/taxonomy/provenance hiện có.
  Run được khóa và kiểm hai hash; lỗi rollback, duyệt trùng hoặc bản cũ trả 409.
  Publish vẫn là thao tác editor trong Post. API Apply cũ dùng cùng service.
- Từ chối bắt buộc lý do đã trim; giữ draft/job ready, khóa edit/Apply/approve và
  provenance qua PostForm. Regenerate có thể tạo run mới để biên tập tiếp.
- Spatie `activity_log`, log `ai-content`, các sự kiện `candidate.edited`,
  `candidate.approved`, `candidate.rejected`. UUID run nằm trong properties,
  dùng cùng cách nhóm với lịch sử edit hiện có; không đổi schema morph của Spatie.
  History API chỉ trả actor/time/reason/Post/fields và pagination allowlist.
- UI có tab Từ chối, lý do trong list, dialog nguồn/kết quả + lịch sử, editor
  hiện có và dialog xác nhận riêng. Nội dung nguồn render text trong template trơ.
  GET cũ không ghi đè bài mới; POST không tự retry. Lỗi mạng/409/5xx yêu cầu GET
  đối chiếu kết quả trước khi gửi tiếp; lý do đang nhập được giữ khi lỗi.

## Kiểm chứng

| Kiểm tra | Kết quả |
| --- | --- |
| Backend toàn bộ | 383 tests, 2649 assertions — đạt |
| Frontend toàn bộ | 45 files, 319 tests — đạt |
| Backend mới | 9 ca review: quyền/owner, source allowlist, approve/reject, hash, audit, rollback, media/cleanup, candidate cũ |
| Frontend mới | 9 ca composable, 7 ca dialog/compare và 1 ca service; callbacks cũ, lỗi đọc/POST, khóa submit, lý do, lịch sử, template trơ |
| Pint, ESLint, Stylelint | Scoped các file sửa/thêm — đạt |
| Production build | `npm run build -- --logLevel error` — đạt |
| Route | 4 endpoint review mới cùng PATCH/Apply cũ đăng ký đúng |

Lệnh tái hiện:

```powershell
php -d extension=gd -d xdebug.mode=off vendor/bin/phpunit --no-progress
npm run test:run -- --silent
npm run build -- --logLevel error
php artisan route:list --path=api/admin/ai-agent/candidates
```

PHP CLI mặc định chưa bật GD: lần chạy `php artisan test` có một test thumbnail
lỗi và một ca bị skip. GD có sẵn trong runtime; test ảnh nguồn riêng đạt
2 tests/23 assertions khi bật `-d extension=gd`, sau đó toàn bộ backend đạt.
Không sửa php.ini, không đổi môi trường web/worker. Tests dùng database cô lập
và fake HTTP/queue/storage, không gọi provider/model thật hoặc migrate DB đang dùng.

Kiểm tra browser tại localhost chuyển tới trang đăng nhập vì phiên kiểm tra
chưa đăng nhập; chưa thực hiện duyệt/từ chối trên dữ liệu ứng dụng đang dùng.
Các thao tác UI được kiểm bằng Vue Test Utils với button/form/layout Vuetify thật;
mutation/rollback/media/audit kiểm qua API trên database test.

## Giới hạn phạm vi được giữ

Retention run vẫn mặc định 2 ngày. Cleanup có thể xóa nguồn/bản AI; Post,
provenance, media đang dùng và Activitylog không bị cleanup run xóa. History API
theo candidate cần run tồn tại; Spatie có chính sách cleanup riêng. Chưa có kho
draft dài hạn, khôi phục run hoặc phân quyền duyệt chéo owner. Không thay đổi
đánh giá chất lượng bài AI/người đọc hoặc artifacts study Task 2 trước đó.
