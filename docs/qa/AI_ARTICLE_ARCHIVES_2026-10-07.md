# Nghiệm thu giai đoạn 1 — kho bài AI gốc

**Ngày:** 07/10/2026, Asia/Bangkok. **Kết quả:** DONE G1-01–G1-06.

**Task:** [Hệ thống tự đánh giá định kỳ](../plans/HE_THONG_TU_DANH_GIA_DINH_KY.md). Q-01 tổng vẫn IN PROGRESS; evaluator, lịch và báo cáo chưa triển khai.

## Thay đổi và contract v1

- [Migration mới](../../database/migrations/2026_10_07_052817_create_ai_article_archives_and_add_archive_lifecycle_to_ai_imports.php) tạo `ai_article_archives`, thêm `archive_version`, `generation_no`, `archive_pending_json` vào `ai_imports`. Migration cũ không sửa. Kho không có FK/cascade theo run/Post/user; rollback migration mới sẽ mất kho/checkpoint, production nên sửa bằng migration tiến.
- [Model](../../app/Models/AiArticleArchive.php), [snapshot builder](../../app/Services/Ai/Content/Archives/AiArticleSnapshot.php), [service](../../app/Services/Ai/Content/Archives/AiArticleArchiveService.php) giữ bài/nguồn/brief/profile dùng để tạo bài và canonical SHA-256. Metadata quyết định/Apply ở `lifecycle_json` và `applied_target_id`, tách khỏi payload/hash bất biến.
- Post text mới **đã được duyệt/Apply** là target đầu tiên ghi archive. Bảng đã giữ identity đa model bằng `target_type` + `applied_target_id`; màn hình `AI Approved` đọc tất cả archive approved có `has_generated_content=true` và hiển thị loại target/ID. Candidate chưa duyệt, bị từ chối, hủy hoặc hết hạn chỉ còn trong `ai_imports` tạm thời và bị dọn theo retention 2 ngày. Resource/Sound chỉ xuất hiện sau khi có adapter/review workflow tương ứng (AI-04). `field_origins` phân biệt AI mới, kế thừa và nguồn/deterministic; `has_generated_content` chỉ true khi content mới do AI tạo. Đổi title/SEO riêng không tính thành bài content AI mới.
- Khóa unique `(run_id, generation_no)`: regenerate có UUID mới; retry do người dùng tăng generation dưới lock và bỏ checkpoint chưa duyệt của lần trước; queue retry giữ generation. Worker cũ không xử lý generation mới; bản đã duyệt không được retry. Lock cache hỗ trợ điều phối; unique key và transaction là lớp bảo vệ DB.
- [Worker](../../app/Jobs/ProcessAiImportJob.php) commit checkpoint hoàn tất đã sanitize trước khi đánh dấu candidate `ready`; checkpoint chỉ là dữ liệu tạm trong `ai_imports`. Lỗi checkpoint có mã riêng và truyền queue; ghi lặp dùng bản đầu, khác hash chặn conflict. Không giữ transaction qua HTTP provider.
- Chỉ transaction approve/Apply mới chuyển checkpoint thành archive bền vững cùng Post/provenance/decision/audit. Ví dụ ba bản trong một session, chọn bản 2: chỉ bản 2 được archive; bản 1/3 hết hạn cùng danh sách. Unapproved/rejected/failed/cancelled/expired không tạo kho; archive của bản đã duyệt vẫn tồn tại sau khi run hết hạn.
- Prompt/schema/hash/usage của các checkpoint thực sự dùng được lưu qua trace pipeline. `reused_checkpoint` phân biệt việc dùng lại artifact; usage của checkpoint gốc không phải một khoản token mới. Thiếu usage không tự điền 0 hoặc suy đoán chi phí.
- Snapshot/recovery lọc credential, connection/header/raw response; URL bỏ userinfo/query xác thực, hash tính trên bản đã redact. JSON nguồn/bài/context không tự serialize qua model ra API. API read-only và màn hình `AI Approved` đã có quyền `posts.manage`, filter ngày/tiêu đề, phân trang và preview snapshot đã sanitize; chưa có mutation archive.
- Kho giữ HTML/text/ID/alt/URL tham khảo; vòng đời file ảnh thuộc Media Library hiện có. Lưu snapshot không đồng nghĩa giữ mọi binary ảnh tạm lâu dài.

