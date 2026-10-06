# Bàn giao cuối phiên — 2026-10-06

Chủ dự án yêu cầu commit/push GitHub và tiếp tục vào ngày 2026-10-07.
Nhánh làm việc: `main`. Không có task kế tiếp nào được chọn trong phiên này.

## Thay đổi đã hoàn tất trong phiên

- Upload logo/favicon tại Settings → Tổng quan → Thương hiệu website: preview,
  lưu cùng version, hoàn tác/gỡ về mặc định, quyền và xử lý lỗi/409. Logo/favicon
  áp dụng public/admin; file mới rollback được dọn, file cũ chỉ dọn sau commit.
- Migration `2026_10_06_180000_add_site_branding.php` đã chạy riêng trên DB local;
  thêm hai property null, không thay branding hoặc thông tin website đã lưu.
- Media dialog dùng style/component của project, không gian lớn hơn và panel
  xem trước riêng. Grid khoảng 10 cột khi đủ rộng, tự giảm theo màn hình;
  item giữ hình vuông. Selection/search/pagination giữ contract hiện có.
- Dialog duyệt AI Content dùng bố cục mới với dữ liệu động, preview/diff hai
  phía và lịch sử; chuẩn hóa thông báo lỗi quality cho người dùng.
- Writing profile validator giữ đủ các rule tùy chọn hợp lệ thay vì làm mất
  field. Đối chiếu số hỗ trợ số lượng “nội dung” và nhãn thứ tự trình bày;
  số hội nghị/điều khoản/văn bản và số lượng sai vẫn được kiểm tra.
- Chuẩn hóa định dạng trong các file đã sửa. Stylelint nhận pseudo-class Vue
  `deep/global/slotted` trong Vue override; `.zcode/` giữ artifact cục bộ.

## Kiểm chứng cuối phiên

- Backend scoped: **73 tests / 336 assertions** đạt.
- Frontend scoped: **7 files / 35 tests** đạt.
- Scoped ESLint, Stylelint, Pint và `git diff --check` đạt. Production build
  thành công trong **1 phút 2 giây**; còn warning asset demo
  `section-title-icon.png` đã có trước.
- Các lệnh dùng để kiểm:

```powershell
php -d extension=gd -d xdebug.mode=off vendor/bin/phpunit tests/Feature/SiteBrandingTest.php tests/Feature/ProjectSettingsApiTest.php tests/Feature/SpatieSettingsMigrationTest.php tests/Feature/AiWritingProfilesApiTest.php tests/Unit/ArticleNumberGroundingTest.php tests/Unit/WritingProfileDefinitionTest.php --no-progress
npx vitest run tests/frontend/settingsBranding.test.js tests/frontend/settings.test.js tests/frontend/settingsLocales.test.js tests/frontend/aiContentComparison.test.js tests/frontend/aiContentReviewDialog.test.js tests/frontend/aiRunFeedback.test.js tests/frontend/mediaLibraryDialog.test.js
```

Chi tiết browser QA, ảnh và giới hạn của branding:
[Settings Branding QA](qa/SITE_BRANDING_2026-10-06/README.md).
Browser branding dùng API fixture; Laravel upload/persistence/quyền được kiểm
bằng feature tests với storage/database cô lập. Không gọi AI trả phí trong đợt
kiểm chứng cuối phiên.

## Trạng thái để tiếp tục

- **Task 1/Task 2 DONE kỹ thuật.** Corpus 20 nguồn, snapshot/hash, công cụ đánh
  giá và báo cáo đã có/đã chạy; không phải chức năng tạo content chưa triển khai.
- **Nghiệm thu chất lượng AI còn chờ:** người đọc xác nhận nguồn/facts, chốt
  tiêu chí, hai người chấm độc lập, hiệu chỉnh prompt/gates/tham số theo kết quả,
  nghiệm thu nhận xét văn phong Ai Prompt và tổng hợp quyết định rollout.
- Các phiên mới chỉ chạy C (Analyze + Plan → Write → Edit); không phục hồi B.
  Study B/C và điểm trống lịch sử giữ nguyên. Không tự gọi model để hoàn tất
  việc chấm của người đọc.
- **Realtime/VPS bỏ qua** theo lựa chọn của chủ dự án. Kho `ai_content_drafts`
  dài hạn và thumbnail từ nội dung Post đã biên tập tạm hoãn.
- **Settings mở rộng:** webhook CRUD/delivery/history, scheduler run history,
  heartbeat worker, GA/GSC/2FA/CAPTCHA và kiểm SMTP production còn backlog.
- **Post/Admin:** customer list, review/publish workflow, phân quyền chi tiết,
  revision/khôi phục và `post_type=gallery`/trình diễn ảnh còn backlog.
- **AI mở rộng:** Laravel AI SDK, Apply Resource/Sound/audio, thống kê chi phí
  tập trung và staging. File HTML cho AI Content đã có; checkbox cũ về file
  nguồn không phải bằng chứng tính năng này còn thiếu.
- **Ai Prompt mở rộng:** lịch sử analysis server, đọc lại nguồn, preview bằng
  quyền AI riêng và idempotency server còn để sau; List/Add/CRUD đã có.
- **Public/SEO/commerce:** public được làm ở luồng riêng; sitemap, structured
  data, free download đầy đủ, commerce và production hardening theo plan tổng.

Nguồn theo dõi: [PLAN](PLAN.md), [FIX 1](fix_1.md),
[Post/Media/AI](PLAN_POST_MEDIA_AI_INTEGRATION.md),
[đánh giá AI](AI_ARTICLE_QUALITY_EVALUATION.md).
`PLAN_MEDIA_LIBRARY.md` đã DONE. Checkbox Tiny Cloud cũ trong
`PLAN_ADDPOST.md` cần đọc cùng bằng chứng nghiệm thu mới tại FIX 1 mục 12.35.
