# FIX 1 — Ổn định AI Content và quy trình duyệt bài

**Ngày lập:** 2026-10-02
**Trạng thái:** Đã triển khai một phần; audit lại code ngày 2026-10-03. Settings đã nối dữ liệu động cho AI & Content; chín tab còn lại vẫn dùng fixture. Tổng hợp phần chưa làm tại mục 10; browser QA cho các thay đổi mới và kiểm chứng VPS còn mở.
**Mục tiêu:** Gom các lỗi đã xác nhận trong lúc test, chốt một lần rồi triển khai đồng bộ.
**Điều chỉnh phạm vi:** Bỏ nhóm Provider/9Router khỏi công việc FIX 1 theo yêu cầu ngày 2026-10-03.

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

Task 2 được đối chiếu lại sau khi hoàn thành task 1; kết quả audit và các điểm cần chốt ở mục 12. Chưa triển khai các gate chất lượng bên dưới.

- [x] Prompt yêu cầu giữ sự thật/code, coi nguồn là dữ liệu không tin cậy và dùng hướng dẫn theo target.
- [ ] Hoàn thiện yêu cầu viết lại cách diễn đạt/cấu trúc, bảo toàn liên kết và trích dẫn; kiểm tra chất lượng kết quả thực tế.
- [ ] Bỏ `suggested_category_ids` và `suggested_tag_ids` khỏi output AI.
- [ ] AI chỉ đề xuất tên/chủ đề; backend tự ánh xạ sang taxonomy hiện có.
- [ ] Tính similarity trên text đã normalize; exact copy phải fail hoặc chuyển `quality_failed`.
- [ ] Kiểm tra đúng ngôn ngữ đầu ra.
- [x] Cho phép tạo lại toàn bài hoặc từng nhóm field sau khi sửa contract regenerate.

### C. Timeout, queue và realtime

- [x] Thời gian chờ riêng trong `ai_providers.request_timeout` (5–600 giây); provider cũ nhận 120 giây, provider mới lấy default từ config.
- [x] Job timeout tối thiểu 180 giây và đủ HTTP + 120 giây; queue `retry_after` tối thiểu 900 giây.
- [ ] Rà lại worker production để `--timeout < retry_after` và đủ lớn hơn request timeout.
- [x] Sửa frontend polling sang backoff thay vì gọi mỗi 1,2 giây liên tục.
- [ ] Broadcast event khi queued/processing/ready/failed.
- [ ] Tích hợp Laravel Echo + Reverb; polling fallback khi WebSocket mất kết nối.
- [ ] Cấu hình Supervisor/systemd/Docker cho `queue:work` và `reverb:start` trên VPS.

### D. Nguồn bài viết

- [ ] Sửa extractor: không xoá toàn bộ con khi trang bọc nội dung trong `<form>`.
- [ ] Mở rộng backend từ `article/body` sang `main/section/article` và lựa chọn container theo mật độ nội dung; frontend hiện chọn `article/main` đầu tiên.
- [x] Backend loại script/style/nav/footer/iframe và sanitize HTML theo allowlist, giữ heading/list/code/table.
- [ ] Hoàn thiện lọc ads/menu và bảo toàn nội dung trong semantic container; nguồn file hiện được chuyển thành text.
- [x] Thêm nguồn file HTML và nội dung paste khi website chặn bot (file được đọc cục bộ, extract thành text rồi gửi backend).
- [x] File giới hạn `.html/.htm`, tối đa 5 MB; text tối đa 200.000 ký tự. Extract bằng DOM tách rời, không chèn nguồn vào trang để thực thi.
- [x] URL fetcher kiểm tra Content-Type khi có header và giới hạn byte tải về.
- [ ] Hoàn thiện validation MIME/encoding cho file HTML và xử lý encoding nguồn.
- [ ] Hiển thị preview phần nguồn đã extract trước khi gửi AI.

### E. Regenerate và frontend contract

- [x] Tách payload builder cho create và regenerate.
- [x] Map output về sáu nhóm backend nhận: `title`, `excerpt`, `content`, `seo`, `taxonomy`, `thumbnail`.
- [x] Không gửi `prompt_key`, provider, model hoặc optional field khi giá trị rỗng/null.
- [x] Backend tương thích payload cũ có kiểm soát và chỉ coi giá trị đã điền là override.
- [x] Giữ parent candidate, tạo child run mới và theo dõi đúng `job_id`.

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