## Kiểm thử

[AiArticleArchiveTest](../../tests/Feature/AiArticleArchiveTest.php) gồm **33 test**; SQLite in-memory và storage/queue/provider fake, chặn HTTP lạ. [ArchiveArticleProvider](../../tests/Fixtures/ArchiveArticleProvider.php) kiểm pipeline ba bước thật bằng DTO giả; không có request AI tính phí.

| Nhóm | Bằng chứng đã đạt |
| --- | --- |
| Nội dung/nguồn | Chỉ bản approved có original trước edit, source block IDs, code/table, brief/profile/model và version/hash/usage pipeline |
| Idempotency | Checkpoint lặp giữ bản đầu; writer khác payload bị conflict; approve lặp không tạo archive thứ hai; insert trùng bị DB unique chặn |
| Bất biến | Eloquent chặn sửa payload; archive giữ original trước edit; worker redelivery không ghi đè bản sửa tay; cleanup phát hiện raw SQL làm sai hash |
| Link và lifecycle | Approve liên kết Post draft và archive; reject không có archive; lỗi Post/archive rollback quyết định; archive vẫn đọc được sau xóa Post/run |
| Retry/cancel/expiry | Retry tạo generation mới nhưng chưa archive; job cũ bị bỏ qua; process lock chặn retry đang tranh worker; terminal không có archive giả |
| Lỗi và phục hồi | Archive insert hoặc audit lỗi rollback Post/provenance/decision/archive; checkpoint còn để duyệt lại; worker phục hồi ready giữ trace và không gọi provider |
| Cleanup | Tạo rồi regenerate hai lần thật trong cùng session, chọn bản 2; dọn cả ba run chỉ còn kho bản 2. Candidate chưa chọn có checkpoint lỗi cũng dọn được; approved thiếu/hỏng original phải giữ run |
| Loại bản | AI original/mixed có content AI mới; title-only có content kế thừa không bị tính thành bài mới; deterministic không giả AI; image/target khác ngoài phạm vi |
| Legacy và bảo mật | Command chỉ xử lý approved; dry-run không write, bounded/UUID validation, legacy không đoán output cũ; known secret/header/URL auth được redact, URL thường giữ query/fragment/encoding, checkpoint private |

Lượt regression trong đợt điều chỉnh: **44 test, 384 assertions** đạt (archive phiên bản trước khi bổ sung kiểm thử cuối, workspace, candidate và import). Các fixture ready dựng tay được đánh dấu legacy; duyệt legacy giữ metadata/content null, không giả bản AI gốc từ draft đã sửa. Luồng thật có checkpoint được kiểm riêng.

**Toàn bộ backend sau điều chỉnh:** `php vendor/bin/phpunit --no-progress` — **472 test, 3.314 assertions đạt**, 165,704 giây. Bao gồm 33 test kho hiện tại và toàn bộ regression backend. PHP syntax/Pint các file sửa đạt; whitespace/link tài liệu được kiểm. Frontend không thay đổi trong đợt điều chỉnh, không chạy lại build frontend. Không gọi provider thật, không đổi prompt/model hoặc bộ 19 bài/điểm lịch sử.

Kiểm concurrent ở đợt này gồm tranh khóa worker, writer khác payload và unique constraint trên SQLite. Stress nhiều process/worker và DB của host thực sẽ kiểm ở giai đoạn 6; không nhận đây là nghiệm thu production.

