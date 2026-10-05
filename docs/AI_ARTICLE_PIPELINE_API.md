# API tạo bài AI — Task 2

Backend và các form Vue 3 đã nối API: AI Content, dialog tạo Post, regenerate, quản lý văn phong và MediaLibrary trong editor chung. Generation chỉ lưu candidate; Apply tạo Post draft khi người dùng xác nhận. Báo cáo nghiệm thu localhost tại [Task 2 QA](qa/TASK2_LOCALHOST_2026-10-05.md).

Form tạo mới dùng `writing_profile_id: null` cho mặc định website. Regenerate bỏ field này để giữ snapshot của parent; chọn lại mặc định gửi `null` rõ ràng. Brief chỉ gửi khi người dùng bật phần chỉnh brief; gửi `{}` rõ ràng để xóa brief kế thừa. File HTML được gửi multipart nguyên byte cùng encoding, không flatten thành text ở trình duyệt. Preview nguồn không gọi model.

Draft mới trả `taxonomy_origin: "manual"` cùng `category_ids`/`tag_ids`. Editor và Apply chỉ khởi tạo selector từ các ID có nhãn này; candidate cũ cần chọn hoặc xác nhận thủ công, không tự dùng gợi ý AI cũ. Save và Apply gửi `expected_version` của GET detail vừa xem; lỗi 409 giữ bản sửa để người dùng mở lại.

GET detail trả `steps[].diagnostics` và `response_diagnostics` đã allowlist. Nhánh ngắn có thể không có step checkpoint, nhưng UI vẫn đọc model/usage một lượt nếu provider báo; thiếu usage không hiển thị 0 giả. Các field raw response, source/intermediate output, key và headers không nằm trong diagnostics công khai.

## Auth và endpoint

Dùng admin bearer token. Target `post` yêu cầu `posts.manage`; các target khác dùng permission trong registry. Các run/candidate thuộc actor; không đọc/sửa run người khác. Response theo `BaseResponse`:

```json
{"success":true,"message":"...","data":{},"errors":[],"meta":[]}
```

| Method | Endpoint dưới `/api/admin` | Chức năng |
| --- | --- | --- |
| GET | `/ai-agent/targets` | Target và nhóm output có quyền |
| GET | `/ai-agent/capabilities/post` | Input/output/prompt/provider/schema/default/brief fields |
| POST | `/ai-agent/source-preview` | Extract nguồn trước generation; throttle 6/phút, chưa gọi AI |
| POST | `/ai-agent/sessions` | Tạo hoặc trả run trùng trong cửa sổ idempotency; HTTP 202 |
| GET | `/ai-agent/sessions` | Danh sách summary phân trang, không có body/nguồn/snapshot |
| GET | `/ai-agent/sessions/{uuid}` | Polling/detail/candidate khi ready |
| GET | `/ai-agent/sessions/{uuid}/candidates` | Các candidate trong cùng session |
| POST | `/ai-agent/sessions/{uuid}/regenerate` | Child run mới, parent giữ nguyên; HTTP 202 |
| POST | `/ai-agent/sessions/{uuid}/retry` | Retry thủ công cùng run bị failed/cancelled/expired |
| POST | `/ai-agent/sessions/{uuid}/cancel` | Yêu cầu hủy; không thu hồi request đã gửi upstream |
| DELETE | `/ai-agent/sessions/{uuid}` | Xóa run terminal; active trả 409 |
| PATCH | `/ai-agent/candidates/{uuid}` | Lưu chỉnh sửa candidate ready chưa Apply |
| POST | `/ai-agent/candidates/{uuid}/apply` | Áp field được chọn vào Post **draft** |

Các endpoint `/posts/ai/import/...` tương thích cũ vẫn được giữ; kết nối mới ưu tiên `/ai-agent`.

## Tạo run: text, URL, HTML và file HTML

Gửi đúng **một** nguồn `url`, `text`, `html` hoặc `html_file`. Những ví dụ sau là payload minh họa; ID model/profile/category/tag phải thay bằng ID thực đã bật/active.

```http
POST /api/admin/ai-agent/sessions
Content-Type: application/json
```

