# QA Settings và AI Content — 2026-10-03

## Phạm vi

- Spatie Laravel Settings 3.9.0; chuyển schema và typed class, bảo toàn giá trị/audit/validation.
- Menu SYSTERM SETTING / SETTING, trang trống.
- Target catalog từ config, Post/Resource/Sound, badge và quyền theo target.
- Dialog edit/remove/regenerate; version check, HTML sanitizer, lineage và polling child.
- Đồng bộ builder regenerate trong AI Content, generic dialog và Post dialog cũ.

## Kiểm thử

- Backend: 68 tests / 384 assertions đạt trước kiểm tra bổ sung quyền Post CRUD.
  SQLite cô lập, HTTP/queue/provider fake; không gọi generation có phí.
- Kiểm tra cuối `AiContentWorkspaceApiTest`: 6 tests / 68 assertions đạt,
  gồm tài khoản chỉ có `resources.create` bị chặn cả CRUD Post và API AI Post cũ.
- Frontend: 48 tests đạt; kiểm tra lại 14 tests action/Post dialog sau đồng bộ
  builder và backoff đạt.
- Pint cuối: 17 file đạt; ESLint các component/composable/dialog/test đã đổi đạt.
- Build production cuối đạt (1 phút 9 giây); log tại `storage/logs/ai-content-build.log`.
- `git diff --check` đạt, không có lỗi whitespace.
- Migration test tạo dữ liệu key/value cũ, typed tuning và custom group;
  kiểm tra up/down/up bảo toàn giá trị, actor cũ và sửa đổi sau migration.
- Test editor kiểm tra HTML sạch, stale version, candidate đã apply, owner khác,
  title/body whitelist và không tạo Post/Resource.
- Test target thêm `lesson` chỉ qua config; create/hash/prompt/quyền đúng target.
- Test regenerate giữ parent/model khi optional trống, list child sau reload,
  từ chối prompt của target khác, partial content và cleanup timer.

## Browser local

- `/admin/ai/content`: dropdown đủ Post/Resource/Sound; icon Tabler, badge đúng loại.
- Tạo 3 candidate mẫu có prefix `[QA]`, không dùng provider có phí.
- Editor Resource sửa title/HTML thành công, list cập nhật và select bên phải vẫn giữ Resource.
- Tạo lại Sound qua deterministic provider, queue worker tạo child ready; bản cũ vẫn còn.
- Dialog xóa có xác nhận; hủy giữ item. Backend fake kiểm tra xóa và chặn active run.
- `/admin/settings`: vùng nội dung trống; menu mới hoạt động.
- Đã dọn 3 fixture và 1 child cùng audit test; queue còn 0 job.
- Local bảng settings cũ có 0 row; migration tạo 6 property mặc định. Test migration
  riêng đã xác nhận trường hợp có dữ liệu cũ.
- Worker hiện tại được restart sau migration; một worker `--timeout=720`.
- Ảnh dùng dữ liệu QA: [AI_CONTENT_SETTINGS_2026-10-03.jpg](AI_CONTENT_SETTINGS_2026-10-03.jpg).

## Giới hạn

- TinyMCE key của môi trường hiện tại không validate được, editor chuyển sang
  textarea HTML theo fallback của `PostEditor`; lưu HTML đã được kiểm tra.
- Sound chỉ tạo mô tả văn bản; không tạo file audio. Resource/Sound chưa apply
  sang domain. Post API apply đã tồn tại, UI duyệt/reject riêng vẫn pending.
- Chưa có `ai_content_drafts`; retention của `ai_imports` vẫn mặc định 2 ngày.
- Các mục provider parser/quality/extractor/Reverb còn pending tại thời điểm nghiệm thu; trạng thái mới nhất nằm ở [PLAN](../plans/PLAN.md).
