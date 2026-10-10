# API mẫu văn phong — Task 2

Backend đã triển khai. **Systerm AI → Ai Prompt → List** dùng datatable API thật, có tạo thủ công, sửa theo ID/version, bật/tắt, xóa có xác nhận và cập nhật mẫu mặc định. **Add** phân tích nguồn, đọc tiến trình/kết quả, hủy, duyệt/lưu rồi reset để tạo mẫu kế tiếp. Profile đã duyệt xuất hiện trong select của AI Content, dialog Post và regenerate. Các phần chưa có API ở Add vẫn dùng dữ liệu tĩnh với nhãn minh họa. Tất cả endpoint dùng admin bearer token và envelope hiện có:

**Phạm vi nối giao diện hiện tại:** List/Add, CRUD, mẫu mặc định và select/brief ở các form tạo bài đã nối API. API preview riêng WritingProfiles, GET lịch sử, GET nguồn, `source_hash` public và idempotency/migration mới chưa thuộc phạm vi đã triển khai. Bảng dưới đây chỉ liệt kê endpoint đã có; việc mở rộng theo [backlog](../plans/PLAN.md#backlog-đang-dùng).

```json
{"success":true,"message":null,"data":{},"errors":[],"meta":[]}
```

## Quyền và endpoint

Prefix: `/api/admin/ai/writing-profiles`.

| Method | Endpoint | Quyền / mục đích |
| --- | --- | --- |
| GET | `/options` | `posts.manage`, `resources.create` hoặc `ai_settings.manage`; chỉ mẫu đang bật |
| GET | `/` | `ai_settings.manage`; danh sách phân trang |
| POST | `/` | `ai_settings.manage`; người dùng duyệt và lưu mẫu |
| GET | `/{id}` | `ai_settings.manage`; đọc mẫu đã lưu |
| PUT | `/{id}` | `ai_settings.manage`; sửa/bật/tắt, bắt buộc `version` |
| DELETE | `/{id}` | `ai_settings.manage`; xóa, bắt buộc `version` |
| POST | `/analyses` | `ai_settings.manage`; queue bài tham khảo, throttle 6/phút |
| GET | `/analyses/{uuid}` | `ai_settings.manage` và là người tạo; polling |
| POST | `/analyses/{uuid}/cancel` | `ai_settings.manage` và là người tạo; hủy queued/analyzing |

`GET /` nhận `page`, `per_page` (1–100, mặc định 25), `search` (tên, tối đa 160 ký tự). Response dùng `data` là danh sách và `meta.pagination` theo chuẩn `BaseResponse::paginated`.

## Phạm vi giao diện Ai Prompt hiện tại

**List** tại `/admin/ai/prompt/list` GET danh sách với `page`, `per_page`, `search`. Bảng dùng `data` là rows và `meta.pagination.total` làm tổng server; số dòng 15/25/50/100, tìm tên debounce 300 ms. Hiển thị `name`, `description`, `origin` (`reference` = phân tích bài mẫu, `manual` = nhập tay), `is_enabled`, `version`, `updated_at`. Nút mở dòng hiển thị `style_instructions` dạng text được escape; không gọi thêm model/detail API. Có loading/rỗng/lỗi/tải lại và abort/guard loại phản hồi cũ. API sắp theo tên/ID nên cột không cho sort. Nút Thêm văn phong mở Add; URL cũ `/admin/ai/prompt` redirect về List.

**Add** tại `/admin/ai/prompt/add` là trang phân tích/duyệt hiện có, thêm nút Danh sách. Các thao tác dưới đây thuộc Add.

Trang dùng GET Settings `/api/admin/settings/ai` để lấy catalog/model text và mặc định; dán nội dung hoặc đọc TXT UTF-8 cục bộ để chuẩn bị `reference_text`. URL/file HTML dùng POST `/api/admin/ai-agent/source-preview` hiện có với `target_type=post`, vì vậy cần `posts.manage` ngoài quyền quản lý AI. Tài khoản chỉ có `ai_settings.manage` dùng nguồn dán/TXT và các API WritingProfiles; giao diện khóa nút URL/HTML và giải thích quyền cần thêm. Snapshot preview trả `content_html`; frontend chuyển thành văn bản riêng để người dùng xem/sửa, không gửi snapshot/hash preview như field mới của analysis.

Luồng ở Add: POST analysis → GET polling/detail → POST cancel nếu cần → xem/sửa kết quả → POST profile mới hoặc PUT profile đã lưu còn mở với version → PUT Settings partial khi muốn đặt/gỡ mặc định. Lưu và cập nhật mặc định thành công thì Add tự về form tạo mới. GET profile hỗ trợ tải version mới hoặc kiểm tra lần lưu đã có ID. GET list vừa cấp dữ liệu cho List, vừa dùng giới hạn để đối chiếu POST lưu chưa rõ kết quả ở Add. DELETE/options trong bảng vẫn sẵn có; giao diện chỉnh sửa/xóa mọi mẫu từ List chưa triển khai.

**Tạo liên tiếp văn phong:** sau lưu thành công, xóa tên, nguồn dán/URL/file, model được chọn, kết quả, form duyệt và UUID resume tự động; nút trở về **Phân tích với AI**. Giữ catalog, cấu hình mặc định và metadata ID profile theo analysis lịch sử. Nút **Văn phong mới** cũng dọn form theo thao tác chủ động; nếu bản duyệt chưa lưu thì cần xác nhận bỏ bản đó. Lưu lỗi hoặc kết quả POST chưa xác định giữ dữ liệu; POST bất định khóa reset để kiểm tra trước. Mẫu đã lưu nhưng PUT mặc định lỗi được giữ cùng lỗi riêng, không tự làm trống. GET analysis 403/404/410 bỏ resume và mở khóa tạo mới; lỗi mạng khi tác vụ còn chạy vẫn yêu cầu kiểm tra lại.

**Quy tắc và dẫn chứng:** `rules_json` chứa những đặc điểm có thể dùng lại như giọng văn, xưng hô, mở bài, nhịp câu/đoạn, chuyển ý, từ ngữ, bố cục, tiêu đề, ví dụ và kết bài; người dùng duyệt/sửa trước khi lưu. `evidence_json` chứa đặc điểm (`feature`), trích đoạn bài mẫu (`excerpt`) và lý do nhận định (`explanation`). Backend đối chiếu trích đoạn với nguồn; giao diện giữ nguyên trích đoạn, cho sửa diễn giải hoặc bỏ dẫn chứng. Khi tạo bài, `ArticlePromptBuilder` chỉ đưa quy tắc đã duyệt và `style_instructions` của profile vào context; không đưa trích đoạn evidence sang bài mới. Dẫn chứng giúp người duyệt đánh giá nhận định của AI, không bảo đảm nhận định đó đúng về ngữ nghĩa.

`GET /analyses` và `GET /analyses/{uuid}/source` **chưa có**. Danh sách lịch sử và các điểm chất lượng/% văn phong/SEO/ảnh/tính độc đáo trên giao diện giữ minh họa, có nhãn rõ. Các field này không thuộc result AI, không được gửi khi lưu mẫu. Copy/export báo cáo sử dụng dữ liệu thực của analysis `ready` và form đang xem/sửa, xử lý cục bộ; chưa có kết quả thì khóa tải báo cáo thật.

Resume dùng UUID hiện có (session metadata hoặc nhập thủ công), GET detail tuân thủ owner/expiry. Mở UUID khác phải xác nhận bỏ form đang sửa chưa lưu. SessionStorage chỉ giữ UUID/hạn lưu, ID profile vừa lưu và cờ/tên của POST lưu chưa rõ kết quả theo tài khoản/analysis; không chứa nguồn, form/result lớn hoặc token. GET detail không trả bài mẫu nên không khôi phục nguồn sau reload. Client không gửi `client_request_id`; không tự replay POST khi timeout chưa biết UUID/ID, và không hứa idempotency server.

Khi POST lưu chưa có kết quả xác định, **Kiểm tra mẫu đã lưu** chỉ GET: có ID thì GET profile; chưa có ID thì GET list theo tên với `per_page=100`, lọc exact name và `analysis_metadata.analysis_id`. Chỉ một mẫu khớp và không còn trang chưa kiểm tra mới ghi nhận ID/version; giữ bản đang sửa. Không có/nhiều kết quả hoặc list còn trang khác giữ khóa tạo mẫu, không tự POST/default. Thao tác này không phải trang danh sách văn phong và không mở rộng API backend.

Kiểm chứng frontend sau sửa tạo liên tiếp: **39 file / 258 tests** đạt, trong đó 52 tests Ai Prompt (API 5/flow 23/UI Add 13/list 11); scoped ESLint và build cuối **33.19 giây** đạt. Browser kiểm GET analysis bị từ chối quyền → Văn phong mới → Add trống → reload vẫn trống theo [QA tạo tiếp văn phong](../qa/AI_PROMPT_NEW_PROFILE_2026-10-04.md). Save/default/reset và tạo liên tiếp hai mẫu kiểm bằng HTTP mock. QA List/Add và nguồn/catalog giữ trong [QA List/Add](../qa/AI_PROMPT_LIST_2026-10-04.md), [QA nối API](../qa/AI_PROMPT_API_2026-10-04.md). Không gọi model hoặc mutation profile từ browser trong đợt sửa này; chưa phải nghiệm thu chất lượng model thật. Test backend ở cuối tài liệu thuộc đợt backend trước.

## Chọn mẫu khi tạo bài

```http
GET /api/admin/ai/writing-profiles/options
```

```json
{
  "success": true,
  "data": {
    "default_writing_profile_id": 12,
    "items": [{"id":12,"name":"Giải thích trực tiếp","description":"Dành cho người mới","version":1}]
  }
}
```

Select có lựa chọn `Dùng mặc định website` và các `items`. `writing_profile_id` vắng mặt/null khi tạo run mới dùng website default. ID cụ thể phải tồn tại và đang bật. Backend snapshot ID/version/rules/hướng dẫn/bằng chứng lúc tạo run; thay đổi profile sau không sửa run cũ. Regenerate giữ snapshot parent khi không override rõ ràng. API article run và brief được ghi trong tài liệu pipeline.

## Tạo mẫu từ bài tham khảo

1. Người dùng dán bài và nhập tên; frontend gọi queue analysis.

```http
POST /api/admin/ai/writing-profiles/analyses
Content-Type: application/json
```

```json
{
  "name": "Giải thích trực tiếp",
  "reference_text": "Bài tham khảo người dùng dán vào, dài từ 30 đến 100000 ký tự...",
  "model_id": 8
}
```

`model_id` là tùy chọn; vắng mặt dùng model text mặc định/fallback hiện có. Client cũ có thể gửi cặp `provider`/`model` được resolver allowlist. Không nhận API key, endpoint, schema hoặc system prompt từ request. HTTP trả `202` với `data.id` UUID và `data.status=queued`; worker queue thực hiện AI call. Driver queue `sync`/`null`, kể cả connection alias, bị từ chối `422`. Giới hạn tạo analysis mỗi actor theo `ai.import.quota_per_hour` (mặc định 20/giờ).

2. Polling cho đến `ready`, `failed` hoặc `cancelled`:

```http
GET /api/admin/ai/writing-profiles/analyses/{uuid}
```

```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "name": "Giải thích trực tiếp",
    "status": "ready",
    "result": {
      "summary": "Gần gũi và đưa ngay vấn đề cho người đọc.",
      "rules": {
        "tone": "Gần gũi, chuyên nghiệp",
        "opening": "Nêu vấn đề cụ thể",
        "sentence_rhythm": "Câu ngắn phối hợp câu giải thích",
        "uncertainties": []
      },
      "evidence": [{"feature":"opening","excerpt":"Trích đoạn có thật trong bài mẫu","explanation":"Nêu ngay tình huống cần giải quyết."}],
      "style_instructions": "Diễn đạt tự nhiên, đưa ngay vấn đề và giải thích bằng ví dụ khi cần."
    },
    "provider": "gateway",
    "model": "remote-model",
    "prompt_version": "1.0",
    "schema_version": "writing-profile.analysis.v1",
    "diagnostics": {},
    "error_code": null,
    "error_message": null,
    "created_at": "ISO8601",
    "started_at": "ISO8601",
    "completed_at": "ISO8601",
    "expires_at": "ISO8601"
  }
}
```

Backend kiểm tra JSON/schema/kiểu/độ dài và excerpt thực sự xuất hiện trong bài tham khảo (chỉ chuẩn hóa HTML entities và khoảng trắng khi đối chiếu). Không có profile tự động sau `ready`. `result` chỉ có khi `ready`; polling không trả bài tham khảo, API key, base URL, connection snapshot hoặc raw provider response. Actor khác nhận `403`; quá hạn nhận `410`.

3. Người dùng xem/sửa kết quả và bấm **Lưu mẫu**; frontend chuyển tên/mô tả/rules/hướng dẫn đã duyệt sang API CRUD:

```http
POST /api/admin/ai/writing-profiles
```

```json
{
  "name": "Giải thích trực tiếp",
  "description": "Gần gũi và đưa ngay vấn đề cho người đọc.",
  "rules_json": {"tone":"Gần gũi","opening":"Nêu vấn đề","sentence_rhythm":"Kết hợp câu ngắn với câu giải thích"},
  "evidence_json": [{"feature":"opening","excerpt":"Trích đoạn có thật trong bài mẫu","explanation":"Nêu ngay tình huống cần giải quyết."}],
  "style_instructions": "Hướng dẫn người dùng đã xem và duyệt.",
  "analysis_id": "uuid-analysis-ready",
  "is_enabled": true
}
```

Trả `201`, profile có `version=1`, `origin=reference`. Analysis phải là của actor, đã `ready` và chưa hết hạn. Bằng chứng sau khi người dùng sửa vẫn được kiểm tra với bài mẫu. Model/provider/prompt/schema/source hash lấy từ server. `origin`, `created_by`, `version`, `analysis_metadata_json` từ client không được dùng khi tạo.

## Tạo thủ công và sửa mẫu

Tạo thủ công dùng cùng POST, bỏ `analysis_id`, `evidence_json` vắng mặt hoặc rỗng; `origin=manual`. Bắt buộc tên, ít nhất một rule và `style_instructions`. Mẫu thủ công chưa có bài nguồn để xác minh nên không nhận evidence tùy ý.

Các key rules được phép: `tone`, `pronouns`, `emotion`, `opening`, `sentence_rhythm`, `paragraph_rhythm`, `transitions`, `vocabulary`, `technical_terms`, `structure_patterns`, `headings`, `bullets`, `examples`, `ending`, `avoid`, `uncertainties`. Mỗi value là chuỗi không rỗng tối đa 2000 ký tự, hoặc danh sách tối đa 20 chuỗi. Không nhận object rules lồng tầng tùy ý. Analyzer schema dùng chuỗi cho các đặc điểm, danh sách cho `structure_patterns`/`avoid`/`uncertainties`.

```http
PUT /api/admin/ai/writing-profiles/12
```

```json
{"version":1,"name":"Tên đã sửa","style_instructions":"Hướng dẫn mới","is_enabled":false}
```

PUT nhận partial fields, tăng version một đơn vị. `version` cũ trả `409`; UI tải lại dữ liệu thay vì ghi đè chỉnh sửa người khác. `analysis_id` không đổi bằng PUT. Khi bài mẫu đã bị dọn, có thể giữ/xóa evidence đã duyệt, không thêm/chỉnh excerpt mới thiếu nguồn kiểm tra. Tắt hoặc xóa mẫu đang làm default sẽ gỡ website default về null.

```http
DELETE /api/admin/ai/writing-profiles/12
Content-Type: application/json
```

```json
{"version":2}
```

Xóa đúng version trả `204`; snapshot run cũ tồn tại độc lập.

## Mẫu mặc định website và vận hành

```http
PUT /api/admin/settings/ai/settings
```

```json
{"default_writing_profile_id":12}
```

Gửi null để bỏ default. Profile phải tồn tại và đang bật; Settings response có `default_writing_profile_id`. Không đưa danh sách/rules profile vào `default_system_prompt`.

Analysis job có một attempt, budget request timeout + 45 giây (tối thiểu 60); timeout/kết nối/schema/evidence sai lưu lỗi an toàn. Hủy không thu hồi được request đã gửi provider, nhưng response về trễ không ghi đè `cancelled`. Khi lỗi, frontend giữ bài người dùng dán để chỉnh và tạo analysis mới; không tự retry tạo chi phí.

`ai:cleanup-writing-profile-analyses` xóa analysis/reference quá `expires_at`. Retention dùng `ai.import.retention_days` (mặc định 2 ngày), profile và evidence đã duyệt vẫn tồn tại. Chạy scheduler/queue worker như cấu hình AI hiện tại; migrations tạo hai bảng và một typed Settings property.

## Kiểm chứng

`php artisan test --compact --filter=AiWritingProfilesApiTest`: 9 test, 88 assertions với SQLite cô lập, queue fake và HTTP fake. Bao phủ version/default/snapshot, permission, explicit human save, excerpt bịa, owner/expiry, hủy trong lúc response tới, retention và từ chối queue driver sync/null kể cả alias. Không gọi model trả phí; chất lượng nhận xét văn phong thực tế cần bộ bài tham khảo do người dùng đánh giá sau khi nối giao diện.
