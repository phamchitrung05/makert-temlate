# Nghiệm thu Task 2 trên localhost — 2026-10-05

Người dùng chọn nghiệm thu localhost trước. Đã hoàn thiện sáu nhóm UI/API theo audit 12.23 của FIX 1, chạy regression, gọi model thật và kiểm MySQL/cache. Hai điều kiện chưa chốt: toolbar TinyMCE thật đang bị Tiny Cloud khóa theo origin; đánh giá chất lượng bằng người đọc/corpus đầy đủ. Không đánh dấu toàn Task 2 hoặc toàn FIX 1 đã đạt nghiệm thu.

## Chức năng đã kiểm

| Nhóm | Kết quả |
| --- | --- |
| Văn phong/brief | Select mẫu đang bật/mặc định, yêu cầu riêng và brief ở AI Content/dialog Post/regenerate. Regenerate bỏ profile/brief để giữ snapshot; override/default/brief rỗng rõ ràng. Browser chọn QA profile id 3/v4; tests kiểm default/tắt/version/conflict/race. |
| Nguồn | File HTML nguyên byte/encoding và raw HTML gửi backend, có preview trước gọi model. File Ovation người dùng cung cấp 349.2 kB lấy phần bài chính, 29 blocks/11 metadata ảnh; không còn chỉ lấy footer GPDKKD. Preview không gọi AI, đổi style/brief không làm mất nguồn đã đọc. |
| Taxonomy | Người dùng chọn Blog id 8/tag responsive id 1; giữ qua Save/partial regenerate/Apply vào Post #6. Backend trả nhãn `manual` cùng IDs; không dùng output/gợi ý taxonomy AI. Legacy yêu cầu xác nhận, 409 giữ bản sửa. |
| Media | Upload asset 9, chèn URL public/ID/alt/caption; Save/reload giữ ref. Regenerate content giữ figure một ảnh và caption ở vị trí placeholder; partial regenerate giữ HTML cha. Apply đồng bộ `post.content_images`; proof kiểm URL/ID/alt/caption/usage. Browser dùng editor HTML dự phòng do Tiny Cloud khóa. |
| Tiến độ | Analyze/Write/Edit/Validate và checkpoint/attempt/timestamps/usage thật/profile/gates hiện sau reload. Nhánh tóm tắt dùng một call, không dựng ba checkpoint; detail trả `response_diagnostics` allowlist cho usage nhánh ngắn. Lỗi nguồn/ref/quality không tạo candidate thành công. |
| Ai Prompt | List CRUD thủ công, edit/version, bật/tắt, default và delete confirmation đã nối. Browser tạo/sửa/bật-tắt QA profile id 3. File Ovation → chọn phần giới thiệu/tám tiện ích → một call phân tích thật → 13 dẫn chứng → xem/sửa/lưu profile id 4 → Add reset tên/nguồn/model/kết quả, List có mẫu mới. Các điểm/%/SEO/history chưa API vẫn ghi minh họa. |

Luồng bài thật dùng provider đã chọn trong Settings, model `gpt-6.1-sol`. Candidate QA tổng hợp đánh dấu rõ nội dung kiểm thử, không coi là một bài báo nguồn thật hay kết quả chấm văn phong. Parent `01a107f5-28bc-70f8-95ed-74e7e980c50c`; content child `01a1081f-5608-73fb-82ee-4ebe782d0310` chạy ba bước; excerpt child `01a1082f-58eb-710a-a3b3-ac34a7ba368d` chỉ một call. Profile snapshot đều id 3/v4; parent giữ riêng.

Post **#6** là draft, `published_at=null`, actor 1 khớp actor run, Blog/tag và ảnh còn nguyên. Post #5 cũng là bản QA draft trước khi phát hiện lỗi nhãn taxonomy; không dùng nó làm bằng chứng taxonomy giữ được. Các mẫu/bài QA được đặt tên rõ; hai mẫu do người dùng có sẵn không bị sửa.

[Proof Post/taxonomy/media](task2-quality/browser-post-proof.json). [Ảnh Post draft](TASK2_POST_DRAFT_2026-10-05.png), [phân tích văn phong thật](TASK2_PROFILE_ANALYSIS_2026-10-05.png), [List profile](TASK2_PROMPT_LIST_2026-10-05.png), [AI Content mobile](TASK2_CONTENT_MOBILE_2026-10-05.png), [báo cáo nhánh một lượt](TASK2_SHORT_RUN_REPORT_2026-10-05.png).

Browser kiểm viewport 390 × 844: form nguồn/văn phong/brief/taxonomy và các nút vẫn trong chiều rộng trang; datatable/tabs cuộn trong vùng riêng. Đã trả viewport về kích thước mặc định sau kiểm tra.

## Lỗi tìm được và đã sửa