Trang `AI Drafts` là luồng dự kiến. Hiện trang `AI Content` dùng candidate trong `ai_imports`, chưa có lớp biên tập lưu riêng. Các việc cần tối thiểu:

- [x] Danh sách hiển thị đang xử lý/chờ duyệt/đã áp dụng/lỗi/hủy/hết hạn; chờ duyệt và đã áp dụng hiện là trạng thái suy ra từ tác vụ.
- [ ] Thêm `ai_content_drafts`, trạng thái duyệt/từ chối thực sự, người duyệt, lịch sử/lý do và chính sách lưu dài hạn độc lập với retention tác vụ.
- [ ] Lưu snapshot nguồn đã extract; xem nguồn và kết quả AI cạnh nhau, hiển thị similarity.
- [x] Chỉnh sửa candidate trước khi duyệt.
- [x] Tạo lại toàn bài hoặc từng phần.
- [x] API apply ép Post `status=draft` và lưu provenance/liên kết với tác vụ AI.
- [ ] Nối nút duyệt/apply và từ chối trên trang AI Content vào workflow biên tập riêng.
- [x] Danh sách hiển thị thời gian tạo.
- [ ] Hiển thị provider/model/prompt version và lỗi từng tác vụ an toàn từ metadata, kể cả sau reload; lưu và hiển thị usage/token.
- [ ] Không trộn trạng thái kỹ thuật với trạng thái biên tập.

## 4. Thứ tự triển khai phần còn lại sau audit

1. Chốt contract response, prompt, similarity và chính sách lỗi/fallback.
2. Sửa provider parser/validator, logging metadata và test adapter.
3. Sửa extractor, validation MIME/encoding và preview nguồn; HTML file input và regenerate contract đã có.
4. Thêm migration/model/API cho `ai_content_drafts`, lưu dài hạn và thao tác approve/reject.
5. Nối UI duyệt/từ chối, compare/similarity và metadata; editor/regenerate đã có.
6. Hoàn thiện nhánh thumbnail generate nếu dùng model ảnh trong luồng AI Content/Post (xem 9.7).
7. Thêm event broadcast, Echo/Reverb và polling fallback; cấu hình process manager trên VPS, smoke test queue/reboot/reconnect.
8. Nối chín tab Settings còn lại và hoàn thành browser QA theo mục 9.

## 5. Tiêu chí hoàn thành

- AI không được báo `ready` nếu thiếu/sai field bắt buộc của nhóm đầu ra đã chọn; title/content của nhóm không chọn có thể giữ từ nguồn hoặc candidate cha.
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
- [ ] Thay fixture của chín tab ngoài AI bằng dữ liệu từ API admin có xác thực Sanctum.
- [ ] Áp dụng redaction/write-only secret cho các nhóm ngoài AI: SMTP password, reCAPTCHA và webhook secret; không để frontend đọc `.env`.
- [ ] Áp dụng ưu tiên database → config/env cho các nhóm ngoài AI; AI Settings đã có cơ chế lưu/default riêng.
- [ ] Áp dụng allowlist, transaction, audit actor/key và bảo vệ secret cho mọi đường ghi Settings ngoài AI.

### 9.2 Bản đồ dữ liệu theo tab

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

### 9.3 API và quyền