```json
{
  "target_type": "post",
  "text": "Nội dung nguồn để viết bài...",
  "title": "Tiêu đề nguồn tùy chọn",
  "language": "vi",
  "model_id": 8,
  "requested_outputs": ["title", "excerpt", "content", "seo"],
  "writing_profile_id": 12,
  "writing_brief": {
    "audience": "Người mới sử dụng công cụ",
    "article_type": "Bài giải thích",
    "purpose": "Giúp người đọc hiểu thay đổi và điều kiện sử dụng",
    "angle": "Nêu lợi ích và giới hạn có trong nguồn",
    "length": "Ngắn, khoảng 4–6 đoạn nếu nguồn đủ thông tin"
  },
  "instructions": "Giải thích tự nhiên, giữ nguyên code, không thêm benchmark.",
  "category_ids": [3],
  "tag_ids": [5, 7]
}
```

URL thay `text` bằng `url`; raw HTML thay bằng `html`:

```json
{"target_type":"post","url":"https://example.org/article","language":"vi","requested_outputs":["title","content"],"category_ids":[3],"tag_ids":[]}
```

```json
{"target_type":"post","html":"<main><article><h1>Bài nguồn</h1><p>Nội dung nguồn...</p><pre><code>const limit = 100;\nconsole.log(limit);</code></pre></article></main>","language":"vi","source_encoding":"UTF-8","requested_outputs":["title","content"]}
```

File dùng `multipart/form-data`, field `html_file` là file `.html`/`.htm` tối đa 5 MB; field scalar như `target_type`, `language`; array dùng `requested_outputs[0]=title`, `requested_outputs[1]=content`. Không tự đặt `Content-Type` có boundary khi dùng browser `FormData`. MIME cho phép `text/html`, `application/xhtml+xml`, `text/plain`; backend tiếp tục kiểm nguồn và encoding. V1 không nhận `.mhtml`.

`text` tối đa 200000 ký tự. HTML tối đa 5 MB; encoding mặc định UTF-8, có thể khai báo `Windows-1252`/`ISO-8859-1` cho file/HTML. Sau extract, budget mặc định là 100000 ký tự HTML và 250 source blocks (`config/ai-content.php`); vượt budget trả lỗi rõ ràng, không cắt thầm bài.

`model_id` vắng mặt dùng text default/fallback đã cấu hình. Client cũ có thể dùng cặp `provider`/`model` allowlisted. `prompt_key` là key registry, không nhận system prompt/schema/API key/endpoint từ request. `writing_profile_id` null/vắng mặt dùng default website; ID cụ thể phải đang bật. [API mẫu văn phong](AI_WRITING_PROFILES_API.md) cung cấp options/default và flow phân tích bài tham khảo.

Brief và `instructions` là tùy chọn. Quy tắc độ chính xác/security/schema ưu tiên cao nhất; yêu cầu riêng của bài ưu tiên hơn profile. Profile hướng dẫn cách diễn đạt, không cung cấp facts hoặc áp một mở bài/outline cố định. Không kéo bài dài bằng lặp ý để đủ số từ cứng.

Nhóm AI output: `title`, `excerpt`, `content`, `seo`, `thumbnail` tùy target. **Không gửi `taxonomy` trong `requested_outputs`**. `category_ids`/`tag_ids` do người dùng chọn thủ công; backend kiểm ID active và không bị xóa. `taxonomy` vẫn là nhóm Apply thủ công để chuyển các lựa chọn sang Post.

Khi chọn `content` cho Post và provider AI, mặc định chạy Analyze + Plan → Write → Edit. Title/excerpt/SEO-only dùng lượt ngắn; thumbnail-only bỏ generation text; deterministic chỉ extract nên không đánh giá như bài AI mới. Resource/Sound giữ nhánh tương thích hiện tại.

Thumbnail là chức năng riêng: `generate_thumbnail`, `thumbnail_mode=auto|source|generate`, `image_model_id` hoặc cặp `image_provider`/`image_model`. Khi có `requested_outputs`, nhóm `thumbnail` quyết định có yêu cầu thumbnail. Ảnh sinh tùy chọn dùng child job riêng sau content ready; image thất bại không làm mất bài đã tạo.

## Preview nguồn

Gửi cùng một loại nguồn đến `/ai-agent/source-preview`, không cần model/profile. Trả snapshot gồm:

```json
{
  "version": "article.source.v1",
  "title": "Bài nguồn",
  "source_url": "",
  "canonical_url": "",
  "content_html": "<p>Nội dung đã extract...</p>",
  "blocks": [{"id":"S001","type":"p","text":"Nội dung đã extract...","html":"<p>Nội dung đã extract...</p>"}],
  "source_images": [],
  "fetched_at": "ISO8601",
  "hash": "sha256"
}
```

