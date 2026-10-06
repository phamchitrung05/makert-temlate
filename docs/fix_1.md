# FIX 1 — Ổn định AI Content và quy trình duyệt bài

**Ngày lập:** 2026-10-02
**Trạng thái 2026-10-06:** Task 1 `DONE`; Task 2 `DONE` kỹ thuật theo 12.35. Workflow biên tập/duyệt trên `ai_imports` đã `DONE` tại 12.36; thumbnail sinh bằng AI đã `DONE` tại 12.37; chuẩn hóa Settings/QA đã xong tại 9.9/12.40; upload logo/favicon đã xong tại 12.42. Kho `ai_content_drafts` và lưu dài hạn tạm hoãn theo chủ dự án. Hai người đọc chấm chất lượng vẫn là đợt riêng; chưa có điểm hoặc kết luận rollout. Artifacts/status study lịch sử và QA Tiny Cloud tại 12.35 được giữ. Toàn FIX 1 vẫn `IN PROGRESS`: Settings mở rộng và các đợt chất lượng còn chờ; Realtime/VPS được bỏ qua trong đợt hiện tại. Phần Ai Prompt chưa API giữ minh họa rõ nhãn.
**Mục tiêu:** Gom các lỗi đã xác nhận trong lúc test, chốt một lần rồi triển khai đồng bộ.
**Điều chỉnh phạm vi:** Bỏ nhóm Provider/9Router khỏi công việc FIX 1 theo yêu cầu ngày 2026-10-03.

**Quy ước gọi tên ảnh theo chủ dự án:**

- **Ảnh trong content:** ảnh minh họa cho một đoạn hoặc phần của bài viết,
  đặt bên trong nội dung; còn gọi là ảnh inline.
- **Thumbnail của Post:** ảnh đại diện cho toàn bộ Post, nằm ở trường
  Thumbnail/Featured Image.

**Làm rõ thumbnail, 2026-10-06:** `DONE` tại 12.37 là sinh **thumbnail của Post**
tại trang **AI Content**, rồi gắn vào Featured Image của Post nháp khi
duyệt/Apply. Add/Edit Post đã có
nút tạo ảnh thủ công bằng prompt/tiêu đề; luồng đọc nội dung Post đã biên tập
để xây ý tưởng và sinh thumbnail phù hợp chưa triển khai, tạm để sau theo chủ dự án.

**Mốc hiện tại:** UX tới 12.29, đối chiếu tiến độ tại 12.30; chất lượng bài
bước 1–2 tại 12.31, bước 3–5 tại 12.32; gỡ B và bắt buộc C tại 12.33;
đối chiếu phần làm tiếp tại 12.34; hoàn tất kỹ thuật Task 2 tại 12.35. Các mốc
12.17–12.23 là báo cáo lịch sử, phần còn thiếu khi đó được cập nhật bởi 12.24.
Từ 12.27, tạo nội dung Post tập trung ở AI Content; nút/dialog tạo bài AI trong
Post đã gỡ. Báo cáo 12.17–12.34 giữ hiện trạng lịch sử; mốc 12.35 là tiến độ
hiện tại. Toàn FIX 1 vẫn `IN PROGRESS`.

## 1. Nguyên tắc đã thống nhất

- AI chỉ tạo candidate/bản nháp, không tự động xuất bản công khai.
- `ai_imports` theo dõi tác vụ kỹ thuật: queue, provider, tiến độ và lỗi.
- Tạo lớp biên tập riêng `ai_content_drafts`: chờ duyệt, đã duyệt, từ chối.
- Khi duyệt, mặc định tạo `posts.status = draft`; editor kiểm tra lần cuối rồi Publish.
- Queue là bắt buộc. Reverb/Echo dùng cho realtime khi VPS có process manager.
- Polling vẫn tồn tại làm phương án dự phòng khi WebSocket mất kết nối.
- Nội dung URL, text và file HTML luôn là dữ liệu không tin cậy; phải extract và sanitize.
- Danh mục và tag do người dùng chọn thủ công; AI không tạo, đề xuất hoặc tự ánh xạ taxonomy trong luồng mới.
- Tạo nội dung đầy đủ theo Analyze + Plan → Write → Edit; mẫu văn phong do người dùng chọn hoặc dùng mặc định website. Chi tiết triển khai dự kiến ở task 2, mục 12.

## 2. Các vấn đề cần sửa

### A. Provider và structured output

Mục này kiểm tra kết quả AI trước khi báo tạo thành công. Ví dụ, người dùng chọn AI tạo tiêu đề, nội dung và SEO nhưng AI chỉ trả tiêu đề hoặc nội dung bị cắt giữa chừng: hệ thống báo lỗi rõ ràng để tạo lại. Các field không chọn vẫn giữ dữ liệu nguồn hoặc giá trị nhập tay. Task 1 đã triển khai kiểm field theo nhóm đã chọn, xác nhận AI kết thúc hợp lệ, chặn fallback che lỗi và lưu diagnostics an toàn; regression và browser smoke local đăng nhập đã đạt với output được kiểm soát.

Chi tiết triển khai và bằng chứng kiểm thử của task này ở mục 11; các checkbox dưới đây phản ánh hiện trạng code.

- [x] Normalize response của adapter; JSON lỗi, refusal, sai kiểu dữ liệu và timeout có xử lý lỗi rõ ràng.
- [x] Kiểm tra chặt `finish_reason`/`finishReason`, `tool_calls`/`function_call` và `functionCall` trước parse JSON; OpenAI yêu cầu `stop`, Gemini yêu cầu `STOP`.
- [x] Request tạo nội dung hiện không gửi `tools`.
- [x] Bổ sung `tool_choice: none` cho provider hỗ trợ.
- [ ] Đánh giá temperature `0` cho structured JSON ở đợt tuning riêng. Task 1 giữ temperature từ Settings/snapshot, mặc định hiện tại `0.2`.
- [x] Kiểm tra field bắt buộc theo nhóm đầu ra được chọn; field văn bản được yêu cầu phải có giá trị hợp lệ, không rỗng. Khi không chọn AI tạo title/content, draft giữ nguồn hoặc tiêu đề nhập tay.
- [x] Finish reason không hợp lệ, `tool_calls`, content rỗng hoặc thiếu field của nhóm đã chọn fail với mã lỗi rõ ràng.
- [x] Không thay output thiếu/sai của nhóm đã chọn bằng fallback rồi báo `ready`; vẫn giữ dữ liệu nguồn cho nhóm không chọn và chế độ deterministic được chọn rõ ràng.
- [x] Lưu provider/model, prompt/schema version và `requested_fields` trong metadata tác vụ.
- [x] Lưu response ID, model do provider báo, finish reason, usage/token, `returned_fields` và lỗi validation an toàn trong `source_meta_json.ai_response`; API chỉ trả lỗi cần hiển thị.
- [x] Snapshot/metadata và lỗi công khai không chứa API key hoặc header xác thực.
- [ ] Nếu bật lưu raw response để debug: giới hạn kích thước/thời hạn, redaction và cấu hình bật/tắt riêng; chờ đợt debug riêng, ngoài task 1.

### B. Prompt và chất lượng nội dung

Task 2 đã hoàn tất kỹ thuật tại mục 12.35. Backend, Ai Prompt List/Add/CRUD và các form tạo bài/biên tập đã nối API. Toolbar Tiny Cloud thật đã kiểm trên localhost; chất lượng văn phong/facts bằng người đọc thuộc đợt riêng theo quyết định của chủ dự án.

- [x] Prompt yêu cầu giữ sự thật/code, coi nguồn là dữ liệu không tin cậy và dùng hướng dẫn theo target.
- [x] Tích hợp Analyze + Plan → Write → Edit cho tác vụ tạo `content`; kiểm source anchors, facts quan trọng, code, số liệu và liên kết. Semantic grounding toàn diện vẫn cần người biên tập kiểm.
- [x] Tạo brief và prompt riêng từng bước; bố cục phù hợp nguồn, không áp một outline cố định.
- [x] Bỏ taxonomy khỏi prompt/schema/alias/nhóm đầu ra AI; danh mục/tag chỉ nhận lựa chọn thủ công và được backend kiểm tra.
- [x] Backend CRUD/version/enable/options/default mẫu văn phong và nhận brief/yêu cầu riêng qua API.
- [x] Nối select văn phong/mặc định, brief/yêu cầu riêng ở AI Content và regenerate; inheritance/override rõ ràng. Dialog tạo bài AI trong Post đã gỡ tại 12.27.
- [x] Quản lý mẫu từ List: sửa, xóa có xác nhận, bật/tắt, tạo thủ công và default; version conflict giữ bản sửa. Xem 12.24.
- [x] Tạo mục `Ai Prompt` trong Systerm AI tại `/admin/ai/prompt`; custom theo theme project và nối API hiện có, phần chưa API có nhãn minh họa.
- [x] API phân tích bài tham khảo qua queue → trả kết quả/evidence → lưu profile khi người dùng POST duyệt.
- [x] Nối API dán bài/URL/file, xem/sửa/lưu mẫu hiện tại — tests/HTTP mock, lint/build và browser UI tại 12.19; model thật đã chạy tại 12.24. Phần chưa API giữ minh họa tĩnh; nghiệm thu chất lượng bằng người đọc còn mở.
- [x] Tách Ai Prompt → List/Add; List dùng datatable tìm tên/phân trang/xem prompt đã lưu, Add giữ trang phân tích hiện tại; báo cáo 12.21.
- [x] Add tự reset sau khi lưu thành công, có nút Văn phong mới và giữ bản khi lưu lỗi/chưa xác định; báo cáo 12.22.
- [x] Nối hook MediaLibrary/upload và bảo toàn ID/URL/alt/caption qua save/regenerate/Apply. Toolbar Tiny Cloud thật đã kiểm tại 12.35; regenerate giữ ảnh có regression và bằng chứng browser/API kế thừa tại 12.24.
- [x] Thêm gate output mới trước merge: exact-copy, similarity warning, language detector vi/en có trạng thái chưa rõ, code/link/số liệu và source references; không coi lexical metric là điểm văn phong hoặc fact verification bên ngoài.
- [x] Backend lưu source/profile/prompt/schema/parent snapshot, checkpoint, timeout/lease, hủy/retry và safe step metadata.
- [x] Hiển thị Analyze/Write/Edit/Validate, checkpoint/gates/profile/usage thật sau reload; nhánh ngắn dùng diagnostics một lượt. Xem 12.24.
- [ ] Nghiệm thu chất lượng thực tế của C trên bộ bài mẫu: facts, diễn đạt, công biên tập, thời gian và token. B đã gỡ tại 12.33; so sánh B/C đã chạy chỉ giữ lịch sử, không yêu cầu chạy mới B.
- [x] Cho phép tạo lại toàn bài hoặc từng nhóm field sau khi sửa contract regenerate.

### C. Timeout, queue và realtime

- [x] Thời gian chờ riêng trong `ai_providers.request_timeout` (5–600 giây); provider cũ nhận 120 giây, provider mới lấy default từ config.
- [x] Luồng một lượt hiện tại: job timeout tối thiểu 180 giây và đủ HTTP + 120 giây; queue `retry_after` tối thiểu 900 giây.
- [x] Task 2: budget ba lượt HTTP tối đa 1920 giây, checkpoint, cancellation và queue `retry_after` tối thiểu 2000 giây. Cấu hình worker/SQS thực tế còn phải kiểm trên môi trường triển khai.
- [ ] Rà lại worker production để `--timeout < retry_after` và đủ lớn hơn request timeout.
- [x] Sửa frontend polling sang backoff thay vì gọi mỗi 1,2 giây liên tục.
- [ ] Broadcast event khi queued/processing/ready/failed.
- [ ] Tích hợp Laravel Echo + Reverb; polling fallback khi WebSocket mất kết nối.
- [ ] Cấu hình Supervisor/systemd/Docker cho `queue:work` và `reverb:start` trên VPS.

### D. Nguồn bài viết

- [x] Backend extractor giữ phần bài trong `<form>`, chỉ loại các input/control.
- [x] Backend chọn `main/section/article` theo mật độ nội dung; frontend upload HTML nguyên bản để người dùng nối sau.
- [x] Backend loại script/style/nav/footer/iframe và sanitize HTML theo allowlist, giữ heading/list/code/table.
- [x] Gửi raw HTML/file tới extractor backend, lọc layout/boilerplate và bảo toàn nội dung trong semantic container; preview cho kiểm vùng chọn trước generation.
- [x] Thêm file HTML, raw HTML và nội dung paste khi website chặn bot; file giữ byte/encoding để backend extract, không flatten trước khi gửi.
- [x] File giới hạn `.html/.htm`, tối đa 5 MB; text tối đa 200.000 ký tự. Extract bằng DOM tách rời, không chèn nguồn vào trang để thực thi.
- [x] URL fetcher kiểm tra Content-Type khi có header và giới hạn byte tải về.
- [x] Backend validation MIME/encoding/byte budget cho raw HTML/file HTML; UTF-8 mặc định và encoding explicit.
- [x] Nối raw HTML/file/encoding và preview ở AI Content cùng dialog Post; đổi văn phong/brief không làm mất preview nguồn. Xem 12.24.
- [x] Snapshot block IDs, whitespace code/table/link/quote/metadata ảnh; resume nguồn cũ, refresh_source explicit và UI source-preview trước generation.

### E. Regenerate và frontend contract

- [x] Tách payload builder cho create và regenerate.
- [x] Contract generation có `title`, `excerpt`, `content`, `seo`, `thumbnail`; `taxonomy` chỉ thuộc Apply thủ công.
- [x] Task 2 bỏ taxonomy khỏi nhóm AI có thể chọn và alias legacy ở frontend/backend; giữ taxonomy thủ công của Post và kiểm snapshot cũ. Xem 12.9, tests tại 12.17.
- [x] Chọn/xác nhận category/tag thủ công trong create/editor/regenerate/Apply; draft trả nhãn manual cùng IDs để không mất lựa chọn khi Save. Xem 12.24.
- [x] Không gửi `prompt_key`, provider, model hoặc optional field khi giá trị rỗng/null.
- [x] Backend tương thích payload cũ có kiểm soát và chỉ coi giá trị đã điền là override.
- [x] Giữ parent candidate, tạo child run mới và theo dõi đúng `job_id`.

## 3. Trang quản lý bài viết AI

Luồng hiện tại (12.36–12.37):

```text
URL / text / HTML file
        ↓
ai_imports: queued → processing → ready / failed
        ↓
ai_imports.source_meta_json.editorial: pending_review
        ↓
approve → posts.status=draft → editor Publish
reject  → giữ lịch sử và lý do từ chối
```

Trang `AI Content` dùng candidate và metadata biên tập trong `ai_imports` theo 12.36. Kho `AI Drafts` dài hạn là hướng dự kiến, đã tạm hoãn theo chủ dự án. Tiến độ hiện tại:

- [x] Danh sách hiển thị đang xử lý/chờ duyệt/đã áp dụng/lỗi/hủy/hết hạn; chờ duyệt và đã áp dụng hiện là trạng thái suy ra từ tác vụ.
- [ ] Thêm kho `ai_content_drafts` và lưu dài hạn độc lập retention — tạm hoãn. Trạng thái duyệt/từ chối, người duyệt và lịch sử/lý do đã có trên `ai_imports` tại 12.36.
- [x] Giao diện so sánh nguồn snapshot/kết quả, người duyệt, lịch sử và lý do tại 12.36; lưu nguồn dài hạn độc lập retention vẫn tạm hoãn.
- [x] Chỉnh sửa candidate trước khi duyệt.
- [x] Tạo lại toàn bài hoặc từng phần.
- [x] API apply ép Post `status=draft` và lưu provenance/liên kết với tác vụ AI.
- [x] Nối nút duyệt/apply và từ chối trên trang AI Content vào workflow biên tập trên bản ghi hiện có tại 12.36.
- [x] Danh sách hiển thị thời gian tạo.
- [x] Hiển thị provider/model/prompt version và lỗi từng tác vụ an toàn từ metadata, kể cả sau reload; lưu và hiển thị usage/token qua báo cáo checkpoint/diagnostics. Bằng chứng 12.24 và workflow biên tập tại 12.36.
- [x] Không trộn trạng thái kỹ thuật với trạng thái biên tập; metadata `editorial` độc lập lifecycle job tại 12.36.

## 4. Thứ tự triển khai phần còn lại sau audit

1. Task 1 parser/validator đã hoàn thành; giữ các kiểm tra đó khi mở rộng provider contract sang schema theo từng nhiệm vụ.
2. Triển khai task 2 theo các đợt tại 12.14: taxonomy thủ công, nguồn/contract, profile, pipeline ba bước, checkpoint/gate, TinyMCE và đánh giá chất lượng.
3. Hoàn thiện extractor, validation MIME/encoding và preview nguồn trong cùng đợt nguồn của task 2; HTML file input và regenerate contract đã có.
4. Kho `ai_content_drafts` và lưu dài hạn tạm hoãn theo chủ dự án; API approve/reject đã xong trên `ai_imports` tại 12.36.
5. UI duyệt/từ chối, so sánh nguồn/kết quả và lịch sử đã xong tại 12.36; đánh giá similarity/chất lượng người đọc thuộc đợt riêng.
6. Thumbnail generate trong luồng AI Content → Post đã xong tại 12.37 (xem 9.7).
7. Thêm event broadcast, Echo/Reverb và polling fallback; cấu hình process manager trên VPS, smoke test queue/reboot/reconnect.
8. Nối chín tab Settings còn lại và hoàn thành browser QA theo mục 9.

## 5. Tiêu chí hoàn thành

- AI không được báo `ready` nếu thiếu/sai field bắt buộc của nhóm đầu ra đã chọn; title/content của nhóm không chọn có thể giữ từ nguồn hoặc candidate cha.
- Response `tool_calls`, sai JSON, sai schema và timeout có mã lỗi riêng.
- Bài exact-copy không được coi là kết quả AI hợp lệ.
- Task 2 có brief riêng, profile được snapshot, ba bước cho nội dung đầy đủ, bảo toàn facts/code/ảnh và taxonomy thủ công; tiêu chí chi tiết tại 12.15.
- URL, text và file HTML đều tạo được draft hoặc báo lỗi nguồn rõ ràng.
- Regenerate tạo child candidate và không trả 422 do sai tên field.
- Người dùng xem, sửa, duyệt hoặc từ chối bài AI trên trang riêng.
- Duyệt chỉ tạo Post draft; không tự publish công khai.
- Realtime hoạt động trên VPS; mất Reverb vẫn xem được tiến độ qua polling backoff.
- Có test cho provider response, extractor, queue lifecycle, approval và frontend contract.

## 6. Các điểm cần chốt khi duyệt plan

- [ ] Hiệu chỉnh similarity theo loại bài/ngôn ngữ và ngoại lệ code/trích dẫn; `60%` là đề xuất cũ, chưa phải ngưỡng áp dụng. Không dùng similarity làm điểm chất lượng tổng thể.
- [ ] Hiệu chỉnh gate exact-copy theo 12.13: mặc định dự kiến báo lỗi chất lượng khi sao chép phần văn xuôi đáng kể; chốt ngoại lệ đoạn ngắn, code và trích dẫn bằng bộ bài mẫu.
- [x] Approve chỉ tạo Post draft, editor Publish sau; API apply hiện đã ép `status=draft`.
- [ ] Có lưu raw response đã redaction trong thời gian ngắn để debug không?
- [ ] Triển khai Reverb ngay đợt này hay sau khi đưa dự án lên VPS?
- [x] File HTML hiện giới hạn 5 MB và chỉ nhận `.html/.htm`.
- [ ] Chốt có cần hỗ trợ `.mhtml` hay giữ phạm vi HTML hiện tại.
- [ ] Chọn model text mặc định/fallback nào thay cho deterministic khi dùng chế độ Auto?

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

## 8. Đối chiếu đợt Settings / AI Content — 2026-10-03

- [x] Cài Spatie Laravel Settings 3.9.0, chuyển bảng key/value sang `group/name/payload/locked`, thêm `App\Settings\AiSettings` và settings migration.
- [x] Giữ schema/dữ liệu cũ trong `legacy_settings`; validation capability, transaction, audit và refresh cho worker vẫn hoạt động.
- [x] Heading `SYSTERM SETTING`, mục `SETTING` và route `/admin/settings` đã được mở.
- [x] Dựng giao diện Settings 10 tab, chuẩn hóa theo component/style của project; AI & Content đã đọc/lưu dữ liệu thật, các tab còn lại vẫn dùng fixture.
- [x] Select Post/Resource/Sound từ `config/ai-agent.php`; backend nhận target, chọn prompt và kiểm tra quyền theo config.
- [x] Badge tài nguyên và action sửa/xóa/tạo lại trong cột trái; form tạo mới bên phải độc lập.
- [x] Dialog editor có version check, sanitize HTML; xóa chỉ candidate terminal và giữ domain đã apply.
- [x] Regenerate giữ parent, tạo child, có trong danh sách sau reload; tạo lại toàn bài hoặc nhóm field.
- [x] QA backend/frontend, lint/build và browser local của đợt trước; dữ liệu QA đã được dọn. Browser QA đăng nhập cho Settings/select mới vẫn chờ tại 9.7/9.8.

Task 1 structured output/finish reason đã hoàn thành triển khai, regression và browser smoke local đăng nhập; worker local đã nhận code mới. Phạm vi QA với output được kiểm soát được ghi tại 11.6. Các mục similarity, extractor, `ai_content_drafts`, approve/reject trên trang riêng và Reverb/VPS vẫn mở.
Resource/Sound ở đợt này tạo candidate văn bản; chưa apply sang Resource/Sound hoặc
tạo file audio. Danh sách vẫn theo retention của `ai_imports` (mặc định 2 ngày).

## 9. Kế hoạch nối dữ liệu động cho SETTING — 2026-10-03

### 9.1 Hiện trạng và nguyên tắc

- [x] Shell Settings đã có tại `resources/js/pages/settings/index.vue`.
- [x] Tab AI & Content đọc/lưu API thật; typed settings, validation, transaction, audit và redaction đã có, dùng chung service với AI Providers.
- [x] Thay fixture của chín tab ngoài AI bằng API Sanctum/capability thật tại 9.9; Webhooks ghi rõ chưa hỗ trợ.
- [ ] Áp dụng redaction/write-only secret cho các nhóm ngoài AI: SMTP password, reCAPTCHA và webhook secret; không để frontend đọc `.env`.
- [x] Áp dụng ưu tiên database → config/env cho site/media/seo/mail/security/languages; AI giữ cơ chế riêng.
- [x] Nhóm mới dùng allowlist, transaction, audit actor/key và bảo vệ SMTP secret; capability chưa có không bật từ UI.

### 9.2 Bản đồ dữ liệu ban đầu theo tab

Bản đồ dưới đây là thiết kế ngày 2026-10-03; trạng thái đã triển khai ở 9.9.
Các mục ngoài capability hiện có vẫn là backlog, không còn fixture trong UI.

| Tab | Dữ liệu hiện tại | Nguồn dữ liệu mục tiêu |
| --- | --- | --- |
| Tổng quan | `general` hard-code | `App\Settings\SiteSettings`, group `site`: tên/url/mô tả/email, timezone, locale, date/time format |
| AI & Content | Đã nối catalog và cấu hình thật | Dùng chung `GET /api/admin/settings/ai`, `PUT /api/admin/settings/ai/settings` và `AiSettings`; model lưu bằng ID, đã có số từ khuyến nghị, system prompt và automation flags |
| Media | Driver, size, extension, WebP hard-code | `App\\Settings\\MediaSettings` và options backend; các action upload/conversion phải đọc cùng một nguồn |
| SEO | Meta/GA/GSC/robots hard-code | `App\\Settings\\SeoSettings`; thêm endpoint/route dùng robots và global metadata |
| Email | SMTP và sender hard-code | `App\\Settings\\MailSettings`; password mã hóa, chỉ nhận write-only, response chỉ trả `configured` |
| Cron Jobs | Mảng demo trong component | Endpoint đọc scheduler registry và run history; chỉ cho sửa/chạy khi có model/quyền rõ ràng, không giả lập `lastRun` |
| Webhooks | Mảng demo trong component | Bảng `webhooks`, model/resource, CRUD, event allowlist, secret mã hóa và test delivery |
| Languages | Mảng demo trong component | Locale registry/config cho options; setting `enabled/default` lưu DB, không tự bật locale chưa có bản dịch |
| Security | Switch/recaptcha demo | `SecuritySettings` cho giá trị có thể lưu; nối session/rate-limit/2FA/reCAPTCHA thật trước khi cho phép bật |
| System Info | Version/disk hard-code | Endpoint read-only lấy PHP/Vue/Vuetify, DB, disk và queue health; không cho ghi từ UI |

`AiSettings` hiện đã có API riêng và đang được worker dùng. Không tạo một đường ghi thứ hai cho provider/model; Settings page phải dùng chung service/composable với AI Providers.

Task 2 quản lý mẫu văn phong tại Systerm AI → Ai Prompt; tab AI & Content dùng `default_writing_profile_id` (12.6–12.7). Backend CRUD/profile analysis/default profile setting đã có. Trang Ai Prompt đã custom theo theme và dùng dữ liệu minh họa; plan nối API, quản lý mẫu và các phần còn thiếu ở 12.18. Select mẫu mặc định trong Settings đã nối catalog/writer AI chung tại 9.9.