- [ ] Thêm `GET /api/admin/settings` trả envelope theo section, options, capability và `updated_at`; secret chỉ có cờ `configured`.
- [ ] Thêm `PATCH /api/admin/settings/{group}` cho `site`, `media`, `seo`, `mail`, `security`; mỗi group có FormRequest và resource/transform riêng. Không thêm writer thứ hai cho AI.
- [x] Giữ và dùng các route AI hiện có: `GET /api/admin/settings/ai`, `PUT /api/admin/settings/ai/settings`.
- [ ] Aggregate Settings endpoint compose dữ liệu AI từ service hiện có.
- [ ] Thêm endpoint riêng cho `webhooks`, `cron`, `languages` và `system-info` khi dữ liệu không phải typed setting.
- [ ] Bổ sung `settings.view` vào permission catalog, cấp `settings.manage` cho role phù hợp bằng migration/seeder; `ai_settings.manage` vẫn tách riêng.
- [ ] Thêm optimistic concurrency bằng `updated_at` hoặc version để tránh ghi đè khi mở nhiều tab; trả `409` khi xung đột.

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
- [ ] Feature tests cho các nhóm Settings còn lại: validation, transaction, audit, redaction và `409` concurrency, kể cả concurrency của AI Settings.
- [ ] Test runtime cho media/SEO/mail/security sau refresh; AI snapshot/worker refresh đã có, kiểm chứng runtime trên VPS vẫn chờ.
- [ ] Frontend tests cho các nhóm còn lại, dirty state, conflict handling và stale response.
- [ ] Browser QA: đủ 10 tab ở desktop/mobile, dark mode, refresh sau save, thiếu quyền, timeout API và empty state.
- [ ] Hoàn thành khi không còn giá trị nghiệp vụ hard-code trong `index.vue`, mọi field editable có API + validation + audit, field read-only có nguồn runtime rõ ràng.

### 9.6 Điểm cần chốt trước khi code

- [ ] Cron chỉ đọc scheduler/run history ở đợt đầu hay cho tạo/sửa schedule trong DB?
- [ ] Languages dùng danh sách locale trong config hay cần bảng quản trị locale riêng?
- [ ] SMTP/GA/GSC/reCAPTCHA cho phép ghi DB có mã hóa hay vẫn bắt buộc env ở production?
- [ ] Nút “Lưu thay đổi” lưu tuần tự từng group hay có endpoint bulk transaction?

### 9.7 Đợt 1: AI & Content đã nối dữ liệu — 2026-10-03

- [x] Tách `AiContentSettingsPanel` và `useAiContentSettings`; tải/lưu qua service AI Settings chung, có loading, retry, catalog trống, thông báo và lỗi từng trường.
- [x] Provider/model lấy catalog thật; chỉ chọn model `text_generation` đang bật, khả dụng và thuộc provider đã chọn. Provider được suy ra từ model ID, không tạo setting trùng.
- [x] Lưu `default_text_model_id`, `default_image_model_id`, `default_temperature`, `min_word_count` (0–10.000), `default_system_prompt` (tối đa 10.000 ký tự), `auto_thumbnail`, `auto_seo`; partial update giữ các setting không được gửi.
- [x] Thêm selector model tạo ảnh thumbnail độc lập với model nội dung; hiển thị provider/model thật và lọc theo capability `image_generation`, trạng thái khả dụng và driver hỗ trợ ảnh.
- [x] Migration bổ sung bốn property còn thiếu đã chạy ở local; đối chiếu xác nhận sáu setting cũ và catalog provider/model được giữ nguyên.
- [x] Tác vụ mới chụp model, temperature, system prompt và số từ khuyến nghị vào snapshot; lựa chọn SEO/thumbnail riêng của tác vụ được ưu tiên. Số từ là khuyến nghị trong prompt, có thể điều chỉnh bằng yêu cầu độ dài riêng.
- [x] Form AI Content và dialog tạo Post nhận mặc định SEO/thumbnail đã lưu, giữ lựa chọn đã sửa khi API tải muộn và áp dụng mặc định khi tạo form mới.
- [x] AI Content và dialog tạo Post hiện lấy thumbnail từ URL nguồn; thiếu ảnh nguồn thì để trống, không tự chuyển sang model ảnh. Tắt SEO loại field SEO tự tạo; regenerate field riêng theo lựa chọn.
- [ ] Hoàn thiện thumbnail `generate` trong luồng AI Content/Post: mở lựa chọn trên UI và nối ảnh sinh ra vào `draft.thumbnail` để list/apply dùng được. Backend nhánh ảnh hiện ghi `parent.result_json.image.media_asset_id`, còn list/apply đọc `draft.thumbnail.media_asset_id`.
- [x] Feature/frontend tests kiểm tra lưu rồi đọc lại, validation/quyền, catalog và lựa chọn model, snapshot, automation flags và regenerate.
- [ ] Browser QA lưu/tải lại với tài khoản đăng nhập của người dùng.

### 9.8 Chuẩn hóa config và chọn đầu ra AI — 2026-10-03