Rà comment theo [quy ước phát triển](../architecture/DEVELOPMENT_GUIDELINES.md#11-chuẩn-comment-bắt-buộc-cho-developer-và-ai): kiểm **23 file PHP/272 khai báo class-method**, bổ sung comment trong **21 file**. Header class nằm sát khai báo, liệt kê hàm và context; method đủ chức năng, INPUT/OUTPUT/SIDE EFFECT/EXCEPTION-TRANSACTION. Đối chiếu PHP token trước/sau xác nhận phần thực thi không đổi, annotation PHPUnit giữ nguyên. Kiểm cú pháp/Pint đạt; chạy lại kho **33 test/327 assertions đạt**. Lượt backend 472/3.314 phía trên là lượt trước thay đổi comment.

Đợt giai đoạn 1 ban đầu đã kiểm cú pháp/Pint 23 file PHP; đợt điều chỉnh kiểm lại **8 file PHP** có sửa, đều đạt. **78 link local** trong 7 tài liệu liên quan không hỏng, không có whitespace thừa. Đối chiếu SHA-256 của **280 file QA/corpus/ảnh** trong manifest nhóm docs không có thay đổi hoặc thiếu file. `ai-review/review.json` đã đổi đường dẫn rubric ở đợt nhóm docs trước, được kiểm riêng là chỉ thay đường dẫn; bộ 19 bài và điểm lịch sử giữ nguyên.

## Database local và dữ liệu cũ

Migration đã chạy trên SQLite local, batch **15**. Kiểm số dòng và SHA-256 của các cột cũ trước/sau:

| Bảng | Số dòng | Các cột cũ |
| --- | ---: | --- |
| posts | 4 | Không đổi |
| ai_imports | 20 | Không đổi |
| ai_provenances | 5 | Không đổi |
| ai_import_steps | 0 | Không đổi |

Bảng archive và ba cột lifecycle đã có, đợt điều chỉnh không thêm migration. Kho local hiện **0 bản**: không auto backfill candidate cũ và không lưu candidate chưa duyệt. Dry-run mới theo bộ lọc approved kiểm kê **1 run legacy**, không ghi dữ liệu. Đây là run DB local, không phải bộ 19 bài corpus/điểm AI ở QA.

Không đăng ký lịch xóa archive trong giai đoạn 1. Run tạm vẫn mặc định 2 ngày. Retention kho, evaluator và ngân sách được cấu hình ở các giai đoạn sau. Trên host cần migrate và nạp lại worker bằng cơ chế quản lý process của host.

## Phục hồi/kiểm kê bằng command

[ArchiveAiArticlesCommand](../../app/Console/Commands/ArchiveAiArticlesCommand.php):

```powershell
php artisan ai-articles:archive --run-id=<UUID> --dry-run
php artisan ai-articles:archive --run-id=<UUID>
php artisan ai-articles:archive --include-legacy --limit=100 --dry-run
```

`--run-id` có thể lặp; limit từ 1 đến 1000. Command không enqueue, không gọi AI hoặc tạo lại bài. Command chỉ chọn run đã approved; checkpoint approved còn sót có thể phục hồi archive mà không gọi provider. Candidate chưa duyệt không được archive. Legacy không đủ bằng chứng giữ `draft_snapshot_json=null`, nhãn `legacy_unverified` và lý do; kiểm kê legacy chỉ ghi khi bật `--include-legacy` ngoài dry-run.

[CleanupAiImportsCommand](../../app/Console/Commands/CleanupAiImportsCommand.php) đối chiếu/cập nhật lifecycle kho approved trước khi dọn run, có thể phục hồi checkpoint đã approved còn sót. Candidate chưa duyệt được xóa cùng checkpoint theo retention mà không tạo archive. Bản approved v1 thiếu/hỏng original trả exit code 1 và ghi số giữ lại theo mã; worker bận hoặc run vừa đổi được bỏ qua an toàn. Không lấy candidate đã sửa làm original để vượt guard. Duyệt legacy tạo Post bình thường nhưng archive đánh dấu `legacy_unverified`, content null; cleanup không tự backfill legacy lịch sử.

## Dữ liệu bàn giao cho giai đoạn 2

Evaluator đọc `AiArticleArchive` trực tiếp, chọn bằng `has_generated_content` và thời điểm tạo; xét thêm origin/field origins và nguồn đủ/thiếu. Lấy `draft_snapshot_json`, `source_snapshot_json`, `context_snapshot_json`, `content_hash`, `source_hash`, `payload_hash`, `snapshot_version` để chốt đầu vào chấm. Trạng thái biên tập chỉ là metadata, không thay nội dung đã lưu. Run/Post không phải dependency bắt buộc.