### 9.3 API và quyền

- [x] GET /api/admin/settings trả section/options/quyền ghi/version/updated_at; SMTP secret chỉ có cờ password_configured.
- [x] PATCH /api/admin/settings/{group} cho site/media/seo/mail/security/languages; FormRequest chọn rules theo nhóm, service transform/redaction. AI giữ writer riêng hiện có.
- [x] Giữ và dùng các route AI hiện có: `GET /api/admin/settings/ai`, `PUT /api/admin/settings/ai/settings`.
- [ ] Aggregate Settings endpoint compose dữ liệu AI từ service hiện có.
- [x] Endpoint đọc Cron/Webhooks/System Info và preferences locale; Languages lưu typed trong API nhóm. Webhooks hiện chỉ trả capability chưa hỗ trợ.
- [x] Bổ sung settings.view/manage, grant admin/super-admin bằng migration/seeder và giữ quyền đã tùy chỉnh; quyền AI riêng.
- [x] Nhóm mới bắt buộc version; AI nhận settings_version từ client mới. Stale trả 409; client AI cũ vẫn tương thích.

### 9.4 Thứ tự triển khai

1. Chốt API contract, field mapping snake_case/camelCase, quyền và thứ tự ưu tiên DB → config/env.
2. Tạo typed settings/migration/defaults cho site, media, SEO, mail, security; giữ `legacy_settings` và không ghi đè giá trị cũ.
3. Tạo service, FormRequest, controller/resource, audit và test cho từng group; xử lý encryption/redaction cho secret.
4. Tạo `settingsService` và `useSettings` ở frontend: load một lần, loading/error/retry, dirty state, save từng group, conflict handling.
5. Đã nối AI tab vào catalog/settings API hiện có; loại bỏ provider/model hard-code và map selector về model ID (đợt 1).
6. Nối side effect runtime: media upload/conversion, SEO metadata/robots, mail manager/test email, security middleware và session.
7. Nối các nhóm có bảng riêng: webhook CRUD/test, language registry, cron registry/run history, system diagnostics read-only.
8. Xóa fixture/fallback UI sau khi API ổn định, giữ skeleton/error state và kiểm tra quyền theo tab.

### 9.5 Kiểm thử và tiêu chí hoàn thành

- [x] Feature tests cho AI Settings/provider: auth/permission, defaults, partial update, validation, snapshot và secret redaction.
- [x] Frontend tests cho AI Settings/catalog: hydrate, options động, save success/error, retry, field errors và chống lưu trùng.
- [x] Feature tests typed Settings: quyền/validation/persistence/audit/redaction/409 và concurrency writer AI; webhook CRUD/run history vẫn chờ.
- [x] Test runtime Media/SEO/mail/security sau refresh local; AI snapshot/worker refresh đã có. Kiểm VPS/SMTP thật vẫn chờ.
- [x] Frontend tests cho nhóm typed/operational, dirty/conflict/stale response, SMTP secret, locale và mẫu văn phong.
- [ ] Browser QA: đủ 10 tab ở desktop/mobile, dark mode, refresh sau save, thiếu quyền, timeout API và empty state.
- [x] Page không còn fixture nghiệp vụ; input editable có API/validation/audit, read-only lấy runtime hoặc ghi rõ capability chưa hỗ trợ.

### 9.6 Điểm cần chốt trước khi code

- [x] Cron chỉ đọc registry chung ở đợt này; không cho nhập command/sửa lịch, run history chưa có.
- [x] Languages dùng config + file dịch, enabled/default lưu typed Settings.
- [x] SMTP cho ghi DB với password mã hóa; GA/GSC/CAPTCHA chưa tích hợp nên không có input.
- [x] Lưu riêng group đang xem; version/dirty và dialog bảo vệ draft.

### 9.7 Đợt 1: AI & Content đã nối dữ liệu — 2026-10-03

- [x] Tách `AiContentSettingsPanel` và `useAiContentSettings`; tải/lưu qua service AI Settings chung, có loading, retry, catalog trống, thông báo và lỗi từng trường.
- [x] Provider/model lấy catalog thật; chỉ chọn model `text_generation` đang bật, khả dụng và thuộc provider đã chọn. Provider được suy ra từ model ID, không tạo setting trùng.
- [x] Lưu `default_text_model_id`, `default_image_model_id`, `default_temperature`, `min_word_count` (0–10.000), `default_system_prompt` (tối đa 10.000 ký tự), `auto_thumbnail`, `auto_seo`; partial update giữ các setting không được gửi.
- [x] Thêm selector model tạo ảnh thumbnail độc lập với model nội dung; hiển thị provider/model thật và lọc theo capability `image_generation`, trạng thái khả dụng và driver hỗ trợ ảnh.
- [x] Migration bổ sung bốn property còn thiếu đã chạy ở local; đối chiếu xác nhận sáu setting cũ và catalog provider/model được giữ nguyên.
- [x] Tác vụ mới chụp model, temperature, system prompt và số từ khuyến nghị vào snapshot; lựa chọn SEO/thumbnail riêng của tác vụ được ưu tiên. Số từ là khuyến nghị trong prompt, có thể điều chỉnh bằng yêu cầu độ dài riêng.
- [x] Form AI Content và dialog tạo Post nhận mặc định SEO/thumbnail đã lưu, giữ lựa chọn đã sửa khi API tải muộn và áp dụng mặc định khi tạo form mới.
- [x] Với mode `source`, AI Content và dialog tạo Post lấy thumbnail từ URL nguồn; thiếu ảnh nguồn thì để trống, không tự chuyển sang model ảnh. Tắt SEO loại field SEO tự tạo; regenerate field riêng theo lựa chọn. Mode `generate` được nối riêng tại 12.37.
- [x] Hoàn thiện thumbnail `generate` trong luồng AI Content → Post tại 12.37: UI chọn ảnh nguồn/AI, model ảnh riêng và prompt tùy chọn; child ảnh gắn vào `draft.thumbnail.media_asset_id`. List/review hiển thị ảnh và tiến trình/lỗi; thử lại/hủy riêng ảnh, duyệt Post draft và provenance model ảnh đã kiểm thử.
- [x] Feature/frontend tests kiểm tra lưu rồi đọc lại, validation/quyền, catalog và lựa chọn model, snapshot, automation flags và regenerate.
- [ ] Browser QA lưu/tải lại với tài khoản đăng nhập của người dùng.

### 9.8 Chuẩn hóa config và chọn đầu ra AI — 2026-10-03

- [x] `ai-agent.php` khai báo target, nhóm đầu ra, prompt và schema; `ai-import.php` giữ giới hạn vận hành; `ai-providers.php` tập trung metadata/adapter và kết nối từ `.env`.
- [x] Provider/model trong database được ưu tiên; connection môi trường tham chiếu metadata driver, không khai báo lại tên/adapter ở `ai-agent.php`.
- [x] `targets.*.outputs` là các nhóm được phép chọn. `output_definitions` chứa nhãn, trường canonical và loại nguồn phù hợp; API trả options từ config.
- [x] Bỏ bốn checkbox trong Tùy chọn AI, thay bằng một `AppSelect` chọn nhiều tag. Lựa chọn được giữ khi tải lại, giới hạn theo tài nguyên và đặt lại theo defaults khi mở form mới.
- [x] Tác vụ chụp `requested_outputs`/`fields`; prompt và kết quả chỉ cập nhật nhóm được chọn. Nhóm không chọn giữ dữ liệu nguồn hoặc candidate cha; tiêu đề nhập tay được giữ. Request cũ không có lựa chọn vẫn được hỗ trợ.
- [x] Mode `source` lấy thumbnail từ URL nguồn; không tự chuyển sang model tạo ảnh khi thiếu ảnh nguồn. Người dùng chọn `generate` để tạo ảnh AI tại 12.37.
- [x] Kiểm thử config/registry, lựa chọn theo target, snapshot, lọc kết quả provider và giao diện select. Build production được cập nhật.
- [ ] Browser QA select với tài khoản đăng nhập của người dùng.

### 9.9 Chuẩn hóa toàn bộ trang Settings — 2026-10-06

- [x] Tách route thành navigation, form theo schema, locale panel, mail test và operational panel; giữ Vuetify/Vuexy, màu alert và AppDialogLayout.
- [x] Sáu nhóm typed ngoài AI: site/media/seo/mail/security/languages. Null fallback config, refresh khi đọc, allowlist/transaction/audit keys, SMTP password encrypted/write-only.
- [x] GET /api/admin/settings và PATCH /api/admin/settings/{group}, Sanctum + settings.view/manage; quyền AI riêng. Có loading/retry, dirty từng tab, reset, giữ draft khi đổi tab/422/409, chặn response cũ/lưu trùng.
- [x] AI & Content giữ catalog/writer cũ; mẫu văn phong mặc định từ catalog thật, có version và nhãn thumbnail Post rõ ràng.
- [x] Media dùng giới hạn/extension subset trong upload validator và profile thumbnail/OG trong conversion; giữ MIME, dangerous extension, private ZIP và ảnh gốc.
- [x] Site/SEO áp dụng tên/meta/canonical/contact trên public, global metadata fallback và /robots.txt. Website URL không đổi địa chỉ kết nối ứng dụng.
- [x] Email dùng ProjectMailService refresh transport khi gửi thử; log/array không báo giao thư thành công. Bảo mật dùng thời hạn token mới và rate limit login.
- [x] Locale lấy config + file dịch thực, menu dùng enabled/default. Chỉ en/fr/ar có bản dịch; các trang quản trị custom vẫn là tiếng Việt.
- [x] Cron dùng ProjectScheduleRegistry chung web/console; System Info đọc phiên bản/DB/disk/queue thật. Webhooks là empty state capability chưa hỗ trợ.
- [x] Backend/frontend tests, lint/Pint/build và browser đủ 10 tab desktop sáng/tối + mobile. Save/reload/draft/reset được kiểm; hai migration thêm nhóm/quyền đã chạy local.

- [x] Upload logo/favicon đã hoàn tất tại 12.42, lưu cùng version nhóm site và áp dụng public/admin.

Backlog mở rộng còn lại: webhook CRUD/delivery/history, scheduler run history/worker
heartbeat, GA/GSC/2FA/CAPTCHA và kiểm SMTP/VPS. Chưa cho phép
bật các tính năng này từ UI. AI vẫn dùng endpoint riêng theo quyền riêng.
Lỗi/quyền/timeout/empty state đã kiểm bằng test tự động; browser chưa mô phỏng
mọi trạng thái lỗi API. Những checkbox thiết kế ở 9.2–9.6 chỉ được hoàn thành
trong phạm vi đã triển khai ở mốc này.

Bằng chứng: [Settings QA](qa/SETTINGS_2026-10-06/README.md).

## 10. Tổng hợp phần còn lại — audit 2026-10-03, cập nhật tiến độ 2026-10-06

Các checkbox bên trên gồm cả task, tiêu chí và quyết định. Không cộng checkbox trống thành số task độc lập. Những nhóm chưa hoàn thành:

| Nhóm | Phần còn thiếu | Tham chiếu |
| --- | --- | --- |
| Structured output | Tuning temperature và quyết định debug raw response ở đợt riêng. Task 1 đã xong code, diagnostics, regression, build, restart worker và browser smoke local với output được kiểm soát | 2A, 11.6 |
| Đánh giá chất lượng C — đợt riêng | Task 2 đã `DONE` kỹ thuật tại 12.35. Hai người đọc chưa chấm; tiếp tục xác nhận facts/coverage, văn phong và công biên tập trước kết luận chất lượng/rollout | 2B, 12.15, 12.35 |
| Extractor/nguồn | Raw HTML/file multipart/encoding và preview đã nối AI Content tại 12.24, không flatten file ở client. Còn mở rộng coverage nguồn/layout trong nghiên cứu chất lượng; không còn là hạng mục UI/API chưa nối | 2D, 12.15, 12.24 |
| Biên tập AI — tạm hoãn | Kho `ai_content_drafts`, lưu nguồn/bản nháp dài hạn và duyệt chéo owner chờ đợt riêng. Approve/reject UI/API, người duyệt/lịch sử/lý do và so sánh nguồn đã xong trên `ai_imports` | 3, 12.36 |
| Realtime/VPS | Broadcast, Echo/Reverb, process manager, worker/scheduler production và QA reboot/reconnect | 2C |
| Settings ngoài AI | Chín tab: Tổng quan, Media, SEO, Email, Cron Jobs, Webhooks, Languages, Security, System Info; API, typed settings, quyền, runtime và tests tương ứng | 9.1–9.6 |
| Settings workflow/QA | Concurrency/409 (cả AI), dirty/conflict/stale-response handling và browser QA đăng nhập desktop/mobile/dark mode | 9.3, 9.5, 9.7, 9.8 |

**Đã có, không nằm trong phần chờ:** chuẩn hóa ba config AI, select nhiều tag từ config, AI Settings động, raw HTML/file/encoding và preview, editor/regenerate, duyệt/từ chối/so sánh/lịch sử, Apply Post draft/provenance, thumbnail nguồn hoặc sinh bằng AI (12.37), model catalog sync/test từng model, timeout/queue budget, polling backoff, kiểm kết quả AI trước `ready`, diagnostics/usage theo allowlist và lỗi validation sau reload. Sáu nhóm UI/API tại 12.23 đã triển khai ở 12.24; CRUD văn phong và chuẩn dialog/loading tại 12.25–12.29 đã xong.

**Phạm vi cần chốt nếu mở rộng:** Resource/Sound hiện chỉ tạo candidate văn bản, chưa apply sang domain tương ứng hoặc tạo file audio. Chưa coi phần mở rộng này là task bắt buộc của FIX 1.

**Bằng chứng audit chính:** `AbstractStructuredAiProvider::normalizePayload/validatePayload`, `ArticleImportService::run/extract`, `AiProviderCatalogService::sync/test`, `AiProviderClient`, `AiImportController::apply`, `ProcessAiImageGenerationJob`, `AiContentList.vue`, `useAiContentActions.js` và `settings/index.vue`. Browser smoke local của task 1 đã ghi tại 11.6; các nhóm còn lại vẫn cần QA tương ứng và kiểm chứng môi trường VPS.

## 11. Plan task 1 — Kiểm tra kết quả AI trước khi báo thành công

**Ngày lập:** 2026-10-03. **Trạng thái:** Hoàn thành triển khai task 1, tests/style/build, restart worker và browser smoke local đăng nhập với output được kiểm soát. Không gọi generation từ provider AI thật trong đợt browser QA này; kiểm thêm với upstream thật là bước xác nhận tùy chọn. Bằng chứng và phạm vi tại 11.6; toàn bộ FIX 1 vẫn chỉ hoàn thành một phần.

### 11.1 Mục tiêu và cách xử lý

Khi người dùng chọn AI tạo một hạng mục, kết quả mới của hạng mục đó phải đạt quy tắc trước khi tác vụ được báo thành công. Ví dụ: chọn Tiêu đề + Nội dung nhưng AI chỉ trả tiêu đề thì tác vụ chuyển sang lỗi, giữ nguồn để người dùng tạo lại.

- Áp dụng cho tạo mới và regenerate ở AI Content cùng dialog tạo Post bằng AI; đọc lựa chọn đã chụp trong input của từng run.
- Kiểm output AI riêng trước khi ghép nguồn hoặc candidate cha. Dữ liệu nguồn/cha không được che output AI thiếu của nhóm đã chọn.
- Chỉ ghi `ready` sau khi kiểm phản hồi, parse, kiểm kiểu và kiểm giá trị sau sanitize đều đạt.
- Lỗi output chuyển `failed`, dùng `error_code` để phân biệt; không tự retry generation vì lỗi schema/nội dung. HTTP/timeout tiếp tục dùng chính sách retry hiện có; người dùng vẫn có thể thử lại thủ công.
- Giữ contract cập nhật từng phần, field không chọn, title nhập tay và candidate cha. Các run cũ đã `ready` không bị đổi trạng thái; quy tắc áp dụng cho lần chạy mới.
- Dùng temperature đã lưu trong Settings/snapshot. Lưu diagnostics theo allowlist; raw response debug và tuning temperature là các đợt riêng.

### 11.2 Quy tắc đầu ra theo nhóm

Quy tắc đã được khai báo tại `config/ai-agent.php → output_definitions`, phân biệt field được phép với field bắt buộc/điều kiện tối thiểu. Validator và hướng dẫn gửi AI đọc cùng quy tắc; frontend tiếp tục dùng catalog từ config.

| Nhóm được chọn | Điều kiện đạt |
| --- | --- |
| Tiêu đề | Có `title` dạng chuỗi, không rỗng sau chuẩn hóa khoảng trắng; tuân theo giới hạn 255 ký tự hiện có |
| Mô tả ngắn | Có `excerpt` dạng chuỗi, không rỗng; tuân theo giới hạn hiện hành của project |
| Nội dung | Có `content_html` dạng chuỗi; chấp nhận alias `content` khi thiếu canonical. Sau sanitize, decode entity và chuẩn hóa khoảng trắng phải còn nội dung đọc được |
| SEO | Có ít nhất một field SEO hợp lệ; giữ contract partial hiện tại. Field text rỗng không được tính là đầu ra hợp lệ; boolean `false` vẫn hợp lệ. Không bắt đủ mọi field SEO |
| Danh mục & tags | Có ít nhất một mảng category/tag theo schema; kiểm phần tử là integer ID hợp lệ. Mảng `[]` là kết quả hợp lệ; giữ bước lọc ID tồn tại hiện có |
| Thumbnail từ nguồn | Do pipeline nguồn/media xử lý; ảnh nguồn không có vẫn hợp lệ. Prompt/alt từ text AI là tùy chọn; model không được tự gán source URL hoặc media asset ID |

Field optional thiếu/null được bỏ qua; field bắt buộc thiếu/null/rỗng phải lỗi. Loại field ngoài nhóm được chọn trước khi cập nhật candidate. Với request chỉ chọn thumbnail nguồn, bỏ HTTP generation của text AI; vẫn giữ điều kiện chọn model và kiểm quyền/cấu hình hiện tại ở form/controller. Pipeline vẫn lấy ảnh nguồn nếu có, không bắt AI trả prompt/alt cho ảnh.

Request legacy không có selection giữ cách suy ra full-generation hiện tại: AI phải trả title/content khi được yêu cầu tạo toàn bài; các field optional và cờ SEO/thumbnail cũ được giữ. Regenerate `fields=[]` vẫn là tạo lại toàn bài; regenerate từng nhóm chỉ kiểm output mới của nhóm đó. Deterministic được chọn rõ ràng kiểm draft nguồn, không yêu cầu envelope AI; provider AI đã được chọn mà thiếu cấu hình phải báo lỗi rõ ràng.

### 11.3 Các bước triển khai

#### Bước 1 — Chốt contract và validator dùng chung

- [x] Khai báo required/minimum/type/value rules theo bảng 11.2 trong config; dùng `AiOutputValidator` cho output canonical và kiểm sau sanitize.
- [x] Chuẩn hóa `content` → `content_html`, ưu tiên canonical khi có cả hai; giữ `content` của draft đồng bộ với `content_html`.
- [x] Khóa quy tắc legacy, SEO partial, taxonomy rỗng và thumbnail nguồn bằng test contract.

#### Bước 2 — Kiểm phản hồi theo adapter trước khi bóc JSON

- [x] OpenAI Chat Completions: kiểm choice/content, refusal, `tool_calls`/`function_call` và `finish_reason`; chỉ nhận kết thúc bình thường `stop`. Thiếu marker hoặc kết thúc bị cắt có mã lỗi rõ ràng dù content chứa JSON đọc được.
- [x] Gemini: kiểm block/refusal, `finishReason` và `functionCall`; chỉ nhận candidate kết thúc bình thường `STOP`. Ghép text parts của candidate được chọn trước khi parse.
- [x] HTTP JSON: giữ flat object, `data` object và `output` JSON string hiện có; JSON thuần không bắt có finish marker. Nếu trả envelope OpenAI/Gemini thì kiểm theo envelope tương ứng, không giả lập marker thành công.
- [x] Chỉ nhận JSON object; từ chối list/scalar/null, JSON bị cắt hoặc không hợp lệ. Field ngoài schema/selection không đi vào draft.
- [x] Request tiếp tục không gửi tools; chỉ thêm `tool_choice: none` cho adapter hỗ trợ rõ ràng, có test payload tương ứng.
- [x] Tách diagnostics khỏi mảng field: giữ `generate(): array` hiện có; thêm `AiResponseMetadataProvider` lấy metadata an toàn theo từng lần gọi, reset khi bắt đầu run. Exception mang diagnostics lỗi đã chuẩn hóa để job lưu được.

#### Bước 3 — Kiểm output AI trước fallback và sau sanitize

- [x] `ArticleImportService` kiểm kiểu và required của output mới, sanitize phần HTML AI, rồi kiểm lại giá trị trước khi merge vào nguồn/parent. Validator vẫn được gọi ở service để adapter tùy chỉnh không bỏ qua gate.
- [x] HTML chỉ còn script đã bị loại, markup rỗng, khoảng trắng hoặc `&nbsp;` phải lỗi khi chọn Nội dung. Giữ code/table và HTML an toàn có nội dung.
- [x] Không có output của nhóm đã chọn thì fail, kể cả provider chỉ trả field ngoài nhóm; không giữ fallback rồi báo AI tạo thành công.
- [x] Regenerate lỗi chỉ làm child run thất bại, giữ nguyên dữ liệu/version của parent. Chỉ xử lý thumbnail hoặc xếp image job sau khi gate nội dung đạt.
- [x] Job kiểm cancelled trước ghi kết quả; chỉ ghi `ready` khi service đã hoàn thành toàn bộ gate.

#### Bước 4 — Lưu diagnostics và trả lỗi an toàn

- [x] Dùng cột JSON `source_meta_json` hiện có, thêm nhánh `ai_response` và merge với source metadata thay vì ghi đè; không cần migration. Giữ metadata trong cả run thành công và run lỗi; khởi tạo lại diagnostics khi bắt đầu attempt/thử lại thủ công để không trả lỗi field cũ.
- [x] Allowlist: response ID, model được provider báo (tách khỏi model đã chọn), finish reason, usage/token dạng số, nhóm yêu cầu, tên field trả về thuộc schema, stage và lỗi validation `{group, field, reason}`. Key lạ upstream không được lưu dưới dạng tên field. Giới hạn độ dài/số phần tử; không lưu raw value, raw body, prompt, source text, key hoặc header xác thực trong diagnostics.
- [x] Giữ các mã hiện có `AI_PROVIDER_REFUSAL`, `AI_PROVIDER_INVALID_JSON`, `AI_PROVIDER_SCHEMA`, `AI_PROVIDER_TIMEOUT`; bổ sung `AI_PROVIDER_TOOL_OUTPUT`, `AI_PROVIDER_INCOMPLETE`, `AI_PROVIDER_MISSING_FIELDS`, `AI_PROVIDER_EMPTY_CONTENT`.
- [x] Đồng nhất status/detail/summary: `error_code`, `error` ngắn và `validation_errors` an toàn. Controller chỉ expose các trường cần hiển thị; diagnostics nội bộ không đặt trong `result_json` vì payload hiện spread toàn bộ result.
- [x] Failed run không trả draft mới như candidate thành công; lỗi sau reload vẫn có lý do. Lỗi request HTTP tiếp tục dùng `message/errors` như hiện tại.

#### Bước 5 — Hiển thị lỗi trong các luồng hiện có

- [x] Dùng formatter lỗi chung: field error đã chuẩn hóa → thông báo an toàn backend → thông báo dự phòng; hiển thị snackbar màu lỗi một lần khi run chuyển `failed`.
- [x] Dòng run lỗi trong AI Content giữ lý do ngắn sau reload; không bật hàng loạt snackbar cho lỗi cũ khi tải danh sách.
- [x] Regenerate nhận child đã `failed` ngay hiện lỗi đúng, không báo đã xếp hàng thành công; polling child đúng UUID và giữ parent đang chọn.
- [x] Store/dialog chỉ sync candidate của run thành công. Response lỗi có draft dư không được chọn hoặc apply; parent hợp lệ vẫn dùng được.
- [x] Mất mạng khi polling chỉ cảnh báo chưa đọc được trạng thái, giữ run và cho GET lại cùng UUID; giữ guard response muộn/unmount và không tự tạo generation mới.

#### Bước 6 — Kiểm thử và xác nhận

- [x] Mở rộng tests adapter, selected outputs, service và job lifecycle bằng HTTP/queue fake theo bảng 11.5.
- [x] Bổ sung frontend tests create/regenerate/dialog/list: lỗi ngay và sau polling, reload, parent preservation, thông báo một lần, HTTP 422 và response muộn.
- [x] Chạy bộ test liên quan, kiểm style các file PHP đã sửa và build production; bằng chứng tại 11.6.
- [x] Restart worker local để nhận code mới; process cũ đã thoát và process mới hoạt động.
- [x] Browser smoke đăng nhập trên production asset: hiển thị các run đã chạy qua service/job với output được kiểm soát cho title/content, excerpt-only, SEO partial, thiếu thumbnail nguồn và regenerate lỗi; kiểm reload, editor và giữ candidate cha.
- [x] Dialog Post: smoke mở/đóng `Fill All With AI`; hành vi snackbar khi child lỗi và apply candidate cha đã được kiểm bằng frontend tests, không được ghi nhận là generation lỗi trực tiếp từ provider trong browser.