- [x] `ai-agent.php` khai báo target, nhóm đầu ra, prompt và schema; `ai-import.php` giữ giới hạn vận hành; `ai-providers.php` tập trung metadata/adapter và kết nối từ `.env`.
- [x] Provider/model trong database được ưu tiên; connection môi trường tham chiếu metadata driver, không khai báo lại tên/adapter ở `ai-agent.php`.
- [x] `targets.*.outputs` là các nhóm được phép chọn. `output_definitions` chứa nhãn, trường canonical và loại nguồn phù hợp; API trả options từ config.
- [x] Bỏ bốn checkbox trong Tùy chọn AI, thay bằng một `AppSelect` chọn nhiều tag. Lựa chọn được giữ khi tải lại, giới hạn theo tài nguyên và đặt lại theo defaults khi mở form mới.
- [x] Tác vụ chụp `requested_outputs`/`fields`; prompt và kết quả chỉ cập nhật nhóm được chọn. Nhóm không chọn giữ dữ liệu nguồn hoặc candidate cha; tiêu đề nhập tay được giữ. Request cũ không có lựa chọn vẫn được hỗ trợ.
- [x] Thumbnail trong AI Content vẫn lấy từ URL nguồn; không tự chuyển sang model tạo ảnh khi thiếu ảnh nguồn.
- [x] Kiểm thử config/registry, lựa chọn theo target, snapshot, lọc kết quả provider và giao diện select. Build production được cập nhật.
- [ ] Browser QA select với tài khoản đăng nhập của người dùng.

## 10. Tổng hợp phần còn lại — audit code 2026-10-03

Các checkbox bên trên gồm cả task, tiêu chí và quyết định. Không cộng checkbox trống thành số task độc lập. Những nhóm chưa hoàn thành:

| Nhóm | Phần còn thiếu | Tham chiếu |
| --- | --- | --- |
| Structured output | Tuning temperature và quyết định debug raw response ở đợt riêng. Task 1 đã xong code, diagnostics, regression, build, restart worker và browser smoke local với output được kiểm soát | 2A, 11.6 |
| Chất lượng nội dung | Rewrite đầy đủ, similarity/exact-copy, kiểm ngôn ngữ, đề xuất tên/chủ đề và ánh xạ taxonomy thay cho AI trả ID; đã audit, chưa triển khai | 2B, 12 |
| Extractor/nguồn | Giữ nội dung trong form, chọn container theo mật độ, lọc ads/menu, MIME/encoding và preview nguồn đã extract | 2D |
| Biên tập AI | `ai_content_drafts`, lưu dài hạn, approve/reject UI/API, lịch sử/lý do, so sánh nguồn/kết quả, metadata và lỗi từng tác vụ sau reload | 3 |
| Thumbnail sinh bằng AI | Nối lựa chọn generate và kết quả ảnh vào thumbnail của candidate/Post; hiện luồng tạo bài chỉ lấy ảnh nguồn hoặc để trống | 9.7 |
| Realtime/VPS | Broadcast, Echo/Reverb, process manager, worker/scheduler production và QA reboot/reconnect | 2C |
| Settings ngoài AI | Chín tab: Tổng quan, Media, SEO, Email, Cron Jobs, Webhooks, Languages, Security, System Info; API, typed settings, quyền, runtime và tests tương ứng | 9.1–9.6 |
| Settings workflow/QA | Concurrency/409 (cả AI), dirty/conflict/stale-response handling và browser QA đăng nhập desktop/mobile/dark mode | 9.3, 9.5, 9.7, 9.8 |

**Đã có, không nằm trong phần chờ:** chuẩn hóa ba config AI, select nhiều tag từ config, AI Settings động, model thumbnail selector, nguồn HTML/paste, editor/regenerate, API apply Post draft/provenance, thumbnail nguồn và hiển thị trong danh sách, model catalog sync/test từng model, timeout/queue budget, polling backoff, kiểm kết quả AI trước `ready`, diagnostics theo allowlist và lỗi validation sau reload.

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

## 12. Audit task 2 — Prompt và chất lượng nội dung