Extractor bỏ phần nav/header/footer/script/ads phổ biến, chấm nội dung article/main/section và giữ code xuống dòng. Nội dung trong form không tự bị xóa. Nguồn chứa layout lạ vẫn cần người dùng xem preview; selector heuristic không bảo đảm lấy đúng mọi website. Preview không tự tạo run và không thay generation snapshot.

## Polling và checkpoint

```json
{
  "success": true,
  "data": {
    "job_id": "uuid-run",
    "session_id": "uuid-session",
    "parent_id": null,
    "operation": "create",
    "target_type": "post",
    "status": "writing",
    "current_step": "writing",
    "progress": 55,
    "steps": [{
      "key": "article.analysis-plan",
      "status": "completed",
      "attempt": 1,
      "diagnostics": {"task":"article.analysis-plan","prompt_version":"2.0","schema_version":"article.analysis-plan.v1","usage":{"total_tokens":500}},
      "started_at": "ISO8601",
      "completed_at": "ISO8601"
    }],
    "writing_profile": {"id":12,"name":"Giải thích trực tiếp","version":1},
    "quality_checks": [],
    "image_warnings": [],
    "editor_warning_count": 0,
    "source_type": "text",
    "source_format": "text",
    "draft_version": "sha256-current-draft",
    "error_code": null,
    "error": null,
    "validation_errors": []
  }
}
```

Các field diagnostics phụ thuộc provider/allowlist, có thể thiếu; không giả định luôn có token usage. Trạng thái đang chạy: `queued`, `fetching`, `extracting`, `analyzing`, `planning`, `writing`, `editing`, `validating`, `rewriting`, `seo`, `thumbnail`; `planning` hiện được gộp vào call `analyzing`. Terminal: `ready`, `failed`, `cancelled`, `expired`. `progress` là mốc xử lý, không phải phần trăm token/thời gian hay ETA.

Khi `ready`, `data.draft` chứa field cuối đã sanitize/merge, `data.draft_version` là token lưu candidate, có thumbnail/media/provenance metadata khi phù hợp. `quality_checks` trả tối đa 20 check allowlisted (`check`, `status`, `reason`, `metric`, `detected`, `count`); `image_warnings` có thể chứa `restored_unplaced_images`, `editor_warning_count` là số issue Editor, không trả nguyên message AI. Trong lúc chạy/lỗi không trả draft mới chưa validate. Detail chỉ trả summary step/diagnostics an toàn; knowledge, writing plan, intermediate output, source/profile/connection snapshot và raw provider response không xuất hiện trong `steps` public. Source preview là API có chủ đích để xem nguồn.

Backend lưu checkpoint sau khi validate từng bước. Retry dùng lại bước hoàn thành khi hash của source/profile/prompt/schema/model/connection và context khớp; bước lỗi chạy lại có chủ ý. Không tự fetch lại URL làm thay nguồn của retry. Không coi response checkpoint hoặc confidence AI là xác minh sự thật ngoài internet.

## Regenerate và retry

```http
POST /api/admin/ai-agent/sessions/{uuid}/regenerate
```

```json
{"fields":["content"],"instructions":"Giải thích ngắn hơn cho người mới.","refresh_source":false}
```

Tạo child mới trong cùng session, không sửa parent; chụp draft parent tại thời điểm request dưới row lock và chỉ merge nhóm chọn vào baseline đó. Parent sửa field/ảnh sau khi queue hoặc trong lúc retry không làm thay baseline của child. Không override profile/model thì giữ snapshot parent dù profile/Settings đã thay đổi. Gửi `writing_profile_id:12` để chọn profile bật hiện tại; gửi `writing_profile_id:null` là override rõ ràng dùng website default hiện tại. Có thể override `writing_brief`, taxonomy thủ công hoặc model hợp lệ. Override không tự thay model âm thầm khi selection sai.

`refresh_source=false` mặc định giữ source snapshot. Snapshot đã hết hạn/không còn ở run mới yêu cầu `refresh_source=true`; hệ thống không âm thầm đọc lại nguồn. `true` tạo child lấy nguồn lại, hữu ích khi URL đã cập nhật. Với text/HTML dùng dữ liệu nguồn lưu, muốn thay nội dung nguồn tạo session mới. Regenerate không cho thay `url`/`text`/`html` ngay trong endpoint này. Taxonomy đã chỉnh thủ công ở candidate parent được giữ, trừ khi request override rõ ràng.