### 11.4 Các file đã tác động

| Phần | File/khu vực |
| --- | --- |
| Quy tắc/validation | `config/ai-agent.php`, `AiOutputValidator.php`, `AiResponseDiagnostics.php` |
| Response | `AbstractStructuredAiProvider.php`, `OpenAiProvider.php`, `GeminiProvider.php`, `StructuredAiProvider.php`, `Contracts/AiResponseMetadataProvider.php` |
| Pipeline/lifecycle | `ArticleImportService.php`, `ProcessAiImportJob.php`, `AiImportException.php` |
| API errors | `AiImportController::payload`, `AiSessionSummaryResource.php` |
| Frontend state | `useAiContentGeneration.js`, `useAiContentActions.js`, `useAiContentWorkspace.js`, `useAiRunFeedback.js`, `stores/aiAgent.js`, `utils/aiErrors.js` |
| Frontend hiển thị | `AiContentList.vue`, `ai/content/index.vue`, `CreateWithAiDialog.vue`; dùng component thông báo hiện có |
| Tests | `AiProviderAdapterTest`, `AiSelectedOutputsTest`, `ArticleImportServiceTest`, feature job/API và frontend tests của các luồng trên |

### 11.5 Ma trận kiểm thử và tiêu chí hoàn thành

| Trường hợp | Kết quả cần đạt |
| --- | --- |
| Thiếu/null/rỗng field bắt buộc hoặc chỉ trả field ngoài selection | Failed với lý do đúng; fallback nguồn không che lỗi; lỗi validation không tự gọi lại AI |
| AI bị cắt, trả tool call hoặc refusal dù có JSON đọc được | Failed trước merge/ready; giữ mã lỗi phù hợp |
| JSON malformed, root list/scalar hoặc sai kiểu field | Failed; không ép array thành chuỗi hoặc đưa dữ liệu sai vào candidate |
| Content chỉ còn markup/whitespace/entity rỗng sau sanitize | Failed trước upload thumbnail hoặc image dispatch |
| Excerpt-only/SEO partial, boolean false, taxonomy [] | Ready theo contract nhóm; không bắt AI trả title/content không được chọn |
| Alias content; canonical và alias cùng tồn tại | Chuẩn hóa đúng, canonical được ưu tiên; draft content/content_html đồng bộ |
| HTTP JSON thuần; Gemini nhiều text parts | Nhận shape hợp lệ theo adapter; không bắt marker OpenAI cho mọi provider |
| Thumbnail nguồn không có ảnh | Run hợp lệ, thumbnail trống |
| Chỉ chọn thumbnail nguồn và nguồn có ảnh hợp lệ | Lấy ảnh, tạo asset và gắn thumbnail theo pipeline hiện tại; assert không có HTTP generation của text AI |
| Regenerate lỗi ngay hoặc sau polling | Child failed; parent data/version/candidate đang chọn giữ nguyên |
| Metadata success/failure có dữ liệu upstream nhạy cảm | DB chỉ lưu allowlist; API không trả diagnostics nội bộ/key/header/raw body |
| Reload run lỗi; polling mất mạng; response muộn | Lý do lỗi còn đọc được, snackbar không lặp; GET lại cùng run, không sync/apply output lỗi |

**Hoàn thành task 1 khi:** mọi run generation mới chỉ được `ready` sau khi output đạt quy tắc, field không chọn được giữ, lỗi có lý do rõ ràng trong cả AI Content và dialog Post, test regression đạt và browser QA các luồng chính đã được ghi nhận.

### 11.6 Bằng chứng triển khai và xác nhận — 2026-10-03

- [x] Backend tích hợp: **91 tests / 751 assertions đạt**, gồm adapter/envelope, validator, selected outputs, service/job lifecycle, API và regression Settings/thumbnail.
- [x] Frontend toàn bộ: **34 files / 192 tests đạt**, gồm create/regenerate, dialog/list, thông báo lỗi và bảo toàn candidate cha.
- [x] Scoped Pint cho các file PHP đã sửa đạt.
- [x] Build production thành công; asset chính mới `main-B2QQxytR.js`.
- [x] Worker local đã restart mềm: PID cũ `24360` thoát, PID mới `44228` hoạt động với timeout `780` giây. Đây là xác nhận local; cấu hình/QA worker trên VPS ở mục 2C vẫn mở.
- [x] Browser smoke trên tab đã đăng nhập dùng production asset: tạo bốn run QA tạm qua service/job thật với output in-memory được kiểm soát. Run title + content + thumbnail không có ảnh nguồn đạt `ready`; excerpt-only và SEO partial đạt `ready`, giữ field nguồn; child regenerate thiếu content chuyển `failed`.
- [x] Reload AI Content hiển thị lỗi tiếng Việt `Nội dung thiếu dữ liệu bắt buộc.` và vô hiệu nút sửa của child lỗi. Mở editor candidate cha sau child lỗi xác nhận title/content giữ nguyên; editor excerpt và SEO hiển thị giá trị đúng; nút đóng hoạt động.
- [x] Mở Post Add → `Fill All With AI` thấy dialog và hủy thành công. Snackbar cho child lỗi và khả năng apply candidate cha được xác nhận bằng automated frontend tests; không chạy thủ công luồng generation lỗi qua upstream thật trong dialog Post.
- [x] Bốn run QA đã được xóa, đối chiếu còn `0` run tạm. Screenshot: `storage/logs/ai-output-validation-ui.png`.

Đợt browser QA này **không gửi HTTP generation tới provider AI thật**; output có kiểm soát dùng để xác nhận pipeline và UI. Task 1 đã hoàn thành triển khai, regression và local smoke trong phạm vi trên. Temperature vẫn lấy từ Settings/snapshot; tuning temperature `0` và lưu raw response debug chưa triển khai. Các task khác còn mở, toàn bộ FIX 1 chưa hoàn tất.

## 12. Task 2 — Nâng chất lượng AI Content, văn phong và ảnh nội dung

**Audit ban đầu:** 2026-10-03. **Cập nhật tổng hợp:** 2026-10-04.
**Trạng thái 2026-10-05:** `DONE` kỹ thuật tại 12.35, gồm nguồn/profile/pipeline C/checkpoint/gates/UI/TinyMCE–MediaLibrary/Apply. Chủ dự án đã chuyển chấm người đọc sang đợt riêng và chọn giữ Tiny Cloud trên domain được phép. Browser QA mới dùng localhost và SQLite; model/MySQL/cache của 12.24 là bằng chứng lịch sử riêng. Chưa kết luận chất lượng văn phong hoặc rollout. Các báo cáo 12.17–12.34 giữ lịch sử từng đợt.

**Bước đầu đã làm — 2026-10-04:** mục `Ai Prompt` trên menu dọc/ngang thuộc Systerm AI, route `/admin/ai/prompt` (`ai-prompt`) và trang trống tại `resources/js/pages/ai/prompt/index.vue`. Giao diện sẽ do người dùng bổ sung sau; chưa có API/model/queue phân tích mẫu ở bước này.

**Mục tiêu:** Tạo bài tiếng Việt tự nhiên, có ích và giữ đúng thông tin nguồn; tránh dịch từng câu, lặp ý và ép mọi bài theo cùng một bố cục. Người dùng kiểm soát văn phong, yêu cầu riêng, taxonomy và ảnh; kết quả vẫn là candidate để xem/sửa rồi Apply thành Post draft.

Tài liệu tham khảo nằm tại `C:/Users/phamc/OneDrive/Máy tính/ai_article_pipeline_plan.md`. Mục 12 này là plan tích hợp vào project hiện tại: ưu tiên quyết định mới của người dùng, điều chỉnh kiến trúc theo code đã có và tách các ý tưởng V2 khỏi phần bắt buộc V1.

### 12.1 Các quyết định và phạm vi V1

| Nội dung | Thiết kế trong plan |
| --- | --- |
| Luồng tạo nội dung đầy đủ | Ba lượt AI tuần tự: Analyze + Plan → Write → Edit; ban đầu dùng cùng provider/model đã chọn |
| Vai trò của từng bước | Đọc hiểu/lập dàn ý, viết bản nháp, biên tập; mỗi bước có input/output/prompt/schema riêng |
| Taxonomy | Category/Tag chọn thủ công; bỏ toàn bộ generation/đề xuất/ánh xạ taxonomy bởi AI |
| Prompt | Quy tắc hệ thống theo bước + brief từng bài + profile + dữ liệu nguồn + contract đầu ra |
| Văn phong | Select mẫu đã lưu hoặc mặc định website; textarea cho yêu cầu riêng. AI chọn cấu trúc phù hợp bài, không tự đổi profile |
| Tạo mẫu từ bài tham khảo | Dán bài → AI phân tích → người dùng xem/sửa → Lưu database → dùng lại qua select |
| Ảnh trong bài | Chọn/upload từ bên trong TinyMCE, tại vị trí con trỏ; tích hợp MediaLibrary và giữ tham chiếu asset khi lưu/regenerate |
| Bảo toàn nguồn | Facts có source block ID/bằng chứng, giữ điều kiện, phiên bản, số liệu, code, bảng, link, quote và tham chiếu ảnh |
| Vận hành | Dùng queue, transport, registry, Settings và `AiImport` hiện có; thêm snapshot/checkpoint/metadata cho từng bước |
| Đầu ra nghiệp vụ | Chỉ field được chọn được cập nhật; Post Apply vẫn qua `PostAiAdapter` và ép `status=draft` |

Ba bước là workflow cố định do backend điều phối. Các tên `AnalyzerPlannerAgent`, `WriterAgent`, `EditorAgent` chỉ là lớp đảm nhiệm một nhiệm vụ; không cần ba model riêng hoặc một framework agent tự quyết định công cụ và luồng chạy. Ba lượt AI cũng không tự bảo đảm bài hay hơn: cần đánh giá bằng bộ bài mẫu tại 12.15.

### 12.2 Hiện trạng trước triển khai và phần cần kế thừa

Luồng đang có, rút gọn theo code:

```text
Request URL / text / nội dung từ file HTML + options
  → AiRunService tạo AiImport và snapshot cấu hình
  → ProcessAiImportJob::handle()
  → ArticleImportService::run()
      → ArticleSourceFetcher::fetch() hoặc inlineSource()
      → extract(): nội dung chính, lọc và sanitize
      → PromptRegistry + ProviderRegistry chọn prompt/provider
      → provider->generate() một lượt
      → adapter chuẩn hóa response + AiOutputValidator
      → mergeRequestedFields() với nguồn/candidate cha
      → xử lý thumbnail → lưu result_json.draft → ready
  → người dùng xem/sửa candidate
  → AiImportController::apply()
  → PostAiAdapter → action tạo/cập nhật Post draft + provenance
```

`PostAiAdapter` chuyển draft canonical sang field nghiệp vụ Post, kiểm tra/chuẩn bị payload cho action; nó không gọi model. Adapter provider như OpenAI/Gemini định dạng request theo API của model và chuẩn hóa response về contract hệ thống; nó không ghi Post hay quyết định taxonomy, slug, trạng thái xuất bản hoặc actor.

| Hiện trạng cần xử lý | Bằng chứng/điểm sửa |
| --- | --- |
| Prompt cuối vẫn thiên về rewrite và bố cục chung; chỉ có một lượt generation | `Content/ArticleImportService.php`, `Registries/PromptRegistry.php`, `config/ai-agent.php` |
| `generate(title, content, language, rewriteStyle, promptKey, instructions)` gắn với bài viết; adapter yêu cầu JSON phẳng theo field cuối | `Contracts/AiProviderContract.php`, `Providers/Adapters/AbstractStructuredAiProvider.php` |
| Taxonomy còn trong prompt/schema/output và được lọc ID sau generation | `config/ai-agent.php`, adapter, `ArticleImportService`, `Targets/PostAiAdapter.php` và contract UI |
| Sanitizer đã giữ heading/list/link/blockquote/code/table nhưng chưa cho phép `img/figure/figcaption`; extractor còn có thể mất thuộc tính ảnh và whitespace code | `Content/AiContentSanitizer.php`, `ArticleImportService::extract()` |
| TinyMCE có công cụ ảnh nhưng chưa nối chọn/upload với MediaLibrary của project | `resources/js/views/apps/blog/post/PostEditor.vue`; editor được dùng ở Post và candidate AI |
| Chưa có profile database, source checkpoint, similarity/language gate hay đối chiếu facts theo từng bước | Services, job, Settings và metadata hiện tại |

**Bằng chứng audit ngày 2026-10-03:** chạy `OpenAiProvider` và `ArticleImportService` với HTTP fake có `finish_reason=stop`, chọn `content`: HTML exact-copy và output tiếng Anh khi yêu cầu `vi` đều được nhận. Không gửi generation thật, không ghi Post/Settings. Hai ca này cho thấy thiếu gate chất lượng; không phải lỗi của task 1. Đợt cập nhật plan ngày 2026-10-04 không chạy lại hai ca này.

Giữ các kiểm tra của task 1: finish reason hợp lệ, không tool call/refusal, JSON/schema/kiểu dữ liệu đúng, field đã chọn không rỗng, diagnostics an toàn và không fallback che lỗi. Các đường tạo Resource/Sound vẫn phải tương thích; V1 nâng pipeline bài Post trước, không ép schema bài viết vào các target khác.

### 12.3 Luồng mới từ nguồn đến Post

```text
1. Người dùng nhập nguồn và lựa chọn
   URL / text / HTML + ngôn ngữ + model + nhóm field
   + profile văn phong + yêu cầu riêng + taxonomy thủ công
       ↓
2. Backend validate, tạo AiImport, snapshot options/profile
   Queue job lấy nguồn → extract → sanitize → source blocks/snapshot
       ↓
3. Analyze + Plan
   ArticleKnowledge + WritingBrief/WritingPlan + source anchors
       ↓
4. Write
   Dùng knowledge/plan/profile và nguồn có kiểm soát → bản nháp
       ↓
5. Edit
   Biên tập diễn đạt và đối chiếu thông tin → output cuối
       ↓
6. Validate schema/field → khôi phục tham chiếu ảnh hợp lệ → sanitize
   → quality gate trên output MỚI → merge field được chọn
       ↓
7. Xử lý thumbnail riêng → lưu result_json.draft → ready
       ↓
8. Người dùng xem/sửa trong editor, chèn ảnh trong TinyMCE
   Backend validate HTML và đồng bộ media usage khi lưu
       ↓
9. Apply → PostAiAdapter → tạo/cập nhật Post draft + provenance
   Editor kiểm tra và Publish theo luồng hiện có
```

**Chuẩn hóa nguồn:** bỏ header/footer/nav/ads/script và phần thừa nhưng giữ nội dung chính; không xóa bài chỉ vì nằm trong `<form>`. Chọn container phù hợp từ article/main/section/body theo nội dung, không mặc định lấy phần tử đầu tiên. Giữ đoạn văn, heading, list, code, bảng, link và quote dưới dạng block có ID ổn định; code giữ xuống dòng/thụt đầu dòng. Snapshot gồm nội dung, URL/canonical URL, thời điểm lấy, hash và các block cần dùng làm bằng chứng.

Nguồn dài phải có budget rõ ràng: giữ các block liên quan và thông báo phần thiếu/chưa đọc; không cắt thầm code, điều kiện hoặc kết luận quan trọng để đủ token. V1 không mặc định thêm nhiều lượt tóm tắt/chunking AI; giới hạn input và lỗi nguồn quá lớn phải hiển thị rõ.

**Nhánh rút gọn:** chỉ tạo title/excerpt/SEO thì dùng nhiệm vụ ngắn tương ứng, không bắt chạy đủ ba bước. Nếu chọn `content` cùng các field khác, output cuối chứa các field được yêu cầu trong cùng pipeline. Thumbnail-only không gọi AI text; deterministic không đi qua gate rewrite. Regenerate tạo child, giữ parent và chỉ thay nhóm được chọn; lỗi child không làm mất bản nháp cha hoặc dữ liệu nhập tay.

### 12.4 Analyze + Plan, Write và Edit làm gì?

| Bước | Input | Công việc | Output |
| --- | --- | --- | --- |
| Analyze + Plan | Source blocks, brief người dùng, profile snapshot, field được chọn | Hiểu chủ đề/facts/điều kiện; đánh dấu phần chưa rõ; chọn góc tiếp cận, loại bài, audience và outline nếu brief chưa xác định | Knowledge có bằng chứng + plan/coverage; chưa có bài hoàn chỉnh |
| Write | Knowledge, plan, brief, profile và các đoạn nguồn cần kiểm tra | Viết mới bằng tiếng Việt tự nhiên; giải thích theo nhu cầu người đọc; giữ code/bảng/link/quote và điều kiện | Bản nháp cùng tham chiếu facts/assets cần giữ |
| Edit | Bản nháp, knowledge, source anchors, brief/profile | Sửa dịch máy, lặp ý, sáo rỗng, nhịp câu, heading/bullet thừa; đối chiếu facts, số liệu, phiên bản, code và điều kiện | Bài cuối + metadata sửa/issue cần hiển thị an toàn |

Analyzer phải phân biệt thông tin nguồn khẳng định, suy luận có điều kiện và phần chưa đủ dữ liệu. Facts có `source_block_ids` hoặc trích đoạn hỗ trợ ngay trong V1; không chờ V2 Fact Ledger mới giữ bằng chứng. Các trích đoạn và block ID phải được backend kiểm tra tồn tại. Điểm confidence do AI tự báo không chứng minh nhận xét đúng.

Writer được xem source có kiểm soát, đặc biệt code/bảng/quote; không chỉ nhận một bản tóm tắt dễ mất chi tiết. Được thêm ví dụ giả định để giải thích khi ghi rõ là ví dụ, nhưng không tự tạo benchmark, tính năng/API, trải nghiệm cá nhân, phát ngôn hoặc kết luận mà nguồn không hỗ trợ. Profile bài mẫu chỉ cung cấp cách viết, không cung cấp facts cho bài mới.

Editor là lượt biên tập theo dữ liệu đã cung cấp, không phải xác minh sự thật ngoài internet. Nếu nguồn sai/cũ, ba bước có thể vẫn giữ thông tin sai/cũ; tác vụ research/fact-check bên ngoài thuộc giai đoạn sau.

**Ví dụ giả định — nguồn giới thiệu thư viện X phiên bản 2.0:**

```text
Nguồn: "Version 2.0 supports batch requests of up to 100 items.
This feature requires a paid plan. The endpoint remains /v1/items."

Analyze + Plan:
- F1: batch tối đa 100 phần tử, bằng chứng block S03.
- F2: cần gói trả phí, bằng chứng block S03.
- F3: endpoint vẫn /v1/items, bằng chứng block S03.
- Loại bài: tin cập nhật ngắn; góc viết: thay đổi và điều kiện sử dụng.
- Không có dữ kiện về tốc độ hoặc khả năng dùng trên gói miễn phí.

Write:
"Thư viện X 2.0 cho phép gửi tối đa 100 phần tử trong một batch.
Tính năng này yêu cầu gói trả phí; endpoint vẫn là /v1/items."

Edit:
- Giữ giới hạn 100 và điều kiện gói trả phí.
- Loại câu "nhanh gấp 10 lần" nếu Writer tự thêm.
- Không thêm mở bài dài hoặc kết luận chỉ lặp lại hai câu trên.
```

Bài hướng dẫn có thể cần từng bước/code/ảnh; bài so sánh cần tiêu chí và bằng chứng; tin ngắn có thể chỉ cần vài đoạn. AI chọn cấu trúc theo brief/nguồn, không tự thay yêu cầu người dùng.

### 12.5 Prompt động và yêu cầu cho từng bài

**Prompt hiện tại không chỉ lấy từ `targets.post.content_instructions`:** `PromptRegistry` chọn `ai-agent.prompts` và hướng dẫn theo target; adapter ghép thêm system prompt từ Settings/snapshot, số từ, lựa chọn field, SEO và schema. `instructions` nhập riêng được gửi trong context dưới dạng `additional_instructions`.

Luồng mới tách prompt theo nhiệm vụ, dùng chung các nguyên tắc nhưng không nối một bộ yêu cầu viết bài cuối vào cả ba bước:

```text
Quy tắc nhiệm vụ + nguồn không tin cậy + bảo toàn thông tin
  + WritingBrief của bài này
  + profile/rules/đoạn minh họa đã duyệt
  + input của bước hiện tại và nguồn liên quan
  + schema đầu ra đúng nhiệm vụ
```

- **Quy tắc hệ thống:** an toàn nguồn, facts/attribution, field được phép, không tự quyết định business logic; version riêng từng prompt/schema.
- **Brief từng bài:** ngôn ngữ, người đọc, mục tiêu, loại bài, góc tiếp cận, độ dài và yêu cầu riêng. Người dùng có thể chỉ nhập nguồn/profile; Analyzer suy luận các mục còn thiếu và lưu plan để kiểm tra.
- **Văn phong:** hướng dẫn cách diễn đạt/nhịp câu/từ ngữ, không áp một outline cố định cho tất cả bài.
- **Độ dài:** dùng mức khuyến nghị/range phù hợp nguồn; yêu cầu riêng có ưu tiên. Không kéo bài ngắn thành bài dài bằng lặp ý chỉ để đạt `min_word_count` cứng.
- **Schema:** Analyzer trả knowledge/plan; Writer trả draft; Editor trả final; schema phân tích văn phong độc lập. Chỉ bước tạo final field cần contract Post tương ứng.

Thứ tự ưu tiên: quy tắc hệ thống về dữ liệu/độ chính xác/contract → brief và yêu cầu rõ ràng của bài → profile đã chọn hoặc mặc định website. Nội dung nguồn và bài mẫu là dữ liệu, không được ghi đè chỉ dẫn hệ thống. Nếu yêu cầu riêng mâu thuẫn contract hoặc đòi thông tin không có căn cứ, phản ánh lỗi/issue rõ ràng thay vì bịa để đáp ứng.

Mỗi run lưu prompt key/version, schema version và các input cần tái hiện theo từng bước. Settings `default_system_prompt` vẫn là hướng dẫn chung của website; không dùng nó làm nơi chứa danh sách profile hoặc toàn bộ prompt của mọi nhiệm vụ.

### 12.6 UI chọn văn phong và tạo mẫu từ bài tham khảo

**Khi tạo bài:** select `Văn phong` gồm `Dùng mặc định website` và các profile đã lưu đang bật; textarea `Yêu cầu riêng cho bài` là tùy chọn. Ngôn ngữ/model/nhóm field giữ theo form hiện có. Các tùy chọn audience/loại bài/độ dài có thể nằm trong phần nâng cao, không bắt người dùng điền nhiều select mới để tạo một bài.

Muốn điều chỉnh cách viết cho riêng một bài, người dùng có thể viết tay trong textarea, ví dụ: “Viết ngắn, xưng hô trung tính, giải thích cho người mới, giữ nguyên code”. Muốn dùng lại cách viết đó nhiều lần thì tạo/sửa profile; form tạo bài không cần một ô nhập system prompt đầy đủ.

**Khi quản lý mẫu:** đặt tại Systerm AI → Ai Prompt. Thiết kế gồm tạo thủ công, tạo từ bài tham khảo, xem/sửa/tắt và đặt mặc định; `AiSettings` giữ lựa chọn mặc định website. Admin quản lý rules; người viết chọn profile và thêm yêu cầu riêng. Hiện List/Add, phân tích/duyệt/lưu/đặt mặc định và xem prompt đã có tại 12.19–12.22; tạo thủ công và sửa/xóa/bật-tắt mọi mẫu từ List vẫn chờ UI. Luồng chọn lại profile trong form tạo bài còn mở ở 12.23.

```text
Tạo mẫu mới → Từ bài tham khảo
  → nhập tên mẫu + dán bài
  → bấm Phân tích văn phong → queue phân tích
  → xem bản phân tích, trích đoạn làm bằng chứng và hướng dẫn tổng hợp
  → sửa tên/mô tả/rules/hướng dẫn khi cần
  → bấm Lưu mẫu → profile được lưu database
  → xuất hiện trong select Văn phong khi tạo bài
```

AI phân tích giọng văn/cách xưng hô/mức cảm xúc, cách mở bài, nhịp câu, đoạn và chuyển ý, từ vựng/thuật ngữ, cách dùng heading/bullet/ví dụ/kết luận. Nhận xét có trích đoạn ngắn đúng từ bài; đặc điểm chưa đủ bằng chứng phải ghi chưa rõ, không biến thành quy tắc tuyệt đối. Bài mẫu ở ngôn ngữ khác được chuyển thành hướng dẫn viết tự nhiên trong ngôn ngữ đầu ra.

Ví dụ contract rút gọn, chỉ minh họa thiết kế:

```json
{
  "summary": "Trực tiếp, gần gũi, giải thích bằng tình huống cụ thể",
  "rules": {
    "tone": "Gần gũi, chuyên nghiệp",
    "opening": "Đi thẳng vào vấn đề hoặc thay đổi chính",
    "sentence_rhythm": "Phối hợp câu ngắn với câu giải thích vừa phải",
    "structure_patterns": ["Heading khi đổi chủ đề", "Bullet cho các bước"],
    "avoid": ["Mở bài sáo rỗng", "Kết luận chỉ lặp lại nội dung"]
  },
  "evidence": [
    {
      "feature": "opening",
      "excerpt": "Trích đoạn có thật từ bài người dùng dán",
      "explanation": "Mở bài nêu ngay vấn đề cần giải quyết"
    }
  ],
  "style_instructions": "Hướng dẫn tổng hợp dựa trên đặc điểm có bằng chứng"
}
```

Một lần phân tích tạo profile dùng nhiều lần; khi sinh bài với profile đã lưu không gọi lại AI phân tích văn phong. Đây là trích xuất và tái sử dụng hướng dẫn viết, không phải training/fine-tuning model. Thiết kế ban đầu nhận bài dán; URL/file đơn đã được nối theo yêu cầu tiếp theo tại 12.19–12.20. Nhiều bài mẫu và AI tự chọn profile vẫn để giai đoạn sau.

### 12.7 Database, API và snapshot văn phong

Bảng/field dưới đây mô tả thiết kế dữ liệu ban đầu. Migration/API đã được triển khai tại 12.17; contract hiện tại xem [API mẫu văn phong](AI_WRITING_PROFILES_API.md).

| Dữ liệu | Nội dung dự kiến |
| --- | --- |
| `ai_writing_profiles` | `id`, `name`, `description`, `rules_json`, `evidence_json`, `style_instructions`, `version`, `origin`, `source_hash`, `analysis_metadata_json`, `is_enabled`, `created_by`, timestamps |
| `analysis_metadata_json` | Provider/model thực dùng, prompt/schema version và thông tin phân tích an toàn |
| `AiSettings.default_writing_profile_id` | Profile mặc định website; mở rộng service/API Settings hiện tại |
| `ai_writing_profile_analyses` | Tác vụ phân tích: actor, reference text/hash, connection snapshot không secret, prompt/schema version, status, result, lỗi, timestamps và retention |
| Profile snapshot trong article run | ID/version/rules/hướng dẫn và các ví dụ đã duyệt cần sử dụng; không chỉ lưu ID rồi đọc profile hiện tại ở từng bước |

API dự kiến cung cấp CRUD/bật-tắt/profile options, tạo analysis run, đọc tiến độ/kết quả và lưu profile sau khi người dùng duyệt. Dùng quyền admin/AI hiện có phù hợp với quyền quản lý và quyền sử dụng; kiểm tra quyền ở backend. Validate schema, độ dài, trích đoạn phải có trong bài mẫu và optimistic version khi sửa.

```text
Analysis run: queued → analyzing → ready / failed / cancelled
Profile đã lưu: enabled / disabled
```

Analysis run là tác vụ kỹ thuật độc lập, không phải candidate Post; dùng chung queue/transport/model resolver nhưng không đưa vào danh sách bài AI để Apply. Không tự lưu profile chỉ vì model phân tích thành công: người dùng phải xem/sửa và bấm Lưu. Bài tham khảo có thể hết hạn theo retention tác vụ; profile đã lưu tồn tại độc lập và giữ các trích đoạn đã duyệt.

Sửa/tắt profile không thay đổi run đang chạy hoặc lịch sử. Run mới chỉ chọn profile đang bật. Regenerate mặc định giữ snapshot profile của parent; đổi profile là override rõ ràng. Không mang số liệu, tên sản phẩm, trải nghiệm hoặc kết luận trong bài mẫu sang facts của bài mới.

### 12.8 Ảnh trong content và kết nối TinyMCE–MediaLibrary

Ảnh inline thuộc nội dung bài, khác vai trò thumbnail. Người dùng chọn/upload ảnh ngay trong TinyMCE tại con trỏ; không phải mở thêm một media dialog độc lập bên ngoài editor hoặc chọn lại ảnh trong một gallery thứ hai.

**Contract cập nhật tại 12.41:** Post chèn ảnh bằng link, có thể lặp cùng link ở nhiều vị trí; HTML không tạo quan hệ media. Gallery lưu bộ ảnh riêng có thứ tự qua `post.gallery`. `PostEditor.vue` dùng URL thuần cho Post; candidate AI bật `media-references` để giữ ref nội bộ phục vụ kiểm nguồn và regenerate.

1. Nối `file_picker_callback`/`images_upload_handler` hoặc hook tương đương của editor với API MediaLibrary hiện có; validate loại/kích thước/quyền upload bằng backend.
2. Upload/chọn trả URL public để Post chèn `img`, hoặc người dùng nhập link trực tiếp; alt/caption theo từng vị trí. Candidate AI giữ thêm `data-media-asset-id` trong HTML của bản nháp.
3. Post request/action kiểm URL/markup bằng `ContentImageUrlValidator`, không tạo usage từ content. Gallery nhận `media.gallery_image_ids` và ghi `post.gallery`; candidate AI vẫn kiểm ID/quyền/URL asset tại boundary riêng.
4. Sanitize cho phép `img`, `figure`, `figcaption` và một tập thuộc tính an toàn; chặn event handler/URL nguy hiểm. File upload phải xong trước lưu; không lưu `blob:`/base64 tạm làm URL nội dung cuối.
5. Di chuyển/xóa ảnh trong editor chỉ đổi HTML và không làm đổi Gallery. Cleaner AI đọc URL trong Post trước khi dọn ảnh tạm, không tạo quan hệ content; refs candidate còn retention vẫn được bảo vệ.
6. Thất bại upload/lưu phải hiển thị lỗi và giữ draft; reload, sửa candidate và Apply vẫn giữ ảnh đúng vị trí.

**Regenerate nội dung đã có ảnh:** chuyển ảnh thành placeholder/ref ID được backend cung cấp trước khi gửi text AI. Writer/Editor chỉ giữ/đặt ref được phép; không tự tạo URL hoặc asset ID. Backend khôi phục HTML ảnh từ map đã xác thực, kiểm thiếu/ref không tồn tại và không để bước sanitize làm mất ảnh. Duy trì vị trí hợp lý theo block; người dùng vẫn có thể chỉnh/xóa trong TinyMCE.

**Ảnh trong nguồn URL/HTML:** giữ metadata ảnh gắn với block ngữ cảnh, alt/caption, URL tương đối đã resolve và nguồn lazy-load/srcset khi có. Tách việc ghi nhận ảnh nguồn khỏi việc import file vào MediaLibrary; nếu import từ xa, dùng fetcher/validation/giới hạn hiện có. Chỉ có URL/alt không có nghĩa text model đã nhìn thấy nội dung ảnh. Hiểu screenshot/biểu đồ bằng pixel cần một bước vision riêng nếu triển khai sau.

| Nguồn ảnh | Cách xử lý |
| --- | --- |
| Upload hoặc MediaLibrary | Bắt buộc V1: người dùng chọn trong TinyMCE, backend giữ asset reference và usage |
| Ảnh sẵn trong candidate/Post | Bắt buộc V1: giữ qua sanitize/save/regenerate/Apply; không để AI phát minh ảnh |
| Ảnh từ bài nguồn | Giữ metadata/ngữ cảnh; tự động lựa chọn và import toàn bộ ảnh nguồn là phần mở rộng, không mặc định làm ở V1 |
| Ảnh sinh bằng AI | Có thể dùng `AiImageGenerationService` hiện tại cho minh họa sau; tự chèn ảnh AI trong content để giai đoạn sau |

Bài hướng dẫn cần screenshot thật hoặc ảnh người dùng cung cấp; không sinh hình giả rồi coi đó là giao diện thật của sản phẩm. Nếu mở rộng AI đề xuất vị trí ảnh, AI chỉ trả kế hoạch/ref, backend chọn asset thật và người dùng kiểm tra. Luồng thumbnail generate tại 9.7 vẫn là việc riêng; không gộp thumbnail và inline image thành một field.

### 12.9 Taxonomy hoàn toàn thủ công và tương thích dữ liệu cũ

- Bỏ `suggested_category_ids`, `suggested_tag_ids`, taxonomy alias, taxonomy instructions/schema và nhóm `taxonomy` khỏi lựa chọn AI cho run mới. Cũng không thay bằng AI đề xuất tên/chủ đề rồi backend ánh xạ.
- `category_ids/tag_ids` vẫn là field nghiệp vụ Post nhận từ người dùng; select category/tag dùng catalog hiện có. Backend kiểm tra tồn tại, trạng thái/quyền theo quy tắc Post. Không tự tạo taxonomy mới.
- Khi regenerate, giữ lựa chọn taxonomy thủ công của parent hoặc override hợp lệ; không nhận bất kỳ taxonomy nào từ response model.
- Khi Apply, lấy taxonomy đã được người dùng chọn/xác nhận; AI không quyết định slug, actor hoặc publish status. Slug tiếp tục do business action tạo; status vẫn `draft`.
- Candidate/snapshot cũ vẫn đọc được theo version. Đề xuất AI cũ không mặc nhiên biến thành lựa chọn thủ công đã xác nhận; UI cần người dùng xác nhận/chọn khi Apply. Không sửa ngầm Post đã lưu hoặc dữ liệu lịch sử.
- Run cũ đã queued phải có kế hoạch chuyển phiên bản rõ ràng: drain theo contract cũ hoặc đánh dấu yêu cầu tạo lại; không xử lý snapshot cũ bằng schema mới mà thiếu thông báo.

Không có taxonomy resolver bằng tên trong task 2 mới. Category có phân cấp hoặc tên trùng được giải quyết bằng lựa chọn ID thủ công và validation nghiệp vụ hiện có.

### 12.10 Nhóm Services/Ai theo chức năng và điểm tích hợp

Các folder hiện có sau đợt nhóm file: `Content`, `Providers/Adapters`, `Providers/Catalog`, `Providers/Transport`, `Providers/Diagnostics`, `Images/Adapters`, `Runs`, `Settings`, `Provenance`, `Contracts`, `Registries`, `Targets`. Giữ cấu trúc này; không dựng thêm một cây `app/AI` với provider, queue và settings trùng lặp.

Các file/folder mới dự kiến bổ sung vào hệ thống hiện tại:

```text
app/Services/Ai/
  Content/
    ArticleImportService.php         orchestration nguồn/merge/thumbnail hiện có
    ArticleSourceFetcher.php         fetch và boundary nguồn hiện có
    AiContentSanitizer.php            sanitizer hiện có, bổ sung inline image
    AiOutputValidator.php            validator final hiện có
    Pipelines/
      ArticleGenerationPipeline.php  điều phối AnalyzePlan → Write → Edit
    Agents/
      AnalyzerPlannerAgent.php       đọc hiểu + lập kế hoạch
      WriterAgent.php                viết bản nháp
      EditorAgent.php                biên tập
    Data/                            knowledge/brief/plan/source block DTO
    Prompts/                         builder/template riêng theo nhiệm vụ
    Quality/                         gate/metrics/bảo toàn nguồn
  WritingProfiles/                   CRUD, phân tích bài mẫu, snapshot profile
  Contracts/                         contract AI request/response theo task
  Providers/
    Adapters/                        format API và normalize response
    Transport/                       AiProviderClient/AiConnection dùng lại
    Catalog/                         catalog/ModelResolver dùng lại
    Diagnostics/                     diagnostics dùng lại/mở rộng
  Runs/                              snapshot, progress, checkpoint, cleanup
  Targets/PostAiAdapter.php           candidate → Post, không gọi model
```

Sơ đồ trên là thiết kế folder ban đầu; pipeline/agents/provider DTO và WritingProfiles đã được triển khai tại 12.17 với đường dẫn thực tế trong repository. Controllers/FormRequests/Jobs/Models/migrations nằm ở thư mục Laravel tương ứng, không dồn vào Services. `ArticleImportService::run()` gọi pipeline cho nhánh có `content`; pipeline không tự ghi Post. Agents gọi provider contract dùng chung; transport không chứa luật viết bài. Template/schema đi qua registry/version hiện có thay vì có hai nguồn khai báo khác nhau.

Khi triển khai application files, cập nhật namespace/import/config/tests và comment theo convention project; việc nhóm folder trước đó là phần đã làm, không tạo lại một đợt di chuyển toàn bộ file.

### 12.11 Provider contract và structured output theo nhiệm vụ

Đây là dependency phải làm trước pipeline: adapter hiện yêu cầu field bài viết dạng JSON phẳng nên không thể chỉ thêm prompt Analyzer rồi trả object lồng `knowledge/writing_plan` vào validator Post.

Đề xuất contract task-neutral nhận request DTO gồm `task`, system instructions, input/context, schema, model/options và trả response DTO gồm output + diagnostics/usage. Giữ một bridge cho `generate()` và target cũ trong thời gian chuyển đổi. Provider adapter làm hai việc:

1. Chuyển request chuẩn hệ thống sang format/API provider và gửi qua transport hiện có.
2. Kiểm envelope/finish/refusal/tool call, parse response và chuyển về output/metadata chuẩn theo schema của nhiệm vụ.

Validation nội dung của từng bước nằm ở schema/validator nhiệm vụ; validation Post cuối tiếp tục áp dụng field được chọn. Không để adapter tự chọn outline/profile hoặc thực hiện business action.

| Nhiệm vụ | Schema dự kiến | Dữ liệu chính |
| --- | --- | --- |
| Analyze + Plan | `article.analysis-plan.v1` | Facts/evidence/source refs, thuật ngữ, uncertainties, brief/angle/outline/coverage |
| Write | `article.writer.v1` | Draft field được chọn, refs facts/code/ảnh cần giữ |
| Edit | `article.editor.v1` | Final field được chọn, vấn đề/sửa đổi được giới hạn và lưu riêng |
| Phân tích văn phong | `writing-profile.analysis.v1` | Summary, rules, evidence, style instructions; không có payload Post |
| Title/excerpt/SEO riêng | Schema theo nhiệm vụ/target | Chỉ field thực sự được yêu cầu |

Metadata/intermediate outputs không trộn vào `result_json.draft` public. Output final canonical vẫn phù hợp `PostAiAdapter`. Tăng prompt/schema version khi thay contract và snapshot version từng bước.

Adapter hiện dùng JSON mode/MIME JSON ở các provider tương ứng; không coi đó là server đã cưỡng chế toàn bộ JSON Schema. Khi provider/model hỗ trợ schema thực sự thì gửi schema đúng capability; trường hợp không hỗ trợ dùng prompt + parse/validation chặt. Không giả định mọi model đều có cùng capability.

### 12.12 Queue, checkpoint, progress và retry

V1 dùng job hiện có điều phối ba bước tuần tự. Controller chỉ tạo/đọc/hủy tác vụ; không gọi pipeline ba lượt đồng bộ trong HTTP request. Chỉ tách job mỗi bước nếu budget hoặc vận hành thực tế cần; không dựng thêm queue framework.

**Dữ liệu kỹ thuật cần lưu:**

- Source snapshot/hash và block map sau extract, trước lượt Analyze; snapshot options/profile/model/settings.
- Prompt key/version, schema version, input hash, provider/model thực dùng và output đã validate của từng bước.
- Step status/attempt/start/end, usage/token, latency, checkpoint và lỗi an toàn theo allowlist.
- Kết quả cuối trong `result_json.draft`; intermediate ở storage riêng/metadata bảo vệ. Có thể thêm `ai_import_steps` để lưu attempt/output/checkpoint thay vì phình public JSON; contract lưu trữ phải chốt ở đợt 1.
- Chính sách retention/giới hạn kích thước cho source và step artifacts; không lưu API key/header xác thực hoặc raw response không kiểm soát.

Snapshot kỹ thuật này thuộc task 2 để run có thể tái hiện/resume. Lưu nguồn lâu dài cùng bản biên tập, compare UI và `ai_content_drafts` vẫn thuộc mục 3; không mặc nhiên biến retention hai ngày của `ai_imports` thành nơi lưu bài lâu dài.

**Progress dự kiến:** `queued → fetching → extracting → analyzing/planning → writing → editing → validating → thumbnail nếu chọn → ready`, cùng terminal `failed/cancelled/expired`. Có thể giữ status kỹ thuật tổng quát và dùng `current_step` cho các bước; không thêm enum rời rạc chỉ ở frontend. Cập nhật chung controller cancel/remove/regenerate, cleanup, danh sách trạng thái đang chạy và UI/polling. Progress phản ánh bước đang chạy, không giả thành phần trăm token hoặc thời gian còn lại chính xác.

**Timeout:** công thức hiện tại `max(job_timeout, requestTimeout + 120)` được thiết kế cho một request. Ba request tối đa 600 giây có thể vượt `retry_after=900`; phải tính tổng budget extract + ba bước + validate/media + margin, hoặc chia job. Bảo đảm request/step budget nằm trong job budget và worker/job timeout nhỏ hơn queue `retry_after` để tránh xử lý trùng. Cấu hình production/process manager vẫn cần kiểm chứng riêng tại 2C.

**Hủy/retry:** kiểm cancellation ở mỗi ranh giới bước, lưu checkpoint sau validate, tránh cập nhật `ready` hoặc tạo asset sau khi tác vụ đã hủy. Khóa/claim run và kiểm version để hai worker/attempt không ghi đè. Không giữ transaction DB mở xuyên qua HTTP provider.

Resume chỉ dùng lại bước hoàn thành nếu source/profile/prompt/schema/options hashes khớp. Không fetch lại URL đang chạy rồi tiếp tục với nội dung khác. Regenerate mặc định dùng source snapshot của parent còn giữ được; nếu snapshot đã hết hạn thì yêu cầu lấy nguồn mới và tạo snapshot/run mới rõ ràng. Thay params/profile hoặc bắt đầu lại từ đầu tạo child/new run.

Giữ chính sách timeout/provider error hiện có: không blind retry một request có thể đã tính phí. JSON/schema/quality error không tự lặp gọi model để che lỗi; cho retry thủ công có chủ ý. Nếu sau này thêm retry lỗi transient hoặc revision loop thì giới hạn attempt, ghi cost/usage và không chạy lại bước hợp lệ khi đủ điều kiện resume.

### 12.13 Quality gate và giới hạn đánh giá tự động

Gate chạy **sau validate/sanitize output AI mới, trước merge nguồn/parent và trước thumbnail**. Không chấm draft đã merge vì field không chọn vốn được giữ từ nguồn. Không áp rewrite gate cho deterministic, thumbnail-only, code/trích dẫn được giữ đúng hoặc nhiệm vụ ngắn không tương đương viết lại toàn bài.

| Kiểm tra | Quy tắc V1 dự kiến |
| --- | --- |
| Contract | Kế thừa task 1, cộng schema/ref validity cho từng bước; selected final field phải đúng kiểu và không rỗng |
| Sao chép | Normalize văn xuôi HTML/entity/Unicode/whitespace; exact-copy phần văn xuôi đáng kể báo lỗi chất lượng. Tách code/quote/tên riêng khỏi phần đánh giá; đoạn quá ngắn có trạng thái bỏ qua/chưa xác định |
| Similarity | Metric hỗ trợ phát hiện sao chép trong cùng ngôn ngữ; hiệu chỉnh theo bộ bài mẫu. Không áp cứng `60%`, không dùng độ giống thấp làm mục tiêu của Writer |
| Ngôn ngữ | Detector trên văn bản mới; xử lý đoạn ngắn/nhiều code/tên riêng là chưa xác định. Không suy ra chỉ từ việc có dấu tiếng Việt |
| Bảo toàn nguồn | Kiểm source IDs/excerpt/coverage, dữ kiện số/phiên bản/điều kiện và code/link/quote quan trọng theo ledger tối thiểu; báo thiếu/khác với bằng chứng cụ thể |
| HTML và ảnh | Whitelist an toàn, ref asset hợp lệ, link/quote/code/table không mất qua sanitize, ảnh không bị AI tự tạo URL |

Không coi so khớp chuỗi/regex là kiểm chứng ngữ nghĩa hoàn chỉnh. Dịch khác ngôn ngữ có similarity thấp vẫn có thể rất máy móc. Editor/AI evaluator tự chấm không phải bằng chứng độc lập về tính đúng; facts có lỗi là lỗi riêng, không được giấu trong điểm tổng hợp tốt.

Lưu kết quả từng kiểm tra `pass/fail/skipped/undetermined`, lý do, stage và metric an toàn để editor hiểu. Lỗi chặn dùng trạng thái kỹ thuật `failed` cùng mã quality/schema rõ ràng, không tạo trạng thái nghiệp vụ `quality_failed` riêng chưa được thống nhất. Những nhận xét văn phong chủ quan có thể là warning để người dùng biên tập, không tạo cổng chặn dựa trên một điểm AI tự chấm.

Mã lỗi cụ thể, ngưỡng similarity/language, minimum prose length, danh sách ngoại lệ và mức độ chặn từng kiểm tra cần hiệu chỉnh bằng fixtures trước rollout. Quy tắc dự kiến cho exact-copy không cấm giữ nguyên code, trích dẫn hay thuật ngữ chính xác.

### 12.14 Thứ tự triển khai task 2

Các đợt dưới đây là thứ tự triển khai, không phải danh sách tất cả việc đang thiếu. Các đợt 1–6 và nghiệm thu kỹ thuật đã hoàn tất tại 12.35. Đợt 7 chấm người đọc/rollout được chủ dự án tách riêng. So sánh một lượt/ba lượt và switch rollout trong thiết kế cũ đã được thay bằng C bắt buộc tại 12.33.

| Đợt | Công việc | Kết quả kiểm tra được |
| --- | --- | --- |
| 1 — Contract và nguồn | Loại taxonomy AI; chốt source blocks/snapshot, brief, schema/DTO task-neutral và vị trí lưu step artifacts; nâng adapter có bridge tương thích | Không model response nào quyết định taxonomy; adapter xử lý được schema intermediate; nguồn giữ facts/code/ref |
| 2 — Văn phong | CRUD/profile options/default setting; analysis run bài dán, preview/edit/save, permissions, version và snapshot | Dán bài tạo mẫu, lưu database, chọn lại khi tạo run; facts bài mẫu không sang bài mới |
| 3 — Pipeline | AnalyzerPlanner, Writer, Editor, prompt từng bước và ArticleGenerationPipeline; nhánh title/excerpt/SEO/media-only và merge theo lựa chọn | Tạo nội dung bằng ba bước; field không chọn và parent giữ đúng; final qua adapter Post hiện có |
| 4 — Vận hành | Checkpoint/attempt/progress, cancel/resume, hashes, timeout/retry_after, diagnostics/usage và cleanup | Không dùng lại checkpoint sai input hoặc chạy trùng; reload xem được tiến độ/lỗi từng bước |
| 5 — Quality | Exact-copy/similarity/language/grounding/ref gates, ngoại lệ và lỗi/warning có lý do | Output mới được kiểm trước merge/thumbnail; không có fallback che selected field sai |
| 6 — TinyMCE và media | Picker/upload trong editor, sanitizer ảnh, asset refs/usage, regenerate bảo toàn inline image | Chèn ảnh tại con trỏ, lưu/reload/Apply/regenerate giữ ảnh; không cần chọn lại ngoài editor |
| 7 — Đánh giá chất lượng C, đợt riêng | Người đọc chấm facts/văn phong/công sửa trên nguồn/profile/model cố định; theo dõi cost/latency. Study B/C chỉ giữ lịch sử | Có điểm người đọc và quyết định owner trước kết luận chất lượng/rollout; không giữ Task 2 kỹ thuật mở |

Budget/timeout/cancellation tối thiểu phải có cùng đợt 3 trước khi chạy ba request thật; đợt 4 hoàn thiện resume/quan sát và production checks. Gate contract/ref validity chạy ngay mỗi bước, không đợi đợt 5 mới validate intermediate. Bộ bài mẫu được chuẩn bị từ đợt 1 để không đánh giá chỉ bằng bài dễ.

Cập nhật phạm vi tại 12.33: chỉ C tạo toàn bộ bài. B không còn là baseline
chạy mới hoặc lựa chọn rollout; không fallback sang B hoặc deterministic khi
C lỗi rồi báo thành công. Kết quả B/C cũ vẫn được đọc từ artifacts. Hiệu chỉnh
tiếp tập trung vào facts, diễn đạt và công sửa của C, giữ lỗi thật để đánh giá.

### 12.15 Tiêu chí nghiệm thu và kiểm thử

**Chức năng bắt buộc:**

Các ô dưới đây phản ánh nghiệm thu kỹ thuật tới 12.35, kết hợp tests, browser và model thật. Tiny Cloud đã hoạt động trên `http://localhost:8000`; bằng chứng mới tách rõ với MySQL/fallback editor của 12.24. Chấm người đọc bên dưới thuộc đợt riêng theo quyết định của chủ dự án.