**Ngày kiểm tra:** 2026-10-03. **Trạng thái:** Đã kiểm tra code và chạy hai ca xác minh với HTTP fake; chưa triển khai task 2. Task này là nhóm Chất lượng nội dung ở mục 10 / mục 2B, tiếp sau task 1 structured output.

**Mục tiêu task 2:** Hoàn thiện prompt viết lại bài và bổ sung kiểm chứng chất lượng đầu ra trước khi báo thành công. Prompt mặc định hiện khá cơ bản: đúng ngôn ngữ, giữ sự thật/code, trả JSON và bố cục Post có mở bài/heading/kết luận. System prompt cùng yêu cầu số từ từ Settings được ghép thêm vào request; hiện chúng là hướng dẫn cho model, chưa có gate xác nhận bài đạt yêu cầu chất lượng.

Task 2 bao gồm nâng yêu cầu diễn đạt/bố cục, bảo toàn thông tin/liên kết/trích dẫn, phát hiện sao chép, kiểm ngôn ngữ và ánh xạ danh mục/tag từ tên do AI đề xuất. Mỗi kiểm tra phải có kết quả và lý do rõ ràng; giữ nguyên quy trình candidate chờ duyệt, field không chọn và candidate cha khi regenerate lỗi.

**Giới hạn của kiểm chứng tự động:** Similarity thấp không chứng minh bài viết hay hoặc đúng sự thật. Metrics phát hiện lỗi cụ thể và hỗ trợ người biên tập; độ chính xác, sự đầy đủ, tính nhất quán và chất lượng diễn đạt vẫn cần đối chiếu nguồn và duyệt bài. Không coi JSON/schema hợp lệ hoặc vượt gate similarity là chứng nhận chất lượng tổng thể. Chưa đưa việc tra cứu nguồn bên ngoài hay một lượt AI chấm bài có phí vào phạm vi bắt buộc của task 2.

### 12.1 Hiện trạng đã xác nhận

| Hạng mục | Hiện trạng | Bằng chứng trong code |
| --- | --- | --- |
| Prompt theo tài nguyên | Đã yêu cầu đúng ngôn ngữ, giữ sự thật/code, coi nguồn là dữ liệu không tin cậy; Post có mở bài/heading/kết luận. Chưa yêu cầu cụ thể viết lại cấu trúc/cách diễn đạt và giữ liên kết/trích dẫn; chưa đối chiếu chất lượng kết quả | `config/ai-agent.php:120,130`, `Registries/PromptRegistry.php:157` |
| Bảo toàn HTML | Sanitizer giữ link an toàn, blockquote, code và table nếu AI trả về; chưa kiểm AI có bỏ liên kết, trích dẫn hoặc sửa code so với nguồn | `AiContentSanitizer.php:28–47`, `ArticleImportServiceTest.php:105` |
| Danh mục và tag | AI vẫn được yêu cầu trả `suggested_category_ids/suggested_tag_ids`; context không cung cấp catalog taxonomy. Backend chỉ loại ID không tồn tại, chưa ánh xạ tên/chủ đề | `config/ai-agent.php:53–57,140–141`, `AbstractStructuredAiProvider.php:133–138,224–228`, `ArticleImportService.php:164–167,436–440` |
| Similarity / exact-copy | Chưa có phép đo, cấu hình ngưỡng hoặc gate chặn sao chép. Task 1 kiểm schema, kiểu dữ liệu và nội dung không rỗng | `AiOutputValidator.php:43–104`, `ArticleImportService.php:139–143` |
| Ngôn ngữ đầu ra | Ngôn ngữ được gửi trong prompt/context; chưa có detector hoặc gate kiểm kết quả. Request chỉ kiểm chuỗi tối đa 12 ký tự | `ArticleImportService.php:132`, `AbstractStructuredAiProvider.php:133–138`, `AiImportRequest.php:98` |
| Errors / diagnostics / tests | Có thể dùng trạng thái `failed` và snackbar của task 1. Chưa có mã lỗi/metadata/tests cho similarity, sai ngôn ngữ hoặc ánh xạ taxonomy bằng tên | `AiResponseDiagnostics.php`, `ProcessAiImportJob.php`, `utils/aiErrors.js`, tests AI hiện có |