- Preview bị xóa khi chỉ đổi style/brief: watcher so sánh các giá trị nguồn/encoding/target thực, không so object getter mới.
- Dialog List đọc `action.kind` sau khi đóng trong exit transition: guard trạng thái đã đóng; test public UI kiểm lại.
- Gate số liệu ghép hai ô bảng thành một số, hoặc chặn 2090 khi bài viết 2.090/2,090: giữ ranh giới block/cell và cho phép nhóm hàng nghìn của integer. Phiên bản `2.10` khác `2.1`, không normalize chúng thành cùng giá trị.
- Analyzer trích markup thay vì text: prompt **2.1** nêu exact contiguous excerpt của một `block.text`; schema request 2.0 giữ nguyên cho checkpoint cũ. Lý do lỗi refs được giữ qua diagnostics redaction và hiện an toàn ở UI.
- Caption mất khi regenerate: snapshot/restoration giữ toàn bộ figure chỉ có một ảnh. Figure nhiều ảnh vẫn cần editor rà bố cục/chú thích.
- IDs taxonomy thủ công thiếu nhãn `manual` trong draft mới: bổ sung nhãn từ backend; regression và browser Save/regenerate/Apply giữ đúng IDs.
- Candidate đã Apply còn cho nhập dữ liệu không lưu được: khóa fields/editor; test UI kiểm readonly.
- Checklist alt đếm cả inline HTML và gallery usage của cùng asset: loại gallery IDs đã có trong HTML, kiểm từng vị trí inline và vẫn giữ ảnh gallery legacy/thumbnail độc lập. Browser Post #6 báo **1/1**, thay cho 1/2 trước sửa.
- Metadata nhánh một lượt bị ẩn khi adapter chỉ có `reported_model`, không có `model`: report đọc cả hai key và usage thực. Nhánh excerpt QA đã lưu **1063 input + 128 output = 1191 token**, không tạo checkpoint ba bước giả; [proof diagnostics allowlist](task2-quality/short-run-diagnostics.json) và screenshot sau reload đã đối chiếu trên browser.

## Kiểm queue/MySQL/cache

Local dùng MySQL, queue database và cache database. Worker cũ thoát qua `queue:restart`; worker hiện tại PID 23280 dùng `--timeout=1950 --tries=1 --sleep=1`. Job giới hạn tối đa 1920 giây, lease `retry_after` ít nhất 2000 giây. Worker gọi model thật đã hoàn thành ba bước, nhánh ngắn và analysis văn phong.

Probe dùng fixture/provider fake, gọi job/service thật trên database/cache thật, không tính là benchmark văn phong hoặc call model:

- Hai process PHP gọi `handle()` cùng UUID: chỉ một service execution và một kết quả ready. [Claim proof](task2-quality/mysql-claim.json).
- Hủy giữa lúc service xử lý: completion giữ `cancelled`, không lưu result ready. [Cancel proof](task2-quality/mysql-cancel.json).
- Writer lỗi sau Analyze đã checkpoint; resume chỉ gọi Writer/Editor, giữ đủ ba checkpoint. [Resume proof](task2-quality/mysql-resume.json).

Fixtures probe đã xóa đúng UUID/hash QA sau khi lấy proof. Không chạy cleanup trên dữ liệu người dùng để nghiệm thu retention; retention/quyền/timeout/asset cleanup được kiểm bằng tests cô lập. Windows thiếu pcntl nên chưa kiểm cưỡng chế hard-kill process theo timeout như Linux; HTTP budget/checkpoint/cancel đã kiểm. Supervisor/SQS/Redis/Reverb/VPS vẫn thuộc FIX 1 mục 2C, không được ghi là đã kiểm trên production.

## Regression và build

- Toàn backend: **299 tests / 2078 assertions** đạt với PHP GD bật cho process test. Sau bổ sung diagnostics nhánh ngắn: **14 tests API / 79 assertions** đạt, gồm kiểm usage public và không lộ secrets/intermediate.
- Toàn frontend sau các sửa cuối: **42 files / 289 tests** đạt. Covers raw-file/FormData, preview invalidation/race, profile/default/brief, manual taxonomy, version/legacy Apply, transition/readOnly, upload/save blocker, image position/alt/caption, gallery dedup và metadata `reported_model` nhánh ngắn.
- Scoped ESLint cho các file Task 2, Pint cho PHP đã sửa và production build kiểm riêng. Không dùng lint auto-fix toàn repo hoặc gửi AI từ automated tests.
- Warning build asset `section-title-icon.png` là warning có sẵn. Tiny Cloud errors ở browser là lỗi cấu hình editor thật; không gộp chúng thành lỗi Vue đã sửa.

## Chất lượng model thật và phần còn chờ

Corpus v1: 25 ca/24 nguồn, hash/selection/license/provenance giữ nguyên. Pilot prompt 2.1 gồm Q01/Q02/Q07/Q13/Q18, hai nhánh B/C: **19 call thực, 97.837 token; 6/10 output qua gate, 4/10 bị chặn**. B ready 4/5, C ready 2/5. Ba ca thiếu/đổi link nguồn, một ca Writer dùng fact ID không có trong Analyze. Không đổi nhãn B thành baseline A, không tự retry để bỏ kết quả xấu, không suy “hay hơn” từ số token hay gate.

[Gói chấm mù năm ca](task2-quality/pilot-2026-10-05-five-cases/review/index.html), [CSV người đọc 1](task2-quality/pilot-2026-10-05-five-cases/review/reviewer-1.csv), [CSV người đọc 2](task2-quality/pilot-2026-10-05-five-cases/review/reviewer-2.csv). Có rubric riêng facts/coverage, năm tiêu chí văn phong và công biên tập. Baseline A chỉ phục hồi được config Git HEAD, chưa có toàn bộ request/Settings cũ; vẫn ghi unavailable. Chi phí tiền chưa có bảng giá xác nhận nên để null.

**Chưa nghiệm thu chất lượng:** corpus chủ yếu docs kỹ thuật, cần bổ sung coverage, facts chuẩn và hai người đọc chấm theo FIX 1 12.15. Pilot còn lỗi, nên chưa chốt rollout hoặc khẳng định ba bước tốt hơn một lượt. Các output lỗi là dữ liệu để tuning tiếp sau review, không được che bằng fallback hoặc tự bỏ gate.

**Chưa nghiệm thu toolbar TinyMCE thật:** Tiny Cloud từ chối `http://127.0.0.1:8000`; editor dự phòng và API/media đã kiểm. Cần origin được phép hoặc license self-hosted do owner chọn. Giữ cấu hình/license hiện tại trong lúc chờ, không coi mocks/composable tests là bằng chứng toolbar thực đã chạy.