- [x] URL/text/HTML normalize, source snapshot/anchors, code/table/link/quote/form; fixture tests và file Ovation trên browser localhost.
- [x] `content` chạy Analyze + Plan → Write → Edit; nhánh ngắn/thumbnail-only không gọi thừa. Ba bước và excerpt-only đã chạy model thật.
- [x] Select profile/mặc định, brief/yêu cầu riêng và snapshot/version; regenerate giữ snapshot cũ, override rõ ràng.
- [x] Phân tích văn phong thật từ nguồn tham khảo → 13 dẫn chứng → sửa/lưu → List/select; CRUD thủ công/quyền/version có regression.
- [x] Không AI taxonomy; danh mục/tag thủ công giữ qua edit/regenerate/Apply vào Post #6, legacy/version được kiểm.
- [x] Chèn/upload/sửa/di chuyển/xóa/undo ảnh trong Tiny Cloud thật qua MediaLibrary; save/reload/Apply giữ asset ID/URL/alt/caption/vị trí tại 12.35. Regenerate giữ ảnh có backend/frontend regression và QA browser/API kế thừa tại 12.24; không gọi regenerate mới từ fixture QA editor.
- [x] Gates kiểm output mới trước merge/thumbnail; ngoại lệ code/quote/đoạn ngắn và parent preservation có regression; lỗi thật hiện sau reload, không fallback che lỗi.
- [x] Queue budget/cancel/checkpoint/resume/claim kiểm bằng tests và MySQL/cache probe; progress/usage sau reload. Windows hard-kill và production checks vẫn giới hạn tại 12.24/2C.
- [x] Apply vào Post draft, actor/provenance/media usage đúng; regression Post/Resource/Sound đạt.

**Regression cần có khi triển khai:** HTTP fake cho adapter từng schema/finish reason/refusal/tool calls/JSON lỗi; output từng bước sai schema/ref hoặc thiếu selected field; snapshot/hash/version; facts có số liệu/điều kiện/code/link/quote; prompt injection từ nguồn/bài mẫu; title/SEO-only và partial regenerate; profile CRUD/quyền/disable/concurrency; lifecycle cancel/timeout/resume; HTML ảnh/quyền asset/URL mismatch/usage/cleanup và UI TinyMCE. Automated tests không gửi request AI thật.

**Đánh giá chất lượng — đợt riêng sau code:** chủ dự án đã chọn chuyển hai
người đọc chấm sang đợt riêng ngày 2026-10-05. Tiêu chí này không giữ nghiệm
thu kỹ thuật Task 2 mở; điểm facts/văn phong/công sửa và quyết định rollout
vẫn chưa được chốt. Không dùng tests/gate hoặc AI tự chấm để điền phiếu.
Corpus v2 đã freeze 20 nguồn và study thật tại 12.32;
coverage/facts chuẩn vẫn cần người đọc xác nhận. Từ 12.33, phiên mới chỉ chạy
C trên nguồn/brief/profile/model cố định. Người đọc đối chiếu nguồn và chấm
độc lập; phiếu C có một ứng viên mỗi ca. Study so sánh B/C cũ giữ lịch sử,
không chạy lại B hoặc bắt buộc phục hồi baseline A để nghiệm thu C.

Chấm tự nhiên tiếng Việt, đúng/đủ facts và điều kiện, hữu ích với người đọc, lặp ý/bố cục, mức chỉnh sửa cần thiết; ghi riêng lỗi bịa/mất thông tin, thời gian và token/chi phí. Một bài trôi chảy nhưng sai điều kiện sử dụng không được coi là đạt nhờ điểm văn phong cao. Ghi rubric và kết quả, hiệu chỉnh threshold trên bộ này rồi mới quyết định rollout.

Browser QA có đăng nhập cần kiểm tạo bài/profile, tiến độ từng bước, reload/lỗi/cancel/regenerate, chọn taxonomy thủ công, chèn/upload/di chuyển/xóa ảnh và Apply thành Post draft. Chạy test/lint/build phù hợp phần code thực sự thay đổi; không cần test/build cho riêng đợt cập nhật tài liệu này.

### 12.16 Những phần để sau và điểm cần hiệu chỉnh

**Không bắt buộc V1:** ResearchAgent/FactChecker tra cứu ngoài nguồn, AI QualityEvaluator và revision loop, Fact Ledger đầy đủ nhiều nguồn, SEO agent riêng, tự route model theo bước, tự chọn profile, phân tích nhiều bài mẫu, vision ảnh, tự import ảnh nguồn hoặc sinh/chèn ảnh AI trong bài. URL/file đơn cho Ai Prompt đã có theo 12.19–12.20. Khi mở rộng revision cần giới hạn lượt và tổng cost, không lặp đến khi model tự chấm đạt.

**Cần hiệu chỉnh khi triển khai:** ngưỡng quality và ngoại lệ, source/token budget, retention step artifacts, tên schema/field/API cuối cùng, model và sampling phù hợp từng bước, timeout theo môi trường. Đây là thông số triển khai còn mở; không thay đổi quyết định taxonomy thủ công, profile do người dùng chọn và luồng draft.

**Theo dõi ở phần khác của FIX 1:** `ai_content_drafts`/approve-reject/lưu dài hạn tại mục 3; thumbnail generate tại 9.7; Reverb/VPS tại 2C; chín tab Settings còn lại tại mục 9. Task 2 dùng chung hạ tầng, không coi các nhóm đó đã hoàn thành hoặc dựng workflow trùng lặp.

**Điểm tiếp tục:** giữ bằng chứng Task 1 tại 11.6. Nội dung 12.1–12.16 mô tả thiết kế/tiêu chí; báo cáo backend ở 12.17, phạm vi nối API Ai Prompt ở 12.18 và báo cáo đợt nối ở 12.19. Những mô tả trang trống trong các mốc ban đầu là lịch sử trước khi custom UI.

### 12.17 Báo cáo triển khai backend — 2026-10-04

**Phạm vi đã chốt:** người dùng sẽ tự thêm giao diện Ai Prompt và UI mới; đợt này xây backend để giao diện chỉ cần kết nối API. Trang `/admin/ai/prompt` vẫn trống. Chỉnh frontend hiện có chỉ phục vụ tương thích status/contract, không dựng UI quản lý mẫu hoặc TinyMCE picker mới.

| Nhóm | Backend đã làm | Phần còn mở |
| --- | --- | --- |
| Nguồn | URL/text/raw HTML/file HTML, MIME/encoding/byte budget; preview API; extractor giữ form content/code/table/link/quote; source blocks/hash/version | Giao diện upload HTML nguyên bản và preview |
| Văn phong | CRUD/version/enable/default, options API, phân tích bài tham khảo qua queue với evidence, người dùng duyệt mới lưu, snapshot profile | Trang quản lý/phân tích và select/textarea |
| Pipeline | `AiTaskRequest/Response`, provider `execute()`, AnalyzerPlanner/Writer/Editor, schema và source refs từng bước; title/SEO ngắn và Resource/Sound giữ nhánh phù hợp | QA provider thật và hiệu chỉnh prompt/threshold |
| Taxonomy | Bỏ khỏi generation; ID active thủ công; edit/regenerate/Apply giữ lựa chọn, legacy AI cần chọn/xác nhận lại | Nối category/tag thủ công trong UI mới |
| Queue | `ai_import_steps` có hash/output/diagnostics, resume chỉ checkpoint hợp lệ, source/profile/config/parent baseline bất biến; terminal updates có điều kiện; timeout/lease/cleanup | Worker/cache/SQS/process manager trên VPS |
| Chất lượng | Schema/selected fields/source anchors/important facts, copy/language/code/link/numbers gates trước merge/media; warning và trạng thái chưa xác định công khai | Corpus nguồn thật và chấm mù chất lượng/ngữ nghĩa |
| Ảnh/Apply | MediaLibrary ID/URL/quyền, content usage suy từ HTML, placeholder/ref giữ ảnh regenerate, bảo vệ ảnh được tham chiếu, lock khi attach/xóa; candidate và Post version check; Post vẫn draft/provenance | TinyMCE chọn/upload tại con trỏ và browser QA sau khi nối UI |

Luồng mới:

```text
AiImportController::store → AiRunService::create (snapshot + queue)
  → ProcessAiImportJob::handle → ArticleImportService::run
  → fetch/inline → ArticleSourceExtractor → source snapshot
  → ArticleGenerationPipeline::run
      → AnalyzerPlannerAgent → provider::execute → schema/evidence → checkpoint
      → WriterAgent → provider::execute → schema/references/selected fields → checkpoint
      → EditorAgent → provider::execute → schema/important facts → checkpoint
      → quality gates + khôi phục ảnh theo ID → final fields
  → merge chỉ fields được chọn → thumbnail tùy chọn → ready candidate
  → PATCH có expected_version → Apply có khóa/version
  → PostAiAdapter → Create/UpdatePostAction → Post draft + provenance
```

API cho giao diện: [tạo bài/polling/regenerate/Apply](AI_ARTICLE_PIPELINE_API.md), [mẫu văn phong/phân tích bài tham khảo](AI_WRITING_PROFILES_API.md), [ảnh nội dung/MediaLibrary](TASK2_INLINE_MEDIA_API.md). Folder nghiệp vụ tách theo `Content/Agents`, `Content/Pipelines`, `Content/Prompts`, `Content/Quality`, `WritingProfiles`, `Runs`, `Providers`, `Images`; controller/FormRequest/resource/job/model theo Laravel. Comment file/method tiếng Việt có khung `===`, inventory và INPUT/OUTPUT.

Đã migrate bốn migration mới vào MySQL local: profiles, analyses, default setting, step checkpoints; scheduler có cleanup analysis cùng cleanup run hiện có. Không gọi model trả phí khi regression. SQLite kiểm contract/policy khóa, không thay cho kiểm concurrency nhiều worker trên MySQL/Redis thực tế.

**Kiểm chứng cuối:** lượt regression PHP đạt **213 tests / 1687 assertions**, chạy PHPUnit trực tiếp với GD để không bỏ ca thumbnail. Sau sửa bảo toàn ảnh của baseline, kiểm lại pipeline **34 tests / 237 assertions** và nhánh Settings/provider/thumbnail **28 tests / 298 assertions**, đều đạt; các lượt kiểm lại có test trùng, không cộng thành tổng ca duy nhất. Frontend toàn bộ **35 file / 206 tests** đạt. Pint scoped **62 file** và ESLint scoped đạt; production build thành công. Audit comment **80 file PHP ứng dụng, không thiếu header/inventory/method INPUT/OUTPUT**; comment file/hàm Vue/JS đã sửa cũng được cập nhật. Không chạy browser QA mới hoặc AI trả phí; warning từ component stub trong test/template build không phải bằng chứng UI đã nghiệm thu.

**Chưa nghiệm thu chất lượng thực tế:** [protocol đánh giá](AI_ARTICLE_QUALITY_EVALUATION.md) mô tả A/B/C, 25 ca nguồn, rubric, công biên tập, token/latency/cost. Fixture tổng hợp offline dùng để kiểm kỹ thuật; chưa phải 25 nguồn thật đóng băng, chưa có chấm mù hoặc kết quả chứng minh ba bước viết hay hơn. UI mới, browser QA và rollout production còn mở; không đánh dấu toàn bộ Task 2/FIX 1 hoàn tất.

### 12.18 Plan nối API Ai Prompt — 2026-10-04

Đã lập [plan nối API Ai Prompt](PLAN_AI_PROMPT_API.md). Sau đó người dùng yêu cầu hoàn tất nối API cho trang hiện tại, giữ dữ liệu tĩnh ở những khối chưa có API và để trang danh sách văn phong sang đợt sau. **Mục 0 của plan thay thế phạm vi cũ ở mục 1–8**; bản thiết kế dài vẫn giữ để tham khảo cho đợt mở rộng. Đợt nối hiện tại không tạo endpoint hoặc migration mới.

**Giao diện đã có:** page và các component nguồn/kết quả/insights được custom từ code người dùng đưa, giữ bố cục hai cột, theme Vuetify và icon Tabler. Editor dán dùng Tiptap hiện có; URL/file xem/sửa văn bản chuẩn riêng. Ô **Tên văn phong** phía trên ba tab nối `name` vào analysis/profile. QA tại [báo cáo UI](qa/AI_PROMPT_UI_2026-10-04.md) là bằng chứng của bản preview trước khi nối API; kiểm chứng mới theo dõi ở 12.19.

**Luồng đã nối:** tên mẫu + dán bài/đọc TXT hoặc preview URL/HTML → POST analysis → GET polling → xem/sửa quy tắc và dẫn chứng → người dùng POST lưu profile → PUT sửa mẫu vừa lưu theo version → Settings partial đặt/gỡ mặc định. Phân tích văn phong dùng một request model; không chạy pipeline tạo bài ba bước ở trang này.

**Tên văn phong:** do người dùng nhập, bắt buộc khi phân tích/lưu và tối đa 160 ký tự; không tự lấy tiêu đề bài hoặc để AI đặt tên. Tên được gửi thành `name` cho analysis, kế thừa vào form duyệt rồi cho sửa trước khi lưu profile. Đổi tên profile không làm thay snapshot tên của analysis.

| Nhóm | Công việc theo phạm vi mới | Trạng thái |
| --- | --- | --- |
| API hiện có | Catalog/model, nguồn, POST analysis, GET detail/polling, POST cancel, resume UUID | Đã nối; kiểm lifecycle bằng HTTP mock |
| Duyệt/lưu mẫu hiện tại | POST/PUT profile, GET version/recover save, Settings default partial, copy/export dữ liệu `ready` | Đã nối; regression đạt |
| URL/HTML preview | Dùng `/ai-agent/source-preview` với `target_type=post` và `posts.manage`; dán/TXT dùng được chỉ với `ai_settings.manage` | Giữ quyền backend hiện có |
| Lịch sử/nguồn cũ | GET list analysis và GET source chưa có | Giữ lịch sử minh họa; chưa đọc lại nguồn |
| Điểm và các tab ngoài schema | Điểm/% văn phong/SEO/ảnh/tính độc đáo chưa có API | Giữ dữ liệu tĩnh, nhãn minh họa rõ |
| Danh sách/quản lý văn phong | Trang hoặc dialog list/search/pagination/CRUD toàn bộ | Để sau theo yêu cầu người dùng |
| API backend mở rộng | Preview riêng quyền AI, lịch sử/source, public source_hash, idempotency/migration | Để sau; không triển khai trong đợt này |
| Kiểm chứng | Tests phù hợp, lint/build và browser QA sau nối API | Đạt phạm vi kỹ thuật tại 12.19; chưa gọi model thật |

**Các quyết định:** giữ bố cục và các khối minh họa chưa API theo yêu cầu mới; gắn nhãn để không nhầm với summary/rules/evidence/style_instructions thật và không gửi chúng khi lưu/tải báo cáo thực. Nguồn đã gửi là snapshot trong bộ nhớ của lượt phân tích; ready chưa có nghĩa đã lưu mẫu. Session chỉ nhớ metadata UUID/hạn lưu/profile ID và cờ/tên của POST lưu bất định theo tài khoản/analysis, không lưu bài nguồn; resume GET detail không khôi phục bài mẫu. Mở UUID khác phải xác nhận bỏ form chưa lưu. POST không tự retry; kiểm tra POST profile bất định chỉ GET và giữ bản sửa, không tuyên bố server đã có idempotency. Select mẫu/brief ở form tạo bài vẫn là bước tiếp theo.

- [x] Đối chiếu giao diện và backend, lập plan chi tiết nối API.
- [x] Cập nhật phạm vi: dùng endpoint hiện có; thiếu API giữ tĩnh; danh sách văn phong để sau.
- [x] Hoàn thiện kết nối và kiểm chứng phần hiện tại; báo cáo 12.19.
- [x] Đợt nối tiếp: List/Add và datatable đã triển khai tại 12.21.
- [ ] Đợt sau: sửa/xóa mọi mẫu từ danh sách, lịch sử/source và API backend mở rộng.
- [ ] Nối tiếp select văn phong/brief và mẫu mặc định trong các form liên quan.

### 12.19 Nối API Ai Prompt theo phạm vi mới — 2026-10-04