Retry cùng UUID chỉ dành cho terminal lỗi/hủy/hết hạn; giữ source/profile/prompt snapshot. Catalog connection được kiểm trạng thái và identity, API key rotation đọc lại phía server, timeout có thể refresh khi retry thủ công. Model bị tắt/đổi endpoint cần selection mới thay vì fallback che lỗi. Lỗi timeout/kết nối không tự retry tạo chi phí; upstream HTTP retryable hiện có vẫn theo chính sách job/transport giới hạn. Client không lặp tạo/regen tự động sau timeout HTTP nếu chưa kiểm tra run đã tồn tại.

## Sửa candidate, ảnh inline và Apply

```http
PATCH /api/admin/ai-agent/candidates/{uuid}
```

```json
{
  "expected_version": "gia-tri-data.draft_version-tu-GET-detail",
  "title": "Tiêu đề sau biên tập",
  "content_html": "<p>Nội dung đã sửa...</p><figure><img src=\"https://site.example/storage/media/42/image.webp\" data-media-asset-id=\"42\" alt=\"Ảnh minh họa\"><figcaption>Chú thích ảnh</figcaption></figure>",
  "excerpt": "Mô tả ngắn",
  "category_ids": [3],
  "tag_ids": [5]
}
```

Title và content bắt buộc, các field excerpt/SEO/taxonomy là tùy chọn. Backend lock row, so sánh `expected_version`, validate ảnh trước/sau sanitize và trả draft/version mới; stale/đã Apply/chưa ready/hết hạn trả `409`. Lấy token từ server, không tự tính SHA-256 ở client.

Ảnh inline cần `img` có `data-media-asset-id` và URL chính xác thuộc MediaAsset **public**, đúng quyền attach, có file thật. Lấy từ `/api/admin/media-assets` hoặc upload bằng API hiện có; `asset.id` là ref, `asset.file.url`/conversion đã sẵn sàng là URL. Backend không tin `content_image_ids` tự khai báo: suy từ HTML và đồng bộ usage `post.content_images` khi Apply/lưu Post. Chặn ID/URL giả, event handler, `srcset`/`picture` chưa hỗ trợ, URL tạm blob/base64. Xóa ảnh khỏi nội dung không tự xóa file toàn cục.

Kết nối picker/upload TinyMCE chưa triển khai theo yêu cầu backend trước. Khi nối UI: chọn/upload ngay trong editor, giữ ref attribute, chờ upload xong trước lưu. Regenerate ảnh parent dùng placeholder ID do backend cung cấp; AI không tạo URL/ID mới. Ảnh AI quên đặt được khôi phục cuối bài để giữ asset; cần editor rà lại vị trí. Tự nhập toàn bộ ảnh nguồn/vision/AI sinh ảnh inline để giai đoạn sau.

```http
POST /api/admin/ai-agent/candidates/{uuid}/apply
```

```json
{"fields":["title","excerpt","content","seo","taxonomy"],"expected_version":"gia-tri-data.draft_version-tu-GET-detail","category_ids":[3],"tag_ids":[5]}
```

Tạo Post mới status draft; response gồm `post_id`, `fields`, provenance. Nên gửi `expected_version` lấy từ detail để chặn candidate đã đổi sau khi người dùng xem; field tùy chọn để tương thích client cũ, backend vẫn lock candidate và kiểm hash lúc Apply. Cập nhật Post có sẵn thêm `target_id`, nên gửi `expected_updated_at` lấy từ Post API để chặn target đã đổi. Apply lock cả candidate và target trong transaction. Taxonomy Apply hoàn toàn thủ công; candidate cũ chứa gợi ý AI phải được chọn/xác nhận lại, không tự áp ID legacy. Slug/actor/status theo domain actions; Publish vẫn là thao tác duyệt riêng.

## Lỗi và giới hạn chất lượng

Validation request trả `422`; quyền `403` hoặc owner `404`; xung đột/trạng thái `409`; quota run `429`; AI tắt `503`. Lỗi bên trong queue đọc tại polling `status=failed`, `error_code`, `error`, diagnostics bounded.