**Rủi ro taxonomy:** ID do AI đoán mà tình cờ tồn tại vẫn được nhận, dù không đúng chủ đề. ID không tồn tại bị lọc thành `[]` nhưng tác vụ vẫn có thể thành công. `existingIds()` chưa lọc taxonomy inactive. Category có phân cấp và tên có thể trùng; resolver mới không được tùy ý lấy bản ghi đầu tiên.

### 12.2 Xác minh hành vi hiện tại

Chạy `OpenAiProvider` và `ArticleImportService` thật với phản hồi `finish_reason=stop` từ HTTP fake, chọn riêng `content`:

- **Exact copy:** output giữ nguyên toàn bộ HTML nguồn; pipeline vẫn nhận kết quả hợp lệ.
- **Sai ngôn ngữ:** yêu cầu `vi`, nguồn tiếng Việt, output chỉ chứa đoạn tiếng Anh; pipeline vẫn nhận kết quả hợp lệ.

Hai ca xác minh dùng model `AiImport` chưa lưu, không ghi tác vụ/Post, không gửi request AI thật và không thay đổi Settings. Đây là bằng chứng thiếu gate chất lượng, không phải regression của task 1. Không chạy lại bộ test/build vì đợt audit chỉ cập nhật tài liệu.

### 12.3 Các phần cần triển khai tiếp

1. Hoàn thiện prompt: viết lại cách diễn đạt/cấu trúc, giữ sự thật, code, URL liên kết và attribution/trích dẫn. Tăng version khi thay contract; kiểm tra cả URL/text, Post/Resource/Sound và lựa chọn từng nhóm.
2. Đổi output taxonomy generation mới sang danh sách tên/chủ đề có giới hạn. Backend ánh xạ taxonomy active, xử lý tên trùng/không khớp rõ ràng, không tự tạo taxonomy. Candidate vẫn trả `category_ids/tag_ids` do backend xác định để apply/UI hoạt động; giữ khả năng đọc candidate cũ và phân biệt schema của run đã xếp hàng.
3. Thêm gate chất lượng **sau sanitize output mới, trước merge nguồn/parent và trước xử lý thumbnail**. Đo similarity với phần nguồn trong memory; không so sánh draft đã merge vì field không chọn vốn được giữ nguyên. Bỏ qua phép kiểm rewrite cho deterministic và thumbnail-only.
4. Kiểm ngôn ngữ trên văn bản mới được AI tạo theo lựa chọn. Có trạng thái chưa xác định/bỏ qua cho nguồn quá ngắn, nhiều tên riêng hoặc code; không suy ra ngôn ngữ chỉ từ việc có/không có dấu tiếng Việt.
5. Lưu metrics/lý do an toàn theo allowlist và dùng lỗi `failed` không retry cho quality gate. Bổ sung test normalize HTML/entity/Unicode/whitespace, sao chép, paraphrase, sai ngôn ngữ, code/trích dẫn, taxonomy tên trùng/inactive/không khớp, partial regenerate, giữ parent và lỗi sau reload.

**Các quyết định chưa chốt:** cách đo và ngưỡng similarity; `60%` ở mục 6 mới là đề xuất. Cần chốt exact-copy fail ngay hay đưa ra biên tập, và cách xử lý đoạn ngắn/code/trích dẫn để tránh lỗi sai. Chưa có quyết định mới được áp dụng trong đợt audit này.

Snapshot nguồn dài hạn và giao diện so sánh nguồn/kết quả thuộc mục 3; gate có thể dùng nguồn đã extract trong memory hiện nay. Regenerate URL hiện tải lại nguồn, nên chưa có bảo đảm nguồn giống thời điểm tạo candidate cha.

### 12.4 Điểm tiếp tục trên máy ở nhà

- Task 1 đã triển khai và kiểm thử; bằng chứng tại 11.6.
- Task 2 đã audit và xác định phạm vi; code kiểm chất lượng chưa triển khai.
- Bước tiếp theo: chốt quy tắc similarity/exact-copy và các ngoại lệ, rồi cụ thể hóa contract prompt/schema, taxonomy resolver, quality gate và ma trận test trước khi triển khai.
- Dùng `docs/fix_1.md` làm nguồn theo dõi phần còn lại; các nhóm Settings ngoài AI, extractor, biên tập dài hạn và realtime/VPS vẫn mở.