**Trạng thái:** đã hoàn tất nối API hiện có và kiểm chứng phạm vi kỹ thuật. Phạm vi cập nhật theo yêu cầu “những mục nào chưa có API thì tạm thời để data tĩnh”; trang danh sách văn phong để sau. Tài liệu API hiện có: [mẫu văn phong](AI_WRITING_PROFILES_API.md); quyết định thay thế thiết kế cũ: [plan mục 0](PLAN_AI_PROMPT_API.md#0-phạm-vi-thay-thế-theo-yêu-cầu-mới-của-người-dùng). Toàn bộ Task 2/FIX 1 và chất lượng model thật vẫn chưa hoàn thành.

| Phần đã nối | Hành vi và giới hạn |
| --- | --- |
| Service/composable | Tầng HTTP riêng; composable nguồn/catalog, analysis lifecycle và form/profile; page dùng props/models/emits với component |
| Nguồn | Dán HTML chuyển text; TXT UTF-8 đọc local; URL/HTML dùng preview Post với quyền `posts.manage`; đổi lựa chọn hủy trạng thái đã đọc |
| Phân tích | POST một lần theo click; poll GET backoff; cancel/resume GET bằng UUID; hiển thị lỗi field/queue/provider/owner/expiry |
| Kết quả | Summary văn phong, rules, evidence và style instructions từ result thật; nguồn đã gửi tách khỏi nguồn đang sửa |
| Lưu/sửa | Người dùng duyệt rồi POST; PUT mẫu vừa lưu cần version; xung đột giữ bản sửa và cho GET bản mới theo thao tác rõ ràng |
| Mặc định | Settings partial chỉ field default; lưu mẫu thành công nhưng default lỗi được báo riêng |
| Reload | Session metadata theo actor/UUID/profile ID và pending name khi POST lưu bất định; GET lại result/profile, không khôi phục raw source và không lưu nguồn vào storage |
| Kiểm tra lưu bất định | GET profile nếu biết ID; nếu chưa có ID thì GET list một trang tối đa 100 theo tên, đối chiếu exact name + analysis ID. Một mẫu duy nhất mới ghi nhận ID/version; nhiều/không có mẫu hoặc còn trang khác giữ khóa POST, giữ bản sửa |
| Copy/export | Clipboard/Markdown từ kết quả `ready` và bản đã duyệt/sửa; không đưa điểm hoặc nhận xét mock vào báo cáo thật |
| Chưa API | Lịch sử list và điểm/%/SEO/ảnh/tính độc đáo giữ tĩnh, có nhãn minh họa; không thực hiện API giả |
| Để sau | List/page/dialog quản lý tất cả văn phong; GET history/source và idempotency backend; select/brief form tạo bài |

**Không thay backend:** không endpoint mới, không migration/bảng mới, không public source hash mới hoặc request key mới. Không mở quyền preview Post. Không tự retry mutation khi kết quả chưa rõ và không tự lưu profile sau analysis ready. Việc khóa gửi ở client chưa phải bảo đảm idempotency của server.

**Kiểm chứng:** toàn frontend đạt **38 file / 238 tests**, gồm **32 tests Ai Prompt**: API 4, flow 20, UI 8. Scoped ESLint page/components/composables/service/utils và ba file test đạt. Production build đạt **31.40 giây**, còn warning asset `section-title-icon.png` đã có trước.

Browser localhost xác nhận catalog thật, validation nguồn rỗng, nhập tên/dán bài 24 từ, tab URL/file, lịch sử minh họa và ô UUID. Viewport 390/1440 không tràn ngang sau khi layout ổn định; console không có lỗi. Báo cáo/screenshot: [QA nối API Ai Prompt](qa/AI_PROMPT_API_2026-10-04.md). Browser không gọi model hoặc mutation profile; lifecycle/result/save/version/default/recovery được kiểm bằng HTTP mock. Bằng chứng preview ở 12.18 và backend ở 12.17 là các mốc trước riêng biệt.

**Giới hạn nghiệm thu:** không gọi model trả phí; chưa đánh giá chất lượng nhận xét văn phong với bài/model thật hoặc chạy luồng browser gọi model rồi lưu Post/profile thực. GET phục hồi POST lưu bất định là kiểm tra mẫu hiện tại, không triển khai trang danh sách. Manual resume UUID khác yêu cầu xác nhận bỏ form chưa lưu. Các kết quả trên không đánh dấu toàn bộ Task 2/FIX 1 đã xong.

- [x] Chốt và ghi phạm vi mới; giữ hợp đồng backend đang có.
- [x] Hoàn thiện wiring và kiểm chứng chức năng API bằng HTTP mock phù hợp.
- [x] Ghi kết quả lint/build/browser QA sau khi chạy thực tế.
- [ ] Nghiệm thu chất lượng phân tích văn phong với bài/model thật ở đợt riêng.

### 12.20 Sửa chọn nhầm footer khi đọc HTML trong Ai Prompt — 2026-10-04

**Đã tái hiện và sửa:** file Ovation người dùng cung cấp có bài trong `div#article-content`, footer dùng `div.footer/div.copyright/section`. Extractor cũ chỉ xét article/main/section và chọn section bản quyền, trả 303 ký tự footer. Bộ lọc dùng chung nay ưu tiên schema `articleBody` và nhãn body phổ biến, xét div prose khi fallback, giảm điểm vùng nhiều link, loại chrome dùng div/role và bỏ ứng viên rỗng. Giữ nội dung copyright nằm trong bài, form/code và sanitizer; không hardcode website hoặc gọi model để đọc nguồn.

**Kiểm chứng:** extractor/import/pipeline đạt **38 tests / 242 assertions**; API Task 2 đạt **14 tests / 75 assertions**, gồm upload file HTML với bố cục lỗi; Pint scoped ba file đạt. File thật sau sửa có 5449 ký tự khi strip tags thay vì footer; upload trực tiếp qua Ai Prompt hiện **1117 từ**, đủ đầu/cuối và tám mục bài, không có `GPDKKD`. Browser chỉ đọc preview, không chạy AI/lưu mẫu. Báo cáo/screenshot: [QA nguồn HTML](qa/AI_PROMPT_SOURCE_2026-10-04.md).

Nhận diện vẫn là heuristic cho các website/layout khác nhau; người dùng xem/sửa text preview trước khi phân tích. UI chọn vùng DOM/CSS selector thủ công hoặc cấu hình selector theo website chưa triển khai. Chỉ sửa backend/test trong đợt này; hợp đồng API và migration giữ nguyên.

### 12.21 Ai Prompt List/Add và datatable mẫu văn phong — 2026-10-04

**Đã hoàn tất theo yêu cầu mới:** Systerm AI → Ai Prompt có hai mục **List** và **Add** ở menu dọc/ngang. List tại `/admin/ai/prompt/list` đọc các profile đã được duyệt/lưu; Add tại `/admin/ai/prompt/add` dùng giao diện phân tích hiện tại. URL cũ `/admin/ai/prompt` redirect về List. Hai trang có nút điều hướng qua lại và cùng quyền `ai_settings.manage`.

**Datatable:** GET `/api/admin/ai/writing-profiles` với `page`, `per_page`, `search` theo tên. Hiển thị tên/mô tả, nguồn `reference`/`manual`, bật/tắt, version, ngày cập nhật và nút mở prompt `style_instructions`. Tổng dòng lấy từ `meta.pagination.total`; số dòng 15/25/50/100, tìm kiếm debounce 300 ms và đưa về trang đầu. Có loading/lỗi/rỗng/tải lại; abort và sequence guard loại phản hồi cũ khi đổi query/rời trang. Nội dung prompt render text được escape. Cột không cho sort vì API hiện sắp theo tên/ID.

**Kiến trúc/comment:** route List/Add ghép `AiPromptList`/`AiPromptCreate`; giao diện bảng/bộ lọc dùng props và `defineModel`, `useAiPromptList` quản lý GET/query, service giữ envelope phân trang. Luồng Add giữ nguồn/preview, queue/poll/cancel/resume, duyệt/lưu/version/default/copy/export và xác nhận form chưa lưu. File tạo/sửa có header, inventory và INPUT/OUTPUT tiếng Việt với dấu `===`. Dùng API và bảng hiện có, không thêm endpoint/migration.

**Kiểm chứng:** frontend **39 file / 250 tests** đạt; Ai Prompt **4 file / 44 tests** gồm API 5, flow 20, UI Add 8, list 11. Các ca List kiểm tổng server, page/size/search/clear, race success/error, cleanup, danh sách thu nhỏ, lỗi/tải lại, prompt escape, liên kết Add và footer Vuetify thật. Sau sửa hiển thị khoảng dòng, kiểm lại 11 tests List đạt. ESLint scoped đạt; build cuối đạt **1 phút 4 giây**, 2857 modules, còn warning asset `section-title-icon.png` đã có trước.

**Browser localhost:** đọc mẫu thật **Bài Viết Du Lịch**, mở prompt, tìm “Du Lịch”, từ khóa không có kết quả, xóa lọc/tải lại, chọn 25 dòng, List → Add → List và URL cũ → List. Add vẫn có tên/model/editor và ba tab nguồn. Desktop 1440 × 1000, mobile 390 × 844 không tràn ngang sau layout ổn định. Không ghi nhận console error; không gọi model hoặc tạo/sửa/xóa profile trong đợt QA này. Báo cáo và ảnh: [QA List/Add](qa/AI_PROMPT_LIST_2026-10-04.md).

- [x] Tách route/menu List và Add, tương thích URL cũ.
- [x] Nối datatable profile đã lưu bằng API thật, tìm tên/phân trang/xem prompt.
- [x] Giữ luồng phân tích/duyệt/lưu hiện có ở Add và comment theo project.
- [x] Kiểm tests, lint, build, browser và lưu ảnh giao diện.

Phạm vi lần này hoàn tất List/Add; chỉnh sửa/xóa mọi mẫu từ List, lịch sử phân tích server và chất lượng model thật chưa thuộc đợt triển khai này. Toàn bộ Task 2/FIX 1 vẫn theo các hạng mục mở ở trên.

### 12.22 Sửa Add tạo tiếp văn phong sau lưu — 2026-10-04

**Nguyên nhân:** luồng lưu giữ profile/form và analysis UUID để sửa tiếp hoặc resume sau reload. Khi tách List/Add, chưa có bước làm trống Add, nên trang tiếp tục hiển thị dữ liệu cũ và nút Phân tích lại.

**Đã sửa:** `profiles.save()` trả kết quả thành công/lỗi cho page; sau khi save và cập nhật mặc định thành công nếu được yêu cầu, `AiPromptCreate` reset nguồn/tên/model, analysis/result, form duyệt và UUID resume, hiện snackbar đã lưu rồi trả nút về Phân tích với AI. Giữ mẫu database, catalog/default và metadata ID profile của analysis lịch sử. Nút Văn phong mới cho phép bắt đầu lại từ kết quả đang mở; bản duyệt chưa lưu cần xác nhận trước khi bỏ.

**Lỗi vẫn giữ bản:** validation/version conflict hoặc POST chưa rõ kết quả không làm trống. POST bất định khóa reset để kiểm tra trước; profile đã lưu nhưng default lỗi vẫn hiển thị bản đã lưu và lỗi riêng. GET resume 403/404/410 dừng resume/mở khóa tạo mới; lỗi mạng khi tác vụ còn chạy vẫn cho Kiểm tra lại. Reset dừng GET/timer và sequence guard bỏ response tới muộn; không gọi model hoặc xóa profile/analysis server.

**Quy tắc và dẫn chứng:** rules là các đặc điểm cách viết người dùng duyệt để dùng lại; evidence gồm feature/trích đoạn thật/diễn giải giúp đối chiếu nhận định đó với bài mẫu. Backend kiểm trích đoạn có trong nguồn; UI cho sửa diễn giải hoặc bỏ dẫn chứng, giữ nguyên câu trích. Khi tạo bài mới, prompt nhận rules và style_instructions đã duyệt; evidence không được gửi sang bài mới. Ví dụ và giải thích trong [tài liệu API](AI_WRITING_PROFILES_API.md) và [QA tạo tiếp văn phong](qa/AI_PROMPT_NEW_PROFILE_2026-10-04.md).

**Kiểm chứng:** toàn frontend **39 file / 258 tests** đạt; Ai Prompt **4 file / 52 tests** (API 5, flow 23, UI Add 13, list 11). Kiểm tạo/lưu hai mẫu liên tiếp bằng POST mới, reset/reload, response trễ, lỗi save/default và xác nhận bỏ bản chưa lưu qua HTTP mock. Scoped ESLint đạt; production build cuối **33.19 giây**, 2857 modules, còn warning asset `section-title-icon.png` đã có trước.

**Browser localhost:** mở UUID cũ trả 403 do owner, thấy trạng thái Không có quyền truy cập và Văn phong mới được bật; bấm tạo mới rồi reload vẫn tên/editor trống, 0 từ, model mặc định và Phân tích với AI. Console không có error; có warning i18n menu hiện có. Không gọi model hoặc mutation profile từ browser; auto-reset sau lưu được kiểm bằng HTTP mock. Bằng chứng/ảnh: [QA tạo tiếp](qa/AI_PROMPT_NEW_PROFILE_2026-10-04.md).

- [x] Tự chuẩn bị Add cho mẫu kế tiếp sau lưu thành công.
- [x] Thêm Văn phong mới, bảo vệ bản chưa lưu và lỗi POST/default.
- [x] Không khóa Add khi resume bị từ chối quyền/hết hạn.
- [x] Cập nhật comment INPUT/OUTPUT/inventory với dấu `===`, tests và tài liệu.

Chỉ sửa frontend và tài liệu, giữ backend/API/migration hiện có. CRUD mọi mẫu từ List, lịch sử server và nghiệm thu chất lượng model thật vẫn là các phần mở của Task 2.

### 12.23 Rà soát phần còn lại của Task 2 sau List/Add/reset — 2026-10-04

**Kết luận:** Task 2 chưa hoàn tất. Backend cốt lõi và luồng Ai Prompt hiện tại đã có; còn sáu nhóm giao diện/kết nối bắt buộc và hai nhóm nghiệm thu. Đợt này chỉ đọc plan/code/test/báo cáo và cập nhật tiến độ, không triển khai tính năng mới hoặc gọi model.

**Đã triển khai:** Analyze + Plan → Write → Edit với prompt/schema riêng; nhánh ngắn/thumbnail-only và field merge; source snapshot/anchors; profile CRUD/options/default/version/snapshot; taxonomy AI bị loại; queue/checkpoint/cancel/retry/budget; quality gates; asset reference/usage/Apply draft ở backend. Ai Prompt có nguồn dán/URL/file, phân tích/duyệt/lưu/mặc định/copy/export, List tìm/phân trang/xem prompt và Add reset/tạo tiếp. Bằng chứng regression và browser theo 12.17, 12.19–12.22; không coi các mốc đó là nghiệm thu model thật.

| Phần còn thiếu | Code/hành vi hiện tại | Việc cần hoàn thiện |
| --- | --- | --- |
| 1. Chọn văn phong và brief khi tạo bài | Không có `writing_profile_id`/`writing_brief` trong form AI Content và `CreateWithAiDialog`; backend/options đã có. Dialog Post có Yêu cầu bổ sung gửi `instructions`; AI Content tự ghép hướng dẫn độ dài/SEO | Select mẫu đang bật hoặc mặc định ở create/regenerate; nối yêu cầu riêng của AI Content và brief theo plan. Giữ ô yêu cầu riêng đã có ở dialog Post, áp dụng ưu tiên brief/profile và xử lý default/profile tắt |
| 2. HTML nguyên bản và preview nguồn tạo bài | `aiContentInput::buildAiContentRequest()` gọi `extractHtmlText(await file.text())`, gửi `input.text`. Không có preview trước generation ở AI Content. Ai Prompt đã dùng preview backend | Gửi raw HTML hoặc `html_file` tới backend và hiển thị nguồn đã extract trước phân tích; giữ code/table/link/quote/metadata ảnh và xử lý lỗi encoding/budget. Đường file hiện tại có thể làm mất cấu trúc trước khi backend nhận nguồn |
| 3. Taxonomy thủ công trong AI Content | Generation frontend/backend đã bỏ taxonomy; Post form có categories/tags. `AiContentEditorDialog` chỉ biên tập title/excerpt/content/SEO, form create chưa chọn category/tag | Nối selector và payload taxonomy thủ công cho create/edit/regenerate/Apply của AI Content, xác nhận candidate legacy khi cần; kiểm giữ lựa chọn qua các bước. Không khôi phục AI taxonomy |
| 4. TinyMCE–MediaLibrary | `PostEditor.vue` có plugin image, nhưng không có picker/upload hook MediaLibrary và chưa cấu hình giữ asset ref. Backend lưu/usage/regenerate ảnh đã có | Chọn/upload ngay trong editor tại con trỏ, chèn URL + `data-media-asset-id`, sửa alt/caption, giữ ảnh qua save/reload/Apply/regenerate; lỗi upload giữ draft và chặn lưu URL tạm |
| 5. Tiến độ và thông tin từng bước | Backend trả `analyzing/writing/editing/validating`, steps/diagnostics/quality_checks/profile/warnings. AI Content label và Post dialog progress vẫn ánh xạ `rewriting/seo` của luồng cũ; chưa render các metadata mới | Ánh xạ đúng Analyze/Write/Edit/Validate; hiển thị status/attempt/timestamps/lỗi/usage an toàn, profile/version, kết quả gates và cảnh báo ảnh sau reload. Không đưa raw nguồn/intermediate output vào public UI |
| 6. Quản lý mọi profile đã lưu | List chỉ GET/search/page/xem prompt; Add bắt đầu bằng phân tích và duyệt mẫu. API GET detail/PUT/version/DELETE/manual profile đã có | Trang/dialog sửa mẫu theo ID, bật/tắt/xóa, tạo thủ công không bắt phân tích bài; xử lý version 409 và mặc định. Đây là phần UI chưa nối, không phải thiếu toàn bộ CRUD backend |

**Hai nhóm chưa nghiệm thu:**

- [ ] **Chất lượng và tuning:** chọn/freeze 20–30 bài thật (protocol hiện có 25 ca), chạy baseline một lượt/brief tốt/ba bước, chấm mù facts/tự nhiên/bố cục/công sửa, ghi token/latency/cost; hiệu chỉnh prompt, exact-copy/language/similarity/budget và quyết định rollout. 20 nguồn tổng hợp offline chỉ kiểm kỹ thuật, không chứng minh chất lượng viết. Xem [protocol](AI_ARTICLE_QUALITY_EVALUATION.md).
- [ ] **Luồng thực tế và vận hành:** sau khi nối UI, browser đăng nhập kiểm profile → chọn khi tạo bài → ba bước → taxonomy/ảnh → sửa/regenerate → Apply Post draft, cùng reload/lỗi/hủy/retention. Kiểm provider thực và claim/checkpoint/cancellation/timeout trên MySQL/Redis nhiều worker; worker/cache/SQS/process manager production liên quan mục 2C vẫn chưa có bằng chứng nghiệm thu.

**Giữ đúng phạm vi đã chốt:** lịch sử phân tích dạng danh sách server, điểm/%/SEO/ảnh/tính độc đáo của Ai Prompt còn minh họa vì thiếu API; theo yêu cầu 12.19 được giữ tĩnh ở đợt hiện tại, không coi đó là lỗi API hoặc số liệu model thật. Thêm history/source API là phần mở rộng tiếp theo. Vision/research/revision/multiple-reference/AI tự chọn profile vẫn thuộc V2. `ai_content_drafts`/approve-reject/lưu dài hạn thuộc mục 3; thumbnail AI thuộc 9.7; Reverb và các tab Settings ngoài AI thuộc phần khác của FIX 1.

**Điều chỉnh plan:** sửa mô tả cũ còn ghi Ai Prompt chưa nối API, chưa có migration/class hoặc taxonomy generation chưa bỏ. Giữ các ô 12.15 ở trạng thái chưa nghiệm thu xuyên suốt; không đánh dấu toàn Task 2 hoàn tất chỉ từ backend tests. Không chạy lại test/build cho riêng việc cập nhật tài liệu.

**Thứ tự đề xuất:** nối select văn phong/brief và nguồn raw HTML/preview → taxonomy thủ công và tiến độ/gates → TinyMCE–MediaLibrary → quản lý profile từ List → browser/model/corpus/runtime QA. Các nhóm giao diện có thể review độc lập; không tự triển khai thêm trong đợt audit này.

### 12.24 Hoàn thiện triển khai và nghiệm thu localhost — 2026-10-05

**Phạm vi:** theo yêu cầu hoàn thiện Task 2 và câu trả lời “Nghiệm thu trên localhost trước”, đã triển khai sáu nhóm còn thiếu ở 12.23. Dùng Laravel services/requests/jobs/resources và Vue 3 `script setup`, service/composable/component theo chức năng. File mới/sửa có comment tiếng Việt, inventory/Input/Output và dấu `===`. Không mở rộng sang Reverb/VPS, lớp duyệt riêng, V2 hoặc các API Ai Prompt được phép giữ minh họa.

| Nhóm đã hoàn thiện | Kết quả |
| --- | --- |
| Văn phong/brief | Shared `AiWritingPreferences`, options service/composable, request whitelist; tích hợp AI Content/Post/regenerate, mặc định/inherit/override và brief rỗng rõ ràng. |
| Nguồn | Raw HTML/file multipart/encoding, shared source preview và abort/race guard; giữ cấu trúc ở backend, không flatten file ở client. |
| Taxonomy | Shared selector đầy đủ trang active, giữ ID mất để editor tự bỏ; create/edit/regenerate/Apply gửi lựa chọn thủ công, legacy confirmation và expected version. |
| Editor/media | `usePostInlineMedia` nối MediaLibrary/upload ở bookmark, undo/alt/caption, URL/asset ref; khóa Save khi upload/URL tạm, giữ figure caption khi regenerate. Editor HTML dự phòng hoạt động khi Tiny Cloud khóa. |
| Quan sát | `AiPipelineReport` đọc stages/checkpoint/profile/version/usage/gates/lỗi an toàn sau reload; diagnostics nhánh một lượt được public qua allowlist, không raw/intermediate/secret. |
| Ai Prompt CRUD | List tạo thủ công/sửa/version/bật-tắt/xóa xác nhận/default; Add phân tích thật/lưu/reset; giữ form khi conflict/default lỗi/POST bất định. |

**Các lỗi đã sửa khi QA:** preview reset vì object getter mới, dialog đọc action sau đóng, số liệu bị ghép giữa ô bảng, integer nhóm hàng nghìn bị hiểu sai, evidence markup thiếu hướng dẫn, caption mất qua regenerate, draft thiếu `taxonomy_origin=manual`, candidate Apply còn cho nhập không lưu được, checklist alt đếm trùng gallery/HTML và usage nhánh một lượt bị ẩn khi adapter chỉ trả `reported_model`. Mỗi lỗi có regression tương ứng; không nới gate để che output sai.

**Browser/model:** file Ovation lấy phần bài chính 29 blocks/11 metadata ảnh. Mẫu tham khảo chọn phần giới thiệu/tám tiện ích, model thật trả 13 dẫn chứng; lưu QA profile #4 rồi Add trống để tạo tiếp. Chọn profile QA #3/v4 và brief tạo bài ba bước; upload asset #9, sửa/lưu/regen content giữ ID/URL/alt/caption. Excerpt-only chạy một call, giữ HTML/taxonomy cha; Apply vào Post **#6 draft**, đúng actor, Blog/tag và usage. Post #5 là QA trước sửa taxonomy, giữ riêng; không xuất bản.

**Vận hành local:** MySQL/cache database probe xác nhận hai process cùng UUID chỉ thực thi service một lần, cancel không bị đổi về ready và resume dùng lại Analyze checkpoint. Fixture probe đã xóa theo đúng UUID/hash QA. Worker local nhận code mới và timeout/budget/lease phù hợp; không coi Windows thiếu pcntl là bằng chứng hard-kill Linux hoặc kiểm production.

**Kiểm chứng:** toàn backend **299 tests/2078 assertions** đạt; API sau bổ sung diagnostics **14 tests/79 assertions** đạt. Toàn frontend cuối **42 files/289 tests** đạt; scoped ESLint/Pint/build đạt. Viewport mobile 390 × 844 không tràn chiều rộng trang, đã reset sau kiểm. Còn warning build asset có sẵn. Bằng chứng chi tiết, screenshots và proof JSON ở [QA localhost](qa/TASK2_LOCALHOST_2026-10-05.md); contract cập nhật ở [pipeline API](AI_ARTICLE_PIPELINE_API.md), [profile API](AI_WRITING_PROFILES_API.md) và [inline media](TASK2_INLINE_MEDIA_API.md).

**Đánh giá đã chuẩn bị và chạy:** corpus v1 có 25 ca/24 nguồn đã freeze/hash/license/provenance. Prompt 2.0 pilot được giữ cùng lỗi; prompt **2.1** làm rõ evidence text và giữ request schema checkpoint 2.0. Pilot năm ca B/C cùng nguồn/model/profile có **19 call/97.837 token, 6 output qua gate và 4 bị chặn**; B ready 4/5, C ready 2/5. Các lỗi gồm thiếu/đổi link nguồn và fact ID không có trong Analyze. Gói chấm mù hai người đọc, CSV đủ rubric/facts/coverage/công sửa và harness budget/no-Apply đã có tại [QA chất lượng](qa/task2-quality/README.md). Chưa phục hồi toàn bộ request baseline A, chưa có bảng giá xác nhận để tính cost; không gán số giả.

**Chưa hoàn tất nghiệm thu Task 2:**

- [ ] TinyMCE toolbar thật: Tiny Cloud từ chối origin `http://127.0.0.1:8000`. Hook/backend/composable và fallback đã kiểm; cần origin hợp lệ hoặc owner chọn license self-hosted trước browser QA toolbar/chèn-di chuyển-xóa ảnh thật.
- [ ] Chất lượng và rollout: pilot còn lỗi, corpus v1 thiếu coverage; cần owner/người đọc xác nhận nguồn/facts, hai người chấm, tuning tiếp và nghiên cứu đầy đủ theo 12.15. Gói review đã cụ thể; không dùng AI tự chấm để đóng tiêu chí người đọc hoặc kết luận ba bước tốt hơn một lượt.

Sáu nhóm code/UI/API ở audit 12.23 đã có kết quả kiểm được. Hai điều kiện trên vẫn giữ mở rõ ràng; không đánh dấu toàn Task 2 hoặc toàn FIX 1 hoàn tất chỉ từ test/build hay các call model thành công.

### 12.25 Ai Prompt List: dialog riêng và tải/đóng ổn định — 2026-10-05

Theo yêu cầu sửa UX, tách dialog nhập thủ công, chỉnh sửa, bật/tắt và xóa. Form tạo/sửa luôn hiện đầy đủ, khóa nhập và có vòng xoay khi tải; lỗi GET giữ form khóa để tải lại. Giữ nội dung/loading trong lúc đóng, chỉ dọn dữ liệu tại `afterLeave`; hủy request khi đóng và bỏ response muộn. Các xác nhận bỏ bản sửa, version 409/default lỗi/POST bất định được giữ.

Toàn frontend **43 files/296 tests** đạt; scoped ESLint/build đạt. Browser localhost kiểm form/loading và đóng/mở lại, không ghi profile hoặc gọi AI. Các dialog sửa/trạng thái/xóa kiểm bằng HTTP giả vì danh sách local hiện rỗng. Chi tiết tại [plan Ai Prompt mục 0.8](PLAN_AI_PROMPT_API.md#08-tách-dialog-và-ổn-định-tảiđóng--2026-10-05). Không thay đổi hai điều kiện nghiệm thu Task 2 còn mở ở 12.24.

### 12.26 Chuẩn hóa header/content/footer dialog — 2026-10-05

Đã chuẩn hóa **39 dialog ứng dụng** bằng `components/dialogs/AppDialogLayout.vue`,
lấy Post Create with AI làm mẫu: header có tiêu đề/nút đóng, content ở giữa cuộn,
footer luôn hiện Hủy/Đóng và thao tác chính. Bao gồm AI, Prompt, Post, Media,
Settings, tìm kiếm và các dialog tái sử dụng. Demo API Vuetify trong `views/demos/`
giữ hành vi minh họa; khi đưa vào ứng dụng phải chuyển sang khung chuẩn.

Nút tạo/lưu văn phong được đưa khỏi form dài xuống footer. Các form có submit
dùng ID riêng từ `useId()` và nút footer liên kết tới form; các handler guard/
validation hiện có được giữ. Footer wrap, tiêu đề/mô tả wrap; Media Library giữ
scrollbar panel trong content ở desktop, cuộn content chung trên mobile hoặc
viewport thấp. Không tự reset state trong layout hoặc thay contract API.

Kiểm chứng: **44 frontend files/300 tests**, scoped ESLint và production build
**1 phút 30 giây** đạt. Regression mới kiểm vùng header/body/footer, khóa nút đóng,
footer mặc định, hai form độc lập và submit đúng một lần; regression Prompt giữ
loading/after-leave/bản chưa lưu và xác nhận nút tạo nằm trong footer.

Browser kiểm component thật với API giả, chặn mutation/model: Post/Prompt cuộn
giữ nguyên tọa độ header/footer ở **1280×720**, Prompt/Media không tràn ngang ở
**390×844**, Media ở **1280×400** vẫn có footer và cuộn được nội dung. Enter và
nút Submit của form có footer đều phát sự kiện. Phiên kiểm bố cục dùng harness
tạm vì tài khoản admin seed trong code không đăng nhập được vào database local;
harness đã dọn sau kiểm tra. Quy tắc và mẫu cho dialog mới đã ghi vào
[PROJECT_STRUCTURE mục 4.3.1](PROJECT_STRUCTURE.md#431-cấu-trúc-dialog-bắt-buộc)
và [PLAN](PLAN.md#chuẩn-cấu-trúc-dialog-bắt-buộc).

### 12.27 Tập trung tạo nội dung ở AI Content — 2026-10-05

Theo yêu cầu mới, đã gỡ **Create With AI** ở Post List và **Fill All with AI**
ở Post Add/Edit, dọn cả nút **AI Assistant** minh họa trong khung soạn bài.
Xóa `CreateWithAiDialog.vue`, hai component chỉ phục vụ dialog
(`ArticleSourcePreviewCard.vue`, `AiImportProgressCard.vue`) và hai bộ test cũ.
Không còn import hoặc điểm mở dialog tạo nội dung AI trong mã nguồn Post/tests.
Luồng tạo, review, regenerate và Apply nội dung Post tập trung tại AI Content.

PostForm dùng handler riêng cho thumbnail; xác nhận thay ảnh và lineage khi lưu
đã có regression cho cả Áp dụng và Giữ dữ liệu hiện tại. Các ca này kiểm title,
content và excerpt người dùng nhập vẫn được lưu đúng sau khi chọn ảnh.
Plan, inventory và component map đã cập nhật. Chuẩn dialog ở mục 12.26 tiếp tục
dùng `AppDialogLayout` làm mẫu: header/footer cố định, chỉ content ở giữa cuộn.
Các snapshot cũ về dialog Post là lịch sử triển khai.

Kiểm chứng: toàn frontend **42 files/285 tests** đạt; scoped ESLint không có lỗi
hoặc cảnh báo; production build **51.44 giây** đạt. Điều kiện nghiệm thu Task 2
còn mở tại 12.24 tiếp tục được theo dõi riêng.

### 12.28 Nút X theo mẫu dialog Vuexy — 2026-10-05

Đã chuẩn hóa nút đóng của `AppDialogLayout` bằng `DialogCloseBtn` có sẵn:
nổi ngoài góc trên bên phải của card theo CSS Vuexy. Nút là sibling của card,
không bị `overflow: hidden` cắt; header/footer cố định và content vẫn cuộn.
Layout chuyển attrs/class/style vào card và tiếp tục xử lý `closeLabel`,
`closeDisabled`, event `close` qua cùng handler. Edit AI Content, xác nhận tải
bản mới và toàn bộ dialog thêm/sửa provider/model ở AI Settings nhận chuẩn chung.
Quy tắc vị trí X đã ghi vào PLAN và PROJECT_STRUCTURE mục 4.3.1.

Kiểm chứng: **42 frontend files/285 tests**, scoped ESLint và production build
**1 phút 39 giây** đạt. Regression khung chung kiểm nút X nằm ngoài card,
attrs vẫn thuộc card, khóa đóng/footer và submit đúng một lần.

Browser dùng component thật, dữ liệu mẫu và API giả chặn mutation/model:
edit AI Content tại **1280×720** cuộn content giữ nguyên tọa độ header/footer/X;
thêm/sửa provider/model có nút X nổi ngoài card và đóng đúng; ở **390×844**
nút/footers vẫn trong viewport, không tràn ngang. Provider tại **1280×400** có
body cuộn riêng và footer/X luôn hiện. Harness đã dọn, viewport đã reset.
Ảnh kiểm chứng: [AI Content](qa/AI_DIALOG_CLOSE_2026-10-05/ai-content.jpg)
và [AI Settings](qa/AI_DIALOG_CLOSE_2026-10-05/ai-settings.jpg).

### 12.29 Loading form edit AI Content — 2026-10-05

Dialog chỉnh sửa danh sách AI Content hiện đủ tiêu đề, tóm tắt, editor, taxonomy
cho Post và SEO ngay khi mở. Trong lúc GET, các field/Lưu bị khóa và vòng xoay
hiển thị trên form, phía trên cả toolbar TinyMCE; Hủy/X vẫn đóng được. Khi tải
xong, dữ liệu được điền vào cùng form. GET lỗi giữ đủ field bị khóa và có xác
nhận tải lại; loading summary giữ `target_type` để retry đúng loại nội dung.
Lỗi lưu giữ nguyên bản đang sửa; candidate đã Apply tiếp tục chỉ đọc.

Snapshot session/loading/error và form được giữ đến `after-leave`. Event đóng
cũ sau khi mở lại bị bỏ qua; response GET muộn tiếp tục được guard ở composable.
Không gửi Save khi thiếu draft/version, đang tải hoặc đang upload ảnh.
Đã bỏ dòng “Danh mục và tag · chọn thủ công” trong `AiManualTaxonomyFields`;
các trường Danh mục/Tag và nút tải lại vẫn hoạt động. Placeholder cho các trường
text và taxonomy được bổ sung. Quy tắc loading/after-leave được cập nhật trong
PLAN và PROJECT_STRUCTURE mục 4.3.1.

Kiểm chứng: toàn frontend **42 files/292 tests**, scoped ESLint/Stylelint và
production build đạt. Regression kiểm tải chậm → ready giữ cùng field, lỗi GET/
tải lại, khóa Save, giữ bản sửa khi lỗi lưu, đóng lúc tải/đã tải và mở lại trước
event `after-leave` cũ; retry API giữ đúng target. Run lỗi không có draft vẫn
hiện báo cáo pipeline để người dùng đọc nguyên nhân.

Browser kiểm component thật với dữ liệu mẫu/API giả, không mutation/model:
desktop **1280×720** có đủ field bị khóa, vòng xoay rõ; sau GET các input được
điền/mở khóa, bỏ heading taxonomy, header/footer giữ tọa độ khi cuộn. Mobile
**390×844** giữ X/footer/loading trong viewport, không tràn ngang. Tiny Cloud
vẫn cảnh báo origin/key đã biết ở 12.24; phiên này không nghiệm thu khả năng
chỉnh sửa toolbar TinyMCE. Harness đã dọn và viewport đã reset.

Ảnh kiểm chứng: [đang tải](qa/AI_CONTENT_EDITOR_LOADING_2026-10-05/loading.jpg),
[đã tải và taxonomy](qa/AI_CONTENT_EDITOR_LOADING_2026-10-05/ready.jpg),
[mobile](qa/AI_CONTENT_EDITOR_LOADING_2026-10-05/loading-mobile.jpg).
Các điều kiện nghiệm thu Task 2 còn mở ở 12.24 được giữ nguyên.

### 12.30 Đối chiếu tiến độ FIX 1 — 2026-10-05

Đọc lại plan, báo cáo QA và đối chiếu code hiện tại; không triển khai tính năng
mới, gọi model hoặc chạy lại tests/build trong đợt cập nhật tài liệu này.
Sửa mục tổng hợp 10 còn ghi raw HTML/preview chưa nối và mục 3 còn để báo cáo
metadata/usage ở trạng thái chưa làm; các phần này đã có bằng chứng tại 12.24.
Giữ nguyên báo cáo lịch sử 12.17–12.23 và các tiêu chí còn mở.

| Nhóm | Trạng thái hiện tại | Phần tiếp theo |
| --- | --- | --- |
| Task 1 — kiểm output trước ready | `DONE` cho phạm vi triển khai/regression/local smoke | Tuning temperature/raw debug thuộc đợt riêng, không mở lại Task 1 |
| Task 2 — pipeline/content/profile/media | `IN PROGRESS`: code/backend và sáu nhóm UI/API đã triển khai, có QA localhost/model thật/MySQL | Nghiệm thu toolbar TinyMCE thật và nghiên cứu chất lượng bằng người đọc |
| UX Ai Prompt/AI Content/dialog | `DONE` tới mốc code 12.29 | List/manual/edit/trạng thái riêng, header/footer, X/loading/placeholder, dọn tạo bài AI khỏi Post đã xong |
| Workflow biên tập AI | `TODO` | Lớp draft biên tập riêng, lưu dài hạn, approve/reject, lịch sử/lý do và so sánh nguồn |
| Thumbnail AI trong luồng content | `TODO` cho phần kết nối còn thiếu | Nối generate vào thumbnail candidate/Post; ảnh nguồn đã có |
| Realtime/VPS | `TODO` | Broadcast/Echo/Reverb, process manager, timeout production và QA reconnect/reboot |
| Chín tab Settings ngoài AI | `TODO` cho API/runtime | Hiện fixture; còn typed settings/API/quyền/runtime/tests, concurrency và QA đăng nhập |

Hai điểm nghiệm thu Task 2 chưa đóng:

1. **TinyMCE:** Tiny Cloud đang từ chối origin/key localhost. Có picker/upload,
   backend/media và editor dự phòng, nhưng cần origin được phép hoặc cấu hình
   license được chủ dự án chọn trước khi kiểm toolbar/chèn-di chuyển-xóa ảnh thật.
2. **Chất lượng:** pilot năm ca đã chạy B/C nhưng còn 4/10 output bị chặn.
   Hai CSV chấm mù hiện có 10 dòng mỗi người, 0 dòng đã có điểm/lỗi facts;
   summary ghi `human_review=pending`, baseline A chưa phục hồi đủ cấu hình.
   Cần bổ sung coverage/facts nguồn, hai người chấm, tuning và quyết định rollout.

Kiểm chứng gần nhất giữ đúng nguồn: backend tại 12.24 **299 tests/2078 assertions**
(API diagnostics **14 tests/79 assertions** kiểm bổ sung); frontend tại 12.29
**42 files/292 tests**, scoped ESLint/Stylelint và build đạt. Không cộng các bộ
test trùng hoặc tính checkbox thành phần trăm hoàn thành.

Bước tiếp theo theo plan là hoàn tất hai điều kiện nghiệm thu Task 2; các nhóm
workflow biên tập, thumbnail, Reverb/VPS và Settings là phạm vi còn lại của FIX 1.
Phần lịch sử/nguồn/điểm minh họa chưa có API ở Ai Prompt vẫn để sau theo phạm vi
đã chốt; Resource/Sound apply/audio và V2 không được tự đưa vào phần bắt buộc.

### 12.31 Chất lượng bài — triển khai bước 1–2 — 2026-10-05

**Phạm vi:** điều tra bốn output bị chặn của pilot 2.1 rồi sửa prompt/luồng
Analyze → Write → Edit theo nguyên nhân. Giữ nguyên corpus, artifacts lỗi và
CSV chấm cũ; không Apply/Publish bài hoặc đổi Settings/default.

**Bước 1 — đối chiếu nguồn và từng call.** Audit tái chạy bằng
`scripts/ai-quality/audit.php`, 0 model call; dữ liệu chi tiết và hash tại
[audit 2.2](qa/task2-quality/audit-2026-10-05-prompt22.json).

| Output cũ | Nguyên nhân xác nhận | Kiểm lại offline sau sửa |
| --- | --- | --- |
| Q02-B | Bài giữ link tham khảo nhưng chép thiếu một phần commit hash trong link mục lục `#laravel-12`. Gate cũ cũng bắt mọi link mục lục như tham khảo. URL chép sai là lỗi output thật. | Vẫn chặn `source_link_unknown`; không suy đoán/sửa URL |
| Q02-C | Giữ đủ SemVer/PHP Manual, bỏ ba link mục lục. Gate bắt mục lục là tham khảo; sau lỗi này còn lộ Q1 → quý I bị coi là mất số 1. | Qua gate với nguyên HTML và facts cũ |
| Q07-C | Bỏ 11 link mục lục, giữ citation đúng URL nguồn. Phần đã chọn chỉ là Introduction, không phải toàn bộ các mục được liệt kê. | Qua gate với nguyên HTML và facts cũ |
| Q18-C | Ledger có F01–F18; Writer thêm source block `S029` vào `used_fact_ids`. Global prompt cũ đồng thời cấm “IDs” dù schema yêu cầu provenance IDs. | Vẫn chặn unknown fact reference; không xóa/đổi ID để cho qua |

Pilot 2.2 còn tìm được một false positive mới: F12 ghi Loft Suite **2 tầng**,
Writer/Editor đều giữ **hai tầng** cùng LED 80-inch và piano. Gate cũ chỉ tìm
token chữ số nên chặn `important_number_missing`. Đã thêm đối chiếu số tầng
đơn giản 1–9 bằng chữ; giá trị khác, thành phần của số lớn, số thập phân bằng
chữ và tầng rưỡi vẫn bị chặn. Không sửa candidate để làm kết quả đạt.

**Bước 2 — thay đổi triển khai.**

- `ArticleSourceLinkPolicy` tạo manifest dùng chung trong prompt B/C và gate.
  Link body phải có href đúng nguyên bản, kể cả URL dài/query/fragment. Chỉ
  nhận diện TOC khi list đầu bài sau H1/heading Mục lục, không có prose và toàn
  link fragment của đúng tài liệu; list tham khảo, link trang khác hoặc link
  body trùng TOC vẫn bắt buộc. URL nguồn/canonical được phép dùng cho citation.
- Prompt **2.2** đưa cùng manifest tới Analyze/Write/Edit và nhánh một lượt;
  phân biệt block `S...`, fact `F...`, asset `I...`. Analyzer có pattern fact ID
  và enum source blocks; Writer/Editor có enum fact IDs từ analysis đã validate;
  Writer chỉ dùng images đã cấp, mảng rỗng khi không có ảnh. Dữ liệu sai dừng
  sớm, không gọi bước kế hoặc lọc ID để che lỗi.
- Q1–Q4 tương đương quý I–IV và số tầng 1–9 có dạng chữ tương ứng ở phép đối
  chiếu số liệu; giá trị khác vẫn fail. Không đổi HTML, code/version hoặc nới
  quy tắc nhóm nghìn đã có; không suy diễn số lớn/năm từ từ ngữ.
- Snapshot **2.0/2.1** giữ nguyên prompt/schema/context; sáu request hashes được
  chụp trước sửa và so lại bằng regression. Nhánh B cũ cũng giữ instructions
  nguyên vẹn. Source/facts/conditions/previous draft và image refs không bị cắt.
- Diagnostics giữ reason `source_link_unknown` qua redaction, không lộ href,
  source excerpt hoặc credential. Hai regression API được cập nhật theo
  contract hiện tại: instructions bổ sung manifest; detail có diagnostics
  allowlist còn list không trả response ID. Kiểm không lộ raw metadata/key giữ nguyên.
- Harness kiểm hash JSON LF tương thích Git checkout CRLF; đổi nội dung vẫn
  fail trước AI và CLI trả exit 1. `--input-snapshot` chỉ đọc profile/pipeline
  đã freeze, không lấy connection/key từ file hoặc ghi lại profile vào DB.

**Kiểm chứng:** backend **317 tests/2264 assertions**, scoped Pint và
`git diff --check` đạt. Regression dùng outputs thật đã lưu cùng các ca âm:
mất/sai body href, query/fragment/hash; đổi quý/số lượng/version; fact ID lẫn
source/asset ID; chặn trước call tiếp theo; byte/hash checkpoint cũ và source
tampering. HTTP fake/replay offline không được tính là call model hay chấm văn phong.

**Pilot model 2.2:** đã chạy Q02/Q07/Q18, hai nhánh B/C, đúng **12 call,
93.809 token**, không retry hoặc mở rộng corpus. Kết quả phiên chạy giữ nguyên:
**B ready 3/3, C ready 2/3**; Q18-C bị chặn bởi false positive “hai tầng” ở trên.
Sau sửa, [audit sáu outputs](qa/task2-quality/audit-2026-10-05-prompt22-runs.json)
cho **6/6 qua gate hiện tại**, 0 call thêm, giữ nguyên HTML/facts. Đây là kiểm
lại output đã lưu, không đổi status/summary của phiên model hoặc giả là lượt
chạy model mới. [Artifacts/usage](qa/task2-quality/pilot-2026-10-05-prompt22/summary.json)
và [gói chấm](qa/task2-quality/pilot-2026-10-05-prompt22/review/index.html) đều giữ
output thành công/lỗi; hai reviewer chưa chấm. Chi phí tiền vẫn null.

Nguồn và
profile QA id 3/version 4 giữ như bản freeze, connection local lấy từ prototype
`01a10043-503f-7030-a167-2d9facfedc1c`. Connection local khác phiên 2.1; Settings
của phiên cũ chưa có snapshot đủ để đối chiếu. Đây là kiểm chứng gate mới,
chưa phải so sánh văn phong giữa phiên.

**Kết luận bước 1–2:** đã hoàn tất điều tra và sửa lỗi thuộc phạm vi này;
source/ID/URL sai vẫn bị chặn, các cách diễn đạt số tương đương đã có kiểm
chứng bằng output thật và ca âm. Chưa coi gate tự động là kiểm chứng ngữ nghĩa.

**Còn lại:** bổ sung corpus/facts chuẩn, hai người chấm độc lập và quyết định
rollout; baseline A vẫn unavailable. Hai điều kiện nghiệm thu Task 2 tại 12.24
vẫn mở; không đóng Task 2 chỉ vì pilot qua gate kỹ thuật.

### 12.32 Chất lượng bài — triển khai bước 3–5 — 2026-10-05

**Phạm vi:** triển khai theo yêu cầu tiếp tục bước 3–4–5, kế thừa prompt 2.2
và sửa lỗi của 12.31. Bước 3 và phiên so sánh bước 4 đã hoàn tất; công cụ bước
5 đã hoàn tất, nhưng hai người đọc chưa chấm nên chưa có nghiệm thu chất lượng.

**Bước 3 — corpus v2.** [Manifest](qa/task2-quality/corpus-v2/manifest.json)
đóng băng **20 nguồn khác nhau**, 12 đơn vị nguồn giữ nguyên từ v1 và 8 nguồn
bổ sung từ Chính phủ, Cục Du lịch, bảo tàng, Vietnam Travel và GitHub Docs.
Có **6 nguồn tiếng Việt**, tin tức/du lịch/thông báo có điều kiện, một bảng
giới hạn dịch vụ và **18 metadata ảnh trên 8 nguồn**. Các nguồn mới ghi URL,
attribution/license, ngày đăng, hash raw/trích đoạn/snapshot và phạm vi chọn.
Tin dự kiến vẫn là dự kiến; thông báo giá vé năm 2010 không biến thành giá hiện tại.

Ảnh chỉ có URL/alt/chú thích, chưa đánh giá pixel hoặc regenerate với asset
được duyệt. Phần web mới là trích đoạn hoàn chỉnh tối đa 200 từ, không phải
toàn bài dài. Chưa có benchmark độc lập. [Checklist nguồn](qa/task2-quality/corpus-v2/source-checklists.json)
để người đọc xác nhận facts quan trọng từ source blocks; không lấy facts do
Analyze tạo ra làm đáp án chuẩn. Corpus/pilots/CSV cũ giữ nguyên.

**Bước 4 — phiên B/C cùng đầu vào.** Model `gpt-6.1-sol`, prompt 2.2,
profile QA id 3/version 4 và cùng connection local. Mỗi cặp được đối chiếu
hash nguồn/profile/brief/input; chỉ lựa chọn pipeline khác nhau. Provider
resolve một lần rồi clone cho mỗi nhánh. Một lượt mỗi nhánh, B trước C;
chưa đo biến thiên nhiều lần hoặc tách ảnh hưởng thứ tự/cache/tải provider.

Phiên gọi model thật **79 lần trong giới hạn 80**, không retry/fallback,
không Apply/Publish hoặc đổi Settings/default. [Báo cáo chính](qa/task2-quality/study-2026-10-05-corpus-v2/report-final/report.md),
[JSON tổng hợp](qa/task2-quality/study-2026-10-05-corpus-v2/report-final/report.json)
và [summary gốc](qa/task2-quality/study-2026-10-05-corpus-v2/summary.json) giữ
cả run thành công/lỗi:

| Chỉ số | B: một lượt | C: Analyze → Write → Edit |
| --- | --- | --- |
| Ready qua gate / tổng | 20/20 | 14/20 |
| Bị chặn hoặc lỗi | 0 | 5 gate số liệu, 1 timeout Writer |
| Lượt gọi thực tế | 20 | 59 |
| Token đã được provider báo theo call | 58.305 từ 20/20 call | 403.038 từ 58/59 call |
| Tổng token toàn nhánh | 58.305 | Chưa xác định: một call thiếu usage |
| Token trung vị của run có tổng usage đầy đủ | 2.035, n=20 | 14.675, n=19 |
| Thời gian trung vị, tính cả lỗi | 20,2 giây, n=20 | 103,5 giây, n=20 |

Chi phí tiền vẫn `null`. Không thay token timeout bằng 0 hoặc loại bài bị
chặn để làm tỷ lệ đẹp hơn. Thời gian/token chưa đo công sửa và không chứng minh
nhánh nào giữ facts hoặc viết hay hơn.

**Đối chiếu các ca bị chặn, 0 call thêm.** [Audit](qa/task2-quality/study-2026-10-05-corpus-v2/audit-failed.json)
và [token số chưa khớp](qa/task2-quality/study-2026-10-05-corpus-v2/numeric-diagnostics.json)
giữ nguyên output/status model. Các cách biểu diễn cần người đọc đối chiếu:

| Ca C | Bằng chứng nguồn → ứng viên | Kết quả kỹ thuật giữ nguyên |
| --- | --- | --- |
| Q26 | Ngày 9/3 → 09/03; token `3` chưa khớp `03` | `AI_QUALITY_GROUNDING` |
| Q27 | 4 nhóm → bốn nhóm, có liệt kê nhóm | `AI_QUALITY_GROUNDING` |
| Q28 | 3:15 PM → 15:15 | `AI_QUALITY_GROUNDING` |
| Q30 | 02 ngày miễn phí → nêu Thứ ba/Thứ sáu, không lặp token `02` | `AI_QUALITY_GROUNDING` |
| Q32 | 16th century → thế kỷ XVI | `AI_QUALITY_GROUNDING` |
| Q33 | Writer hết thời gian chờ, không có candidate cuối; Edit chưa chạy | `AI_PROVIDER_TIMEOUT` |

Đây là dấu hiệu gate số liệu đang nhạy với cách biểu diễn, không phải năm
bài đã được xác nhận accuracy. Không sửa gate/prompt giữa phiên hoặc đánh dấu
lại run lỗi thành ready. Bài thiếu output không được tạo candidate giả.

**Bước 5 — công cụ cho hai người chấm độc lập.** Đã tạo [phiếu người 1](qa/task2-quality/study-2026-10-05-corpus-v2/review-v2/reviewer-1.html),
[phiếu người 2](qa/task2-quality/study-2026-10-05-corpus-v2/review-v2/reviewer-2.html)
và [hướng dẫn](qa/task2-quality/study-2026-10-05-corpus-v2/review-v2/README.md).
Mỗi người có 40 nhãn X/Y thuộc 20 nguồn; **39 bài có output để chấm**, Q33-C
để trống/disabled. HTML có nguồn, source blocks, brief, văn phong và rubric,
ẩn nhánh/model/prompt/usage/gate; bài bị chặn có output vẫn được chấm.

Phiếu ghi riêng lỗi critical/major/minor, facts được giữ, evidence, năm tiêu
chí diễn đạt 1–5, phút sửa thực tế, số sửa câu/facts/đoạn và ưu tiên X/Y. Bản
nháp tách theo mã phiên/người đọc; tải CSV hoặc xem CSV để sao chép. Hai bảng
phải có danh tính khác nhau và đúng hash của nguồn/output/nhãn. Ô trống không
thành điểm 0; hoàn thành thiếu dữ kiện/điểm/phút sửa hoặc accuracy pass che lỗi
critical/major/coverage đều bị từ chối. Bất đồng facts được giữ để đối chiếu.

`scripts/ai-quality/report.php` đọc hai CSV và tổng hợp vào thư mục báo cáo
mới, **0 AI call**; không ghi đè CSV/artifacts/báo cáo trước. Đã kiểm xuất lại
từ hai CSV trống, hash CSV giữ nguyên. Hiện cả hai reviewer **0/39**, mọi điểm
diễn đạt và phút sửa chưa có dữ liệu; agent không tự chấm thay người.

**Kiểm chứng:** backend **331 tests / 2380 assertions**, frontend **43 files /
301 tests** đạt; Pint 10 file PHP, ESLint và Stylelint phần chấm đạt, diff
không có lỗi whitespace. Browser kiểm hai bộ chấm thật, nguồn/nhãn, tất cả
ô điểm trống, ứng viên thiếu, vùng cuộn và màn hình 390 px không tràn ngang.
CSV export/quotes/multiline và phương án sao chép có regression; trình duyệt
trong app chưa xác nhận file tải Blob về nên đã có nút xem CSV dự phòng.
[Ảnh bộ chấm](qa/task2-quality/study-2026-10-05-corpus-v2/review-v2-pair-preview.png).

**Phần còn chờ:** hai người đọc lập/xác nhận facts và chấm độc lập, đối chiếu
bất đồng, owner chốt tiêu chí/rollout. Baseline A chưa phục hồi; coverage pixel
ảnh/asset regenerate và benchmark còn thiếu. Bước 5 chưa thể đánh dấu đã
nghiệm thu; Task 2 và toàn FIX 1 vẫn `IN PROGRESS`.

### 12.33 Gỡ luồng B, tạo bài chỉ dùng C — 2026-10-05

**Yêu cầu đã hoàn thành:** người dùng chọn bỏ luồng B tạo toàn bộ bài bằng một
call. Tạo nội dung Post bằng AI luôn đi qua **Analyze + Plan → Write → Edit**.
Đây là quyết định sử dụng của người dùng, không phải kết luận C đã đạt điểm
chất lượng từ nghiên cứu B/C.

**Thay đổi thực thi:** xóa nhánh B và phần xử lý riêng của B trong
`ArticleImportService`; không còn switch môi trường `AI_CONTENT_PIPELINE`.
Run mới/chạy lại luôn snapshot `three_step`, kể cả input cũ có `single_step`.
Config/snapshot cũ không thể bật lại B; C lỗi dừng ở bước lỗi, không fallback
sang một call. Ngân sách queue cho toàn bộ Post content luôn tính ba request;
600 giây mỗi request tương ứng 1920 giây/job. Các trường riêng như title,
excerpt/SEO vẫn giữ request theo hợp đồng trường. Nhập thủ công và trích xuất
nguồn không có AI tiếp tục hoạt động theo luồng hiện có.

**Công cụ đánh giá:** `evaluate.php` mặc định C, từ chối B hoặc B,C trước
provider/DB run lookup. Preflight corpus v2 20 nguồn báo tối đa **60 call**.
Report/phiếu mới chỉ có C và một ứng viên mỗi ca; không hỏi preference giữa
hai bài hoặc tạo thống kê B giả. Hai người đọc vẫn chấm độc lập, ô chưa chấm
giữ trống. Report/export chỉ đọc B/C từ artifacts lịch sử và không chạy lại B.

**Kiểm chứng:** backend **337 tests / 2425 assertions** và frontend **43 files /
302 tests** đạt. Pint 22 file PHP, ESLint/Stylelint phần chấm và diff whitespace
đạt. Regression kiểm config/snapshot B cũ, lỗi Writer không fallback, budget,
title/SEO riêng, output thiếu/rỗng, ảnh đại diện và giữ field chưa chọn.

CLI xuất report C từ bản sao output Q01-C đã lưu: một ca, không có cặp B/C,
hai phiếu trống; **0 model call mới**, không tính là phiên chất lượng mới.
CLI cũng tổng hợp lại study B/C 20 cặp và export pilot cũ trên bản sao;
**62 file study gốc và hai CSV chấm được kiểm hash, không thay đổi**.
Browser kiểm phiếu C một cột/một ứng viên, không có lựa chọn giữa hai bài,
điểm trống; chỉ đọc, không chấm thay người. Bằng chứng tại
[verification.json](qa/AI_CONTENT_C_ONLY_2026-10-05/verification.json) và
[ảnh phiếu C](qa/AI_CONTENT_C_ONLY_2026-10-05/c-only-review.jpg).

**Tài liệu:** đã cập nhật API pipeline, protocol đánh giá, hướng dẫn QA,
PLAN, inventory và cấu trúc project. Các kết quả model/CSV/báo cáo B/C cũ
giữ nguyên để truy vết. Lần gỡ B này không gọi model thật, không Apply/Publish
hoặc triển khai VPS. Chất lượng C bằng người đọc và các phần còn mở ở 12.30
vẫn cần nghiệm thu; toàn FIX 1 giữ `IN PROGRESS`.

### 12.34 Đối chiếu tiến độ và task tiếp theo sau khi gỡ B — 2026-10-05

Đã đọc lại plan, code và báo cáo gần nhất sau 12.33. Đây là đợt audit/cập nhật
tài liệu; không sửa tính năng, không gọi model hoặc chạy lại tests/build.
Các số kiểm thử dưới đây là kết quả đã ghi của 12.33, không phải một lượt mới.

| Nhóm | Trạng thái đã xác nhận | Phần làm tiếp |
| --- | --- | --- |
| Task 1 — kiểm output trước ready | `DONE` trong phạm vi đã chốt | Không mở lại; temperature/raw debug thuộc đợt riêng |
| Task 2 — nguồn/profile/ba bước/checkpoint/UX | Code đã triển khai; tổng thể `IN PROGRESS` vì còn nghiệm thu | Xử lý các ca C chưa đạt, chấm chất lượng và kiểm toolbar ảnh TinyMCE thật |
| Gỡ B | `DONE` tại 12.33; config/snapshot cũ không bật lại B | Chỉ C cho mọi phiên tạo toàn bộ bài/chạy đánh giá mới |
| Workflow biên tập AI | `TODO`; chưa có model/migration `ai_content_drafts` hoặc approve/reject API | Lớp draft dài hạn, quyền/người duyệt, lịch sử/lý do, compare và UI duyệt/từ chối |
| Thumbnail sinh bằng AI | `TODO` phần kết nối; request hiện dùng `thumbnail_mode=source`, job ảnh ghi `result.image` | Nối lựa chọn generate và asset vào `draft.thumbnail` để list/Apply Post dùng được |
| Realtime/VPS | `TODO`; chưa có event broadcast/Echo/Reverb cho AI | Broadcast, polling fallback, process manager và QA môi trường triển khai |
| Settings ngoài AI | `TODO`; chín tab vẫn có dữ liệu minh họa, routes ghi Settings mới chỉ thuộc AI | Typed settings/API/quyền/runtime, concurrency và browser QA |

**Task nên làm tiếp: hoàn thiện chất lượng C, vẫn thuộc Task 2.** Study gần
nhất C ready **14/20**, còn Q26/Q27/Q28/Q30/Q32 bị `important_number_missing`
và Q33 Writer timeout. Audit trên output đã lưu vẫn giữ năm ca blocked và
Q33 không có final candidate. Đây là kết quả kỹ thuật; chưa xác nhận năm ca
là lỗi nội dung hay gate chặn cách diễn đạt tương đương.

1. Đối chiếu từng ca số liệu với source/facts/output: giá trị, đơn vị, điều
   kiện và cách viết số. Chỉ sửa prompt/gate khi có bằng chứng; thêm regression
   để số tương đương hợp lệ nhưng giá trị/đơn vị/điều kiện sai vẫn bị chặn.
2. Rà lại Writer timeout Q33, kích thước đầu vào/đầu ra và budget; không dùng
   B hoặc candidate giả để che lỗi. Khi kiểm bằng model, dùng phiên C mới và
   ngân sách rõ ràng; giữ nguyên study cũ.
3. Hoàn tất đánh giá facts/diễn đạt/công sửa bằng người đọc. Hai phiếu lịch sử
   hiện đều **0/39** bài đã chấm; agent không điền điểm thay người.

**Điều kiện còn lại của Task 2:** nghiệm thu chèn/upload/di chuyển/xóa ảnh
qua toolbar TinyMCE, save/reload/regenerate/Apply giữ ID/URL/alt/vị trí.
QA localhost gần nhất chỉ kiểm editor dự phòng do Tiny Cloud từ chối origin;
cần cấu hình origin/license phù hợp trước khi xác nhận toolbar thật. Không
coi hook/composable tests là nghiệm thu thao tác thật trên editor.

**Thứ tự sau Task 2 theo mục 4:** workflow draft biên tập và approve/reject
backend → UI duyệt/từ chối/so sánh nguồn → thumbnail generate → Realtime/VPS
→ chín tab Settings ngoài AI. Có thể tách workflow thành đợt độc lập trong
khi chờ người đọc/editor, nhưng không đánh dấu Task 2 `DONE` vì đã chuyển việc.

**Kiểm chứng kế thừa:** 12.33 có **337 backend tests/2425 assertions**,
**43 frontend files/302 tests** đạt; Pint/ESLint/Stylelint/CLI/browser QA có
bằng chứng. Không cộng checkbox thành phần trăm hoặc gộp lịch sử thành lượt
test mới. FIX 1 vẫn `IN PROGRESS`; sửa các hướng dẫn còn yêu cầu chạy B trong
2B/12.14/12.15 và cập nhật mốc hoàn thành gần nhất trong `PLAN.md`.

### 12.35 Hoàn tất kỹ thuật Task 2 — 2026-10-05

**Kết luận:** Task 2 `DONE` trong phạm vi kỹ thuật đã chốt. Chủ dự án chọn
chuyển hai người đọc chấm sang đợt riêng, giữ Tiny Cloud và tự đăng nhập tab
kiểm thử. Toàn FIX 1 vẫn `IN PROGRESS`; chưa có điểm người đọc hoặc kết luận
chất lượng/rollout. Mốc này cập nhật trạng thái hiện tại, không viết lại các
báo cáo 12.17–12.34 hoặc study lịch sử.

**Gate số liệu:** tách `ArticleNumberNormalizer` khỏi gate để nhận biểu diễn
tương đương có ngữ cảnh: ngày hợp lệ, giờ 12/24, thế kỷ, số đếm theo đơn vị,
tập thứ trong tuần và dấu hàng nghìn của số đếm nguyên. Giá trị/ngữ cảnh sai,
phiên bản, tiền và số thập phân vẫn được bảo vệ; không sửa HTML đã lưu.
19 output C cũ qua blocking gate hiện tại, 0 calls mới; Q33 cũ vẫn timeout và
không có final candidate. Năm ca bị chặn ở 12.34 có bằng chứng tương đương và
regression dương/âm; không nới gate chỉ để đổi status.

**Q33 mới:** chạy C ba bước ở thư mục riêng với timeout 600 giây/request,
tối đa ba calls; 3 calls thật, 37.608 tokens, tổng 321.634 ms. Từng bước dưới
200 giây nên không kết luận 600 là bắt buộc; Settings của provider không đổi.
Raw artifact mới giữ `failed / AI_QUALITY_GROUNDING` do dấu hàng nghìn; sau
sửa parser, audit offline qua blocking gate với 0 calls thêm. Report raw
giữ failed, không viết lại ready. Warning sáu số và semantic grounding chưa
xác định vẫn cần editor/người đọc rà soát.

**Tiny Cloud thật:** giữ license/key hiện có, dùng `http://localhost:8000`.
Sửa menu/cửa sổ TinyMCE bị lớp dialog che bằng split UI, portal z-index 2500
và event `editorDialog` để cha nhường focus khi cửa sổ riêng mở. Source Code
nhập được và Cancel trả focus; marker QA không được lưu. Quy chuẩn mới đã ghi
vào `PROJECT_STRUCTURE.md` mục 4.3.1.

Browser/API thật kiểm upload/chọn ảnh, alt/caption, di chuyển figure, xóa/undo,
save/reopen/sửa caption ở Post #3; edit/save/reopen/Apply ở AI Content tạo
Post #4 draft. Cả hai giữ asset #22/URL/alt/caption/usage, `published_at=null`.
AI Content dùng fixture QA replay Q33 có nhãn, 0 calls thêm và không tạo
checkpoint giả; không tính là phiên model ready mới. Database mới là SQLite,
taxonomy rỗng giữ []/manual. Regenerate không được gọi từ fixture này; tests
và QA browser/API tại 12.24 là bằng chứng riêng cho bảo toàn ảnh regenerate.

**Kiểm chứng cuối:** backend **374 tests / 2530 assertions**; frontend
**43 files / 302 tests**; riêng số liệu **36 tests / 93 assertions**. Pint,
ESLint/Stylelint scoped và production build **1 phút 36 giây** đạt.
Đối chiếu SHA-256 **62 files lịch sử, 0 thay đổi**; study cũ vẫn 79 calls,
C ready 14/20 và hai CSV 0/39. Không gộp kết quả kiểm lại offline vào status
model gốc hoặc chấm thay người đọc.

Báo cáo/bằng chứng/screenshot: [Task 2 hoàn tất kỹ thuật](qa/TASK2_COMPLETE_2026-10-05/README.md).
`PLAN.md`, inventory, contract và cấu trúc dialog đã cập nhật. Task tiếp theo
theo mục 4: workflow draft dài hạn và API duyệt/từ chối → UI biên tập/compare
→ thumbnail generate → Realtime/VPS → chín tab Settings ngoài AI. Chấm người
đọc/rollout vẫn theo đợt chất lượng riêng.

### 12.36 API/UI duyệt nội dung AI trên bản ghi hiện có — 2026-10-05

**Phạm vi người dùng chọn:** tạm bỏ mục 1 (bảng/model `ai_content_drafts` và
lưu dài hạn); triển khai mục 2–3–4: API nghiệp vụ, UI biên tập/so sánh và tests.
Không thêm migration, không đổi retention của `ai_imports` hoặc phục hồi B.

- Trạng thái biên tập `pending_review/approved/rejected` lưu trong
  `ai_imports.source_meta_json.editorial`, tách khỏi trạng thái kỹ thuật.
- Giữ boundary hiện có: admin có `posts.manage`, chỉ đọc/duyệt run của mình.
  Người duyệt/thời điểm/lý do lấy từ server; lịch sử dùng Spatie Activitylog.
- Duyệt tạo Post draft qua actions/provenance hiện có, khóa row/version,
  chặn duyệt trùng và candidate đã từ chối. API Apply cũ dùng cùng boundary.
- UI giữ editor hiện có; dialog so sánh nguồn đã snapshot/kết quả và lịch sử,
  dialog quyết định riêng có xác nhận duyệt hoặc lý do từ chối bắt buộc.
- Component map: page nối `useAiContentReview`; `AiContentReviewDialog` hiển thị
  metadata/nguồn/lịch sử; `AiContentComparison` render text đã escape;
  `AiContentReviewHistory` trình bày và tải trang tiếp;
  `AiContentReviewDecisionDialog` chỉ phát confirm/close, không gọi API.
- Tests kiểm quyền/owner, version, transition, rollback, media/provenance,
  cleanup và giao diện tải chậm/lỗi/xung đột/đóng/mở lại. Không gọi model thật.

**Trạng thái:** `DONE` cho mục 2–3–4 trong phạm vi trên. Mục 1 (lưu dài hạn)
vẫn tạm hoãn theo lựa chọn người dùng; nguồn/bản AI chịu retention hiện tại.
Activitylog không bị cleanup run xóa; GET history theo candidate cần run còn tồn tại.

**Kiểm chứng:** backend **383 tests / 2649 assertions**, frontend
**45 files / 319 tests**, Pint + ESLint/Stylelint scoped và production build đạt.
PHP CLI mặc định chưa bật GD; bộ backend đầy đủ chạy bằng
`php -d extension=gd -d xdebug.mode=off vendor/bin/phpunit --no-progress`, chỉ bật
GD cho tiến trình kiểm thử, không sửa php.ini hoặc môi trường ứng dụng.
Không gọi model thật, không migrate database đang dùng.

Bằng chứng/phạm vi/lệnh kiểm tra: [AI Content Review QA](qa/AI_CONTENT_REVIEW_2026-10-05.md).
Task tiếp theo theo backlog còn lại: thumbnail generate → Realtime/VPS →
chín tab Settings ngoài AI. Lưu dài hạn và phân quyền duyệt chéo owner chờ đợt riêng.

### 12.37 Hoàn tất thumbnail sinh bằng AI — 2026-10-06

**Kết luận:** tạo thumbnail AI cho candidate ở AI Content và gắn vào Post khi
duyệt đã `DONE` kỹ thuật. Toàn FIX 1
vẫn `IN PROGRESS`; task tiếp theo là Realtime/VPS rồi chín tab Settings ngoài AI.
Kho draft dài hạn, duyệt chéo owner và chấm chất lượng vẫn theo phạm vi đã chốt.

**Phạm vi ảnh:** thumbnail của Post lưu tại `draft.thumbnail.media_asset_id`, được
map sang `media.thumbnail_id` và usage `post.thumbnail` khi tạo Post draft.
Mốc này không triển khai sinh ảnh inline hoặc phân tích nội dung Post để tạo
thumbnail. Nút tạo ảnh độc lập trong `PostMediaPanel` đã có từ trước, chỉ nhận
tiêu đề/prompt nhập tay; không được tính là luồng mới đã nghiệm thu tại 12.37.
Đề xuất thumbnail dựa trên nội dung bài mới nhất tạm hoãn ngày 2026-10-06.

**Đã triển khai:**

- Form tạo và dialog regenerate chọn thumbnail nguồn hoặc AI; model ảnh độc lập
  với model nội dung, chỉ hiện model bật/khả dụng/có capability và driver tạo ảnh.
  Prompt ảnh tùy chọn, tối đa 4.000 ký tự. Generate dùng được với URL, text,
  prompt, HTML hoặc file; thumbnail-only giữ snapshot và không gọi model text.
- `AiThumbnailOptions` dùng chung lựa chọn; `AiThumbnailStatus` hiển thị preview,
  tiến trình/lỗi, kiểm tra, thử lại và hủy. Component trình bày chỉ phát sự kiện;
  composables/service giữ API và polling. Select có nhãn truy cập được trên mobile.
- `ProcessAiImportJob` lưu parent ready và child ảnh trong cùng transaction;
  dispatch sau commit. `AiThumbnailService` gắn ảnh vào
  `draft.thumbnail.media_asset_id`, kèm `origin=generated` và `image_run_id`.
  Summary/detail/review trả `thumbnail_generation` và lựa chọn ảnh an toàn.
- Worker khóa độc quyền theo image run; khi khóa bận, đưa delivery trở lại queue.
  Retry/hủy chỉ tác động child ảnh, không tạo lại bài hoặc thêm dòng vào list bài.
  Ảnh thất bại giữ nội dung ready; kết quả trễ bị chặn khi parent đã duyệt/từ chối,
  hết hạn hoặc child không còn hiện hành. Đồng bộ ảnh giữ chỉnh sửa mới nhất.
- Có thể sửa/từ chối khi ảnh đang chạy; duyệt bị chặn đến khi ảnh có trạng thái
  cuối. Hủy ảnh cho phép duyệt các field còn lại. Version cũ bị từ chối nếu ảnh
  hoặc bản nháp thay đổi. Apply tạo Post draft, media usage và provenance thumbnail
  lấy đúng image run/provider/model, kể cả ảnh kế thừa khi regenerate field khác.
- Backend kiểm `media.upload`, model ID/cặp provider-model hợp lệ và quyền owner;
  không tự thay lựa chọn model sai. Không thêm migration hoặc đổi retention.

**Kiểm chứng cuối:** backend **390 tests / 2727 assertions**, frontend
**46 files / 333 tests**, Pint + ESLint/Stylelint scoped + `git diff --check` đạt;
production build **2 phút 6 giây**. Regression bao gồm success/failure/retry/cancel,
edit/version, phản hồi trễ, khóa worker bận, regenerate và media/provenance/cleanup.
Bộ PHP chạy với GD đã bật sẵn trong CLI hiện tại, không đổi php.ini.

**Browser QA:** Laravel/Vue thật trên localhost với SQLite/tài khoản/media fixture
riêng, viewport mobile 409 × 590. Đã chọn model text/ảnh riêng, kiểm pending chặn
duyệt, retry/hủy ảnh giữ ba dòng bài và duyệt bản có ảnh thành Post draft.
Đối chiếu DB xác nhận media usage và provenance `qa-thumbnail / qa-image`.
Không chạy queue worker hoặc gọi provider AI thật; chưa đánh giá chất lượng ảnh
từ dịch vụ thật. Không migrate DB đang dùng hoặc sửa `.env`.

Báo cáo và bằng chứng: [AI Thumbnail QA](qa/AI_THUMBNAIL_2026-10-06/README.md).
API contract, `PLAN.md`, inventory và cấu trúc project đã đồng bộ; các báo cáo
12.17–12.36 và study cũ được giữ nguyên.

### 12.38 Sửa mở dialog nguồn/lịch sử và làm rõ nội dung đối chiếu — 2026-10-06

Người dùng phản ánh màn hình tối trước khi dialog xuất hiện. CSS Vuexy đặt scrim
`opacity: 1 !important`, trong khi hiệu ứng mở dialog của Vuetify còn chạy.
Loading overlay bên trong review card cũng tạo thêm lớp tối lúc GET đang chờ.

- `AiContentReviewDialog` tắt transition riêng, hiện khung cùng nền; tiến trình
  và thông báo tải đặt inline trong body. Header/footer và nút X/Đóng vẫn hiện.
- `AiContentComparison` nhận `loading`; giữ hai cột, hiện chờ tải thay vì báo
  không có nguồn trước khi API trả về. Chú thích giải thích nguồn là bản chụp
  đầu vào, còn bản AI là bản nháp hiện tại gồm chỉnh sửa đã lưu.
- Nguồn đọc từ `ai_imports.source_meta_json.article_source.content_html`, fallback
  `source_text`; bản AI đọc `result_json.draft.content_html`. Lịch sử sự kiện ở
  `activity_log`, trạng thái duyệt ở `source_meta_json.editorial`; Post được duyệt
  lưu `posts.content` với status draft. Không có thay đổi schema/retention.

**Kiểm chứng:** ba file frontend liên quan **23 tests** đạt; ESLint/Stylelint
scoped, `git diff --check` và production build **1 phút 23 giây** đạt.
Test dùng VDialog/VOverlay thật kiểm loading, một nền, nút đóng và after-leave;
tests composable giữ chặn quyết định/stale-response. Browser localhost dùng DB
fixture riêng xác nhận card visible/opacity 1 khi `aria-busy=true`, một scrim,
đóng nút X rồi mở bài khác và đóng footer. Không gọi generation AI hoặc migrate
DB ứng dụng; kết quả full suites tại 12.37 là lượt trước, không cộng vào lượt này.

Bằng chứng và phạm vi: [QA dialog nguồn/lịch sử](qa/AI_REVIEW_DIALOG_2026-10-06/README.md).
Mốc này là sửa UX của workflow hiện có; toàn FIX 1 vẫn `IN PROGRESS`.

### 12.39 Khôi phục hiệu ứng dialog nguồn/lịch sử đúng với project — 2026-10-06

Người dùng không chấp nhận cách tắt transition tại 12.38 vì dialog không còn
mở giống những dialog khác. Mốc 12.38 được giữ làm lịch sử; hành vi hiện tại
được thay thế bằng hiệu ứng chuẩn Vuetify/Vuexy và layout dùng chung của project.

- `AiContentReviewDialog` dùng `VDialog scrollable` với transition mặc định,
  `AppDialogLayout`, header/footer cố định và nút X. Không có loading overlay
  phủ card; trạng thái tải vẫn nằm inline trong body.
- CSS scoped khôi phục fade cho scrim riêng của dialog, khắc phục override
  `opacity: 1 !important` của Vuexy. Khi không bật reduced motion, nền dùng cùng
  easing/thời lượng mở **225 ms**, đóng **125 ms** với khung dialog.
- GET chạy ngay khi mở. Chỉ parse/render văn bản nguồn và bản AI dài sau
  `after-enter`; giữ nội dung đến hết hiệu ứng đóng rồi dọn qua `after-leave`.
  Khi mở lại, trạng thái render được reset; dữ liệu vẫn do composable hiện có quản lý.

**Kiểm chứng mới:** ba file frontend liên quan **24 tests** đạt; ESLint/Stylelint
scoped đạt; production build **1 phút 7 giây** đạt. Test VDialog thật kiểm dữ liệu
trả sớm chưa render trong enter, render sau enter và còn nguyên trong leave.

**Browser QA:** Laravel/Vue thật với SQLite/account fixture riêng, mở/đóng/mở lại
ba candidate. Lấy mẫu opacity ngắn khi mở xác nhận nền và khung cùng
**0 → 0.852497 → 1**; khi đóng cùng **1 → 0.933624 → 0.562011** trước khi DOM bị dọn.
Lấy mẫu đầy đủ xác nhận text chưa render trong enter và còn trong leave.
Kiểm body cuộn đến lịch sử: header/footer vẫn hiện, một scrim, nút X dùng được.
Mốc `ms` trong artifacts là thời điểm lấy mẫu của công cụ, không phải phép đo
latency ứng dụng. Tab/server QA tạm đã đóng; không gọi generation AI hoặc sửa DB/.env
đang dùng. Kết quả full suites tại 12.37 vẫn là lượt lịch sử riêng.

Bằng chứng: [QA hiệu ứng dialog nguồn/lịch sử](qa/AI_REVIEW_DIALOG_EFFECT_2026-10-06/README.md).
Không thay đổi backend, schema, nơi lưu nguồn/bản AI hoặc retention trong mốc này.
Thumbnail AI vẫn `DONE`; toàn FIX 1 vẫn `IN PROGRESS` theo backlog còn lại.

### 12.40 Chuẩn hóa 10 tab Settings theo project — 2026-10-06

Hoàn tất phạm vi chuẩn hóa trang Settings tại 9.9: sáu nhóm mới đọc/lưu thật,
AI giữ writer chung và có mẫu văn phong/version, Cron/System Info đọc thực,
Webhooks phản ánh chưa hỗ trợ. Mọi input đang cho sửa có API và validation.
Mốc này không đánh dấu webhook delivery, run history hay toàn FIX 1 hoàn thành.

Kiểm chứng và ảnh: [Settings QA](qa/SETTINGS_2026-10-06/README.md).

### 12.41 Tách ảnh content bằng link và bộ ảnh Gallery của Post — 2026-10-06

Theo xác nhận của chủ dự án, ảnh content chỉ là link trong HTML, có thể chèn
cùng link nhiều lần và không có quan hệ media với Post. Gallery là bộ ảnh được
chọn riêng, lưu thứ tự để phục vụ `post_type = gallery` trong giai đoạn sau.

- Post editor thêm **Chèn ảnh bằng link**; chọn/upload từ MediaLibrary cũng chèn
  URL thuần. Alt/caption thuộc từng vị trí trong bài. Không tự điền Gallery.
- Post API nhận `media.gallery_image_ids`, trả `media.gallery_images` và lưu
  `post.gallery`. Content dùng validator URL/markup, không truy vấn asset hoặc
  tạo usage; payload/attach `post.content_images` cũ không còn được ghi.
- Candidate AI giữ ref nội bộ để kiểm nguồn/regenerate. Apply vào Post không tạo
  quan hệ ảnh content hoặc tự điền Gallery. Cleanup ảnh AI đọc URL trong HTML
  Post đã lưu để giữ file đang được dùng, không tạo quan hệ ngầm.
- Migration bỏ usage legacy của ảnh xuất hiện trong content; chuyển ảnh được
  chọn riêng sang Gallery theo thứ tự cũ. Giữ HTML, MediaAsset và file.
  Đã áp dụng migration riêng này ở local: hai usage inline còn **0**; cả bốn
  Post, 22 MediaAsset và 22 bản ghi file giữ nguyên, đối chiếu hash đạt.

**Kiểm chứng:** backend **49 tests / 432 assertions**, frontend **8 files / 55
tests** đạt; scoped ESLint/Pint và production build đạt. Browser trên SQLite
fixture riêng kiểm chèn cùng link hai lần, lưu/mở lại với Gallery rỗng, chọn
ảnh Gallery riêng và lưu/mở lại sau sửa content. Reorder/clear và partial update
được kiểm ở test tự động. TinyMCE localhost khóa theo cấu hình hiện có nên
browser dùng nhánh HTML fallback; không sửa key/editor config.

Báo cáo và ảnh: [Post content/Gallery QA](qa/POST_GALLERY_2026-10-06/README.md).
`post_type = gallery` và giao diện trình diễn ảnh vẫn là backlog tương lai;
mốc này không đánh dấu toàn FIX 1 hoàn thành.

### 12.42 Upload logo/favicon trong Settings — 2026-10-06

**Trạng thái:** `DONE`. Theo lựa chọn chủ dự án, chuyển sang branding;
Realtime/VPS được bỏ qua trong đợt hiện tại.

- Tab Tổng quan chọn logo PNG/JPG/WebP (tối đa 2 MB, 4096 px) và favicon PNG
  vuông (16–512 px, tối đa 512 KB). Preview cục bộ; chỉ upload khi Lưu thay đổi.
- `SettingsBrandingPanel` nhận DTO/file/preview/error, phát select/remove;
  `useSettingsBranding` giữ File/blob và dọn khi hoàn tác/unmount;
  `useSettings` quản lý dirty/version/quyền và `settingsService` gửi multipart.
- Backend dùng cùng writer/version nhóm site. `SiteBrandingService` giữ file
  UUID trên disk public; đường dẫn nằm trong Spatie Settings. Migration thêm
  `site.logo_path` và `site.favicon_path`, không đổi thông tin website đã lưu.
- Logo áp dụng tại header public, loader và các vị trí logo của theme admin;
  favicon áp dụng trên Blade public/admin và SPA sau khi lưu thành công.
- Gỡ ảnh dùng biểu tượng mặc định. Conflict/validation không ghi file; lỗi DB
  rollback và dọn file mới, file cũ chỉ dọn sau commit.

**Kiểm chứng:** 15 backend tests/135 assertions (branding, Settings và migration),
3 frontend files/12 tests, scoped ESLint/Stylelint/Pint và production build
**1 phút 5 giây** đạt. Migration thêm hai property đã chạy riêng trên DB local;
GET trang admin thật trả 200 và có branding bootstrap/favicon.

Browser kiểm page/panel/theme thật với API fixture: chọn hai PNG qua file input,
preview không đổi branding trước Save, lưu/reload áp dụng logo/favicon; desktop
sáng/tối và mobile 390 px không tràn ngang, hai card xếp dọc trên mobile.
Upload/persistence/quyền/validation/409/rollback/runtime Blade được kiểm bằng
HTTP feature tests với database/storage cô lập. QA không thay logo/favicon đang
dùng trên website development; ảnh trong screenshot là fixture có nhãn.

Contract: [Settings Branding API](SETTINGS_BRANDING_API.md).
Báo cáo/ảnh/lệnh kiểm tra: [Branding QA](qa/SITE_BRANDING_2026-10-06/README.md).
Toàn FIX 1 vẫn `IN PROGRESS`; Realtime/VPS bỏ qua trong đợt này, các mục Settings
mở rộng còn lại và kho draft dài hạn giữ trạng thái theo backlog.