| Mã/nhóm | Ý nghĩa / hành động |
| --- | --- |
| `SOURCE_EMPTY`, `SOURCE_TOO_LARGE` | Kiểm preview/chọn phần nguồn đúng; không cắt thầm hoặc dựng fallback |
| `AI_PROVIDER_INVALID_JSON`, `AI_PROVIDER_SCHEMA`, `AI_PROVIDER_INCOMPLETE`, `AI_PROVIDER_REFUSAL`, `AI_PROVIDER_TOOL_OUTPUT` | Provider chưa trả output hoàn chỉnh đúng contract; kiểm model/config rồi tạo/retry có chủ ý |
| `AI_PROVIDER_TIMEOUT`, `AI_PROVIDER_HTTP_*` | Kiểm upstream/timeout/quota; không lặp request mù sau mất kết nối |
| `AI_SOURCE_REFERENCE` | Facts/block IDs/excerpts/fact coverage hoặc ref ảnh không hợp lệ |
| `AI_QUALITY_EXACT_COPY` | Văn xuôi nguồn đủ dài bị sao chép nguyên văn, loại code/quote khỏi phép so sánh |
| `AI_QUALITY_LANGUAGE` | Detector có dấu hiệu ngôn ngữ chủ đạo trái yêu cầu |
| `AI_QUALITY_GROUNDING`, `AI_QUALITY_EDITOR` | Mất code/link/số liệu quan trọng hoặc Editor báo issue blocking |
| `AI_LEGACY_TAXONOMY` | Run cũ vẫn yêu cầu AI tạo taxonomy; tạo run mới với taxonomy thủ công |
| `AI_INPUT_BUDGET` | Tổng nguồn/brief/profile/schema/artifact vượt budget request của bước, mặc định 160000 ký tự; rút gọn có chủ ý, không cắt thầm |

Similarity là warning thống kê từ vựng, không phải điểm văn phong và không ngưỡng 60% chung. Mặc định warning `0.85`, copy gate chỉ khi mỗi prose đủ `160` ký tự; language detector hỗ trợ vi/en với ít nhất `60` từ và có thể `undetermined`. Số mới không có nguồn là warning cần editor đọc; kiểm regex/anchors không chứng minh đầy đủ ngữ nghĩa. Các ngưỡng chưa được hiệu chỉnh bằng đánh giá người dùng thật. UI đọc `quality_checks`, `image_warnings`, `editor_warning_count`, không tự suy bài đã fact-check chỉ vì `ready`.

OpenAI/Gemini/http-json dùng adapter generic task và schema validation server. JSON-object/MIME mode không đồng nghĩa provider hỗ trợ native strict JSON Schema; không tự bật tính năng model chưa khai báo. Ba call tăng chi phí/thời gian, không bảo đảm chất lượng hơn một call. [Kế hoạch đánh giá](AI_ARTICLE_QUALITY_EVALUATION.md) tách test kỹ thuật khỏi đánh giá bài thực tế.

## Queue và triển khai

- Migrate database và Spatie Settings bằng luồng `php artisan migrate` hiện có; kiểm default text model/profile trước khi tạo run.
- Dùng queue worker thực tế, ưu tiên `database`/`redis` hiện có; không chạy generation trong HTTP. Backend từ chối driver `sync`/`null`, kể cả connection alias trỏ về driver đó. Process manager và scheduler phải hoạt động, không chỉ bật `.env`.
- `AI_CONTENT_PIPELINE=three_step` mặc định; `single_step` là nhánh rollout/so sánh. Mode/prompt/schema/quality/output contracts được snapshot vào run.
- Job timeout = `max(AI_IMPORT_JOB_TIMEOUT, clamp(request_timeout,5,600) × số_call + 120)`. Ba call 600 giây cần tối đa **1920 giây**; đây là ngân sách, không phải ETA. Worker có PCNTL/runtime đủ timeout; cấu hình process manager `stopwaitsecs` lớn hơn job budget để không cắt bài khi restart.
- `retry_after` của database/redis/beanstalkd tối thiểu **2000 giây**, còn tăng theo `AI_IMPORT_JOB_TIMEOUT + 60`. Luôn giữ request timeout < job budget < lease. Nếu tăng job budget hoặc dùng gateway chậm hơn, rà lại lease/process manager.
- SQS visibility timeout thuộc cấu hình queue trên AWS, không dùng `retry_after` trong file Laravel; đặt visibility lớn hơn budget tương ứng trước khi dùng SQS. Chưa có xác nhận production/VPS/SQS đã cấu hình từ các test local.
- Production dùng cache lock chia sẻ giữa worker, không dùng cache array/file riêng từng container để chống redelivery. Checkpoint giữ output đã validate nhưng không thay lock/ràng buộc state.
- Chạy `php artisan queue:restart` sau deploy/config mới theo process manager; scheduler chạy `ai-import:cleanup` và `ai:cleanup-writing-profile-analyses` theo lịch đã đăng ký. Retention mặc định 2 ngày.

Không cần giao diện mới để kiểm API bằng client admin. Hook editor và đánh giá chất lượng với provider/người đọc thật còn chờ bước tích hợp sau.
