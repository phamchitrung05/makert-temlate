# TPL-04 — ProcessTemplateVersionJob và artifact lifecycle

**Ngày:** 14/10/2026  
**Trạng thái:** DONE  
**Phạm vi:** queue processing, checksum/token guard, private staging/artifact,
idempotency, failure lifecycle và hiển thị tracker trong popup hàng đợi dùng chung.

## Thay đổi chính

| Thành phần | File | Chức năng |
| --- | --- | --- |
| Processing service | `app/Services/Resources/TemplateVersionProcessingService.php` | Enqueue sau commit, lock version, recheck package/scan/checksum, publish artifact và ghi screens/manifest sau artifact ready |
| Queue job | `app/Jobs/Resources/ProcessTemplateVersionJob.php` | Unique theo version/token, 3 retries, backoff 30/120/300 giây, timeout 180 giây và terminal failure |
| Parser deadline | `app/Services/Resources/TemplatePackageExtractor.php`, `config/media-assets.php` | Deadline mặc định 120 giây/run, actual-byte/path policy và cleanup staging |
| Queue adapter | `app/Services/Ai/Runs/Adapters/ResourceTemplateTaskAdapter.php` | Projection `resource_template_processing` vào `ai_task_runs`, quyền, cancel và cleanup private |
| Queue popup | `AiTaskRunResource.php`, `useAiTaskQueue.js`, `AiTaskQueueItem.vue`, `AiTaskQueuePopup.vue` | Hiển thị Template trong popup, mở version khi ready, lỗi/cancel theo tracker chung |
| Feature test | `tests/Feature/ResourceTemplateProcessingTest.php` | Enqueue, processing/idempotency, stale token và API visibility/cancel |

Package luôn được đọc từ MediaAsset archive private đã scan clean. Job chỉ giữ
ID/checksum/token dạng scalar; service đọc lại source dưới lock trước khi extract.
ZIP được giải nén vào `media_private/template-processing/{version}/{token}`, giữ
nguyên cây site, rồi move sang
`media_private/template-previews/{version}/{token}`. Chỉ sau khi artifact tồn tại
đầy đủ service mới cập nhật pointer, manifest snapshot và screen rows trong
transaction. ZIP gốc không được copy sang public disk.

Worker cũ hoặc checksum cũ bị bỏ qua khi token/source không còn khớp. Lỗi input
được đánh `failed` với message bounded; lỗi vận hành được log chi tiết, dọn
artifact/staging và ném lại để queue retry. Hủy từ popup đánh dấu tracker
`cancelled`, giữ source processing ở `failed` với lý do hủy và dọn đúng thư mục
token. Enqueue lại cùng checksum dùng lại run active/ready nếu artifact còn; nếu
artifact ready bị mất sẽ tạo token mới để rebuild. Resource version/default và
package không bị thay đổi bởi worker.

## Kiểm chứng

- `ResourceTemplateProcessingTest`: **4 tests / 29 assertions**.
- Scoped resource template/parser/schema/invariant và AI queue regression:
  **71 tests / 673 assertions**.
- Frontend queue tests (`aiTaskQueue.test.js`, `aiTaskQueueItem.test.js`):
  **15 tests / 15 assertions**.
- ESLint pass cho các file queue đã sửa; `php -l` pass cho job/service/extractor.
- Fixture normalized xử lý được 18 screen và artifact vẫn giữ cây link tương đối.

## Phạm vi tiếp theo

Endpoint detail/process/rebuild và response `unavailable` khi artifact mất thuộc
TPL-06; ready/promote/rollback thuộc TPL-05; live preview thuộc TPL-08. TPL-04
chỉ cung cấp service/job và tracker để các task đó gọi lại cùng checksum/token.
