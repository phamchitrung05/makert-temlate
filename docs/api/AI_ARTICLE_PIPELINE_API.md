# API tạo bài AI — Task 2

Backend và các form Vue 3 đã nối API: AI Content, regenerate, quản lý văn phong và MediaLibrary trong editor chung. Tạo nội dung tập trung ở AI Content; dialog tạo bài AI trong Post đã gỡ. Generation lưu candidate/checkpoint tạm. Từ 07/10/2026, Duyệt/Apply thành công tạo Post draft và đưa riêng bản AI gốc của candidate được chọn vào kho bền vững. Báo cáo nghiệm thu localhost ban đầu tại [Task 2 QA](../qa/TASK2_LOCALHOST_2026-10-05.md); review workflow trên dữ liệu hiện có tại [Review QA](../qa/AI_CONTENT_REVIEW_2026-10-05.md).

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
| GET | `/ai-agent/candidates/{uuid}/review` | Nguồn đã lưu, draft và trạng thái duyệt có version |
| GET | `/ai-agent/candidates/{uuid}/review/history` | Lịch sử Spatie Activitylog phân trang |
| POST | `/ai-agent/candidates/{uuid}/approve` | Duyệt, tạo một Post **draft** mới và ghi audit |
| POST | `/ai-agent/candidates/{uuid}/reject` | Từ chối với lý do bắt buộc, không tạo Post |

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

File dùng `multipart/form-data`, field `html_file` là file `.html`/`.htm` tối đa 5 MB; field scalar như `target_type`, `language`; array dùng `requested_outputs[0]=title`, `requested_outputs[1]=content`. Không tự đặt `Content-Type` có boundary khi dùng browser `FormData`. MIME cho phép `text/html`, `application/xhtml+xml`, `text/plain`; backend tiếp tục kiểm nguồn và encoding. `.mhtml/.mht` chưa hỗ trợ; extension sai trả 422 với thông báo phạm vi file trước khi queue.

`text` tối đa 200000 ký tự. HTML tối đa 5 MB; encoding mặc định UTF-8, có thể khai báo `Windows-1252`/`ISO-8859-1` cho file/HTML. Sau extract, budget mặc định là 100000 ký tự HTML và 250 source blocks (`config/ai-content.php`); vượt budget trả lỗi rõ ràng, không cắt thầm bài.

`model_id` vắng mặt dùng text default/fallback khả dụng đã cấu hình, hoặc real provider legacy có kết nối hợp lệ. Từ 2026-10-07, Auto thiếu model/kết nối trả 422 ở `model_id` trước queue, không âm thầm dùng deterministic. Client cũ vẫn có thể chọn cặp `provider`/`model` allowlisted; deterministic phải được chọn rõ ràng. `prompt_key` là key registry, không nhận system prompt/schema/API key/endpoint từ request. `writing_profile_id` null/vắng mặt dùng default website; ID cụ thể phải đang bật. [API mẫu văn phong](AI_WRITING_PROFILES_API.md) cung cấp options/default và flow phân tích bài tham khảo.

Brief và `instructions` là tùy chọn. Quy tắc độ chính xác/security/schema ưu tiên cao nhất; yêu cầu riêng của bài ưu tiên hơn profile. Profile hướng dẫn cách diễn đạt, không cung cấp facts hoặc áp một mở bài/outline cố định. Không kéo bài dài bằng lặp ý để đủ số từ cứng.

Nhóm AI output: `title`, `excerpt`, `content`, `seo`, `thumbnail` tùy target. **Không gửi `taxonomy` trong `requested_outputs`**. `category_ids`/`tag_ids` do người dùng chọn thủ công; backend kiểm ID active và không bị xóa. `taxonomy` vẫn là nhóm Apply thủ công để chuyển các lựa chọn sang Post.

Khi chọn `content` cho Post và provider AI, mặc định chạy Analyze + Plan → Write → Edit. Title/excerpt/SEO-only dùng lượt ngắn; thumbnail-only bỏ generation text; deterministic chỉ extract nên không đánh giá như bài AI mới. Resource/Sound giữ nhánh tương thích hiện tại.

Thumbnail là chức năng riêng: `generate_thumbnail`, `thumbnail_mode=auto|source|generate`, `image_model_id` hoặc cặp `image_provider`/`image_model`. Khi có `requested_outputs`, nhóm `thumbnail` quyết định có yêu cầu thumbnail. Ảnh sinh tùy chọn dùng child job riêng sau content ready; image thất bại không làm mất bài đã tạo.

### Thumbnail AI — cập nhật 2026-10-06

- UI chọn `source` (URL) hoặc `generate` (URL/text/HTML/file/đề bài). Model ảnh
  được chọn độc lập model bài viết; default/fallback ảnh lấy từ AI Settings.
- `thumbnail_prompt` tùy chọn, tối đa 4.000 ký tự; bỏ trống dùng prompt/tiêu đề
  của draft. `generate` cần `media.upload`; sai quyền trả 403, model ảnh không
  khả dụng/sai capability/driver trả 422 ở `image_model_id` trước khi tạo run.
- `POST /ai-agent/sessions/{id}/regenerate` nhận thêm `thumbnail_mode`,
  `thumbnail_prompt`, `image_model_id` hoặc `image_provider`/`image_model`.
  `fields=["thumbnail"]` giữ nội dung snapshot và bỏ lượt model text.
- Content được commit `ready` trước khi xếp hàng child ảnh; dispatch ảnh sau
  transaction tạo child. Kết quả ảnh gắn vào `draft.thumbnail` với `media_asset_id`,
  `origin=generated`, `image_run_id`, `alt_text`, `source_url=null`.
- Summary/detail/review trả `thumbnail_generation`: `job_id`, `status`
  (`queued/generating/ready/failed/cancelled/expired`), `progress`, `error_code`,
  `error` an toàn, `provider`, `model`. Detail có `thumbnail_options`
  (`mode/model_id/prompt`) để khởi tạo regenerate. Không trả key/connection/raw response.
- Frontend đọc **parent** tới khi ảnh terminal dù nội dung đã `ready`. Retry
  hoặc cancel dùng endpoint session hiện có với **child job_id** trong
  `thumbnail_generation`; sau đó GET parent. Retry chỉ chạy ảnh, chặn child cũ,
  sai owner, parent hết hạn hoặc đã quyết định; không tự retry POST timeout.
- Review thêm `thumbnail`, `thumbnail_generation`, `can_approve`. Có thể edit
  hoặc reject khi chờ ảnh; approve trả 409 khi ảnh pending. Sau lỗi/hủy có thể
  duyệt mà bỏ field thumbnail. Ảnh về sau edit làm đổi draft/review version;
  quyết định dùng version cũ trả 409. Kết quả trễ không đổi bài đã quyết định.
- Provenance thumbnail lấy provider/model/run từ **image run**, kể cả thumbnail
  kế thừa trong snapshot regenerate; parent vẫn ghi applied fields đầy đủ.
  Duyệt/Apply và lưu Post bằng `ai_runs` dùng cùng boundary provenance.

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

Với kho v1, retry thủ công bỏ checkpoint chưa duyệt của lần trước rồi tăng `generation_no` dưới lock;
retry kỹ thuật của queue giữ cùng generation. Regenerate có UUID mới. Summary/detail
trả `generation_no`; worker của generation cũ bị bỏ qua. Retry khi worker giữ
process lock hoặc bản đã được duyệt trả `409`; xóa run active cũng trả `409`.
Checkpoint tạm giữ bản AI trước biên tập, không xuất hiện trong response.

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

Ảnh trong candidate AI cần `img` có `data-media-asset-id` và URL chính xác thuộc MediaAsset **public**, đúng quyền attach, có file thật. Ref nội bộ phục vụ kiểm nguồn và regenerate; không trở thành Gallery khi Apply. Post lưu HTML/link ảnh, không tạo usage content; Gallery dùng `media.gallery_image_ids`/`post.gallery` độc lập. Candidate chặn ID/URL giả, event handler, `srcset`/`picture` chưa hỗ trợ và URL tạm blob/base64. Xóa ảnh khỏi nội dung không tự xóa file toàn cục. Contract Post cập nhật 2026-10-06 tại [ảnh content/Gallery](TASK2_INLINE_MEDIA_API.md).

Picker/upload TinyMCE đã nối MediaLibrary trong editor dùng chung của Post và AI Content: chọn/upload ngay trong editor, nhập alt/caption, giữ ref attribute và chặn lưu khi upload chưa xong. Tiny Cloud giữ nguyên license; local QA dùng `localhost` với key hiện có. Regenerate ảnh parent dùng placeholder ID do backend cung cấp; AI không tạo URL/ID mới. Figure có một ảnh giữ cả caption khi khôi phục; ảnh thiếu vị trí hợp lệ được khôi phục cuối bài để bảo toàn asset, cần editor rà vị trí. Tự nhập toàn bộ ảnh nguồn/vision/AI sinh ảnh inline để giai đoạn sau.

Browser nghiệm thu Tiny Cloud thật và Apply được ghi tại
[Task 2 hoàn tất kỹ thuật](../qa/TASK2_COMPLETE_2026-10-05/README.md).
Menu dùng `ui_mode: 'split'`; cửa sổ TinyMCE gắn vào body có lớp overlay chung
và event `editorDialog` để caller nhường focus đúng thời gian. Quy chuẩn dialog
ở `PROJECT_STRUCTURE.md` mục 4.3.1. Đợt QA này dùng SQLite, không thay thế bằng
chứng MySQL/cancel/resume của 12.24; UI fixture replay không tính là call AI mới.

```http
POST /api/admin/ai-agent/candidates/{uuid}/apply
```

```json
{"fields":["title","excerpt","content","seo","taxonomy"],"expected_version":"gia-tri-data.draft_version-tu-GET-detail","category_ids":[3],"tag_ids":[5]}
```

Tạo Post mới status draft; response gồm `post_id`, `fields`, provenance. Nên gửi `expected_version` lấy từ detail để chặn candidate đã đổi sau khi người dùng xem; field tùy chọn để tương thích client cũ, backend vẫn lock candidate và kiểm hash lúc Apply. Cập nhật Post có sẵn thêm `target_id`, nên gửi `expected_updated_at` lấy từ Post API để chặn target đã đổi. Apply lock cả candidate và target trong transaction. Taxonomy Apply hoàn toàn thủ công; candidate cũ chứa gợi ý AI phải được chọn/xác nhận lại, không tự áp ID legacy. Slug/actor/status theo domain actions; Publish vẫn là thao tác duyệt riêng.

## Duyệt/từ chối AI Content trên dữ liệu hiện có — 2026-10-05

Mục database/model `ai_content_drafts` và lưu dài hạn được chủ dự án tạm hoãn.
Trạng thái hiện tại nằm trong `ai_imports.source_meta_json.editorial`, độc lập
với trạng thái job `ready/failed/...`: `pending_review → approved` hoặc
`pending_review → rejected`. Bản đã Apply từ trước được suy ra `approved`;
người duyệt/thời điểm cũ để null nếu không có dữ liệu, không dựng lịch sử giả.

Các API review dùng admin bearer token + `posts.manage`, chỉ run thuộc actor
hiện tại và target Post. Chưa mở quyền duyệt chéo owner hoặc nhóm permission mới.
GET review chỉ trả nguồn snapshot đã sanitize (fallback `source_text` nếu có),
draft allowlist, `review`, `draft_version`, `review_version`, `can_review`,
`can_edit`, `has_thumbnail`, `applied_target_id`, thời hạn và provider/model.
Không fetch lại URL, không gọi AI, không trả key/profile/checkpoint thô.

Ý nghĩa hai cột đối chiếu (2026-10-06):

- **Nguồn đã lưu:** snapshot nội dung đầu vào đã extract/làm sạch tại
  `ai_imports.source_meta_json.article_source.content_html`; thiếu snapshot thì
  dùng `ai_imports.source_text` nếu có. Không phải toàn bộ HTML trang web hoặc
  nội dung mới nhất từ URL. Viết tự do có thể hiển thị yêu cầu đầu vào đã lưu.
- **Nội dung AI sau biên tập:** bản nháp hiện tại tại
  `ai_imports.result_json.draft.content_html` (`content` là alias), gồm kết quả AI
  và chỉnh sửa thủ công đã lưu qua PATCH candidate. Không phải bản output ban đầu
  bất biến hoặc bản lịch sử của từng lần sửa.
- Lịch sử sự kiện sửa/duyệt/từ chối ở `activity_log`, log name `ai-content`,
  UUID trong `properties.candidate_id`; sự kiện sửa chỉ lưu field đã thay đổi.
  Trạng thái quyết định hiện tại ở `source_meta_json.editorial`. Approve ghi nội
  dung được chọn vào `posts.content` với status draft. Nguồn/draft trong
  `ai_imports` vẫn chịu `expires_at` và cleanup hiện hành.

```http
POST /api/admin/ai-agent/candidates/{uuid}/approve
```

```json
{
  "fields": ["title", "excerpt", "content", "seo", "taxonomy"],
  "category_ids": [3],
  "tag_ids": [5],
  "expected_version": "data.draft_version-tu-GET-review",
  "expected_review_version": "data.review_version-tu-GET-review",
  "reason": "Đã đối chiếu nguồn"
}
```

`fields` phải gồm `title` vì API mới luôn tạo Post mới; taxonomy chỉ nhận ID
thủ công hợp lệ. Hai version bắt buộc là hash 64 ký tự từ GET vừa xem, không
tự tạo ở client. `reason` tùy chọn khi duyệt, tối đa 2000 ký tự. Không nhận
`target_id`/`expected_updated_at`; actor/thời điểm lấy từ server, Post luôn draft.
Post, SEO/media/taxonomy, provenance, metadata quyết định và audit được ghi cùng
transaction, lock candidate và kiểm version; chỉ một quyết định được chấp nhận.

Reject gửi `expected_version`, `expected_review_version`, `reason` có nội dung
sau trim (tối đa 2000 ký tự). Draft và job `ready` giữ nguyên, `review.status`
chuyển `rejected`; không tạo Post, không gọi provider. Bài đã từ chối không thể
edit/approve/Apply hoặc dùng provenance qua PostForm; có thể regenerate thành
run mới. Duyệt/từ chối lại, candidate chưa ready/hết hạn hoặc version cũ trả 409.
API Apply cũ dùng cùng service duyệt, ghi audit và chặn rejected/duyệt trùng,
vẫn giữ contract cập nhật Post có sẵn và `expected_version` tùy chọn của client cũ.

Lịch sử dùng bảng Spatie `activity_log` hiện có, `log_name=ai-content` và
`properties.candidate_id` là UUID run. Sự kiện `candidate.edited`,
`candidate.approved`, `candidate.rejected` lưu causer/thời điểm; quyết định thêm
lý do/Post ID. GET history nhận `page/per_page` như sessions, trả `data[]` gồm
`id/event/actor/at/reason/post_id/fields` và `meta.pagination`. Không trả raw properties.

UI AI Content có trạng thái Chờ duyệt/Đã duyệt/Từ chối, mở dialog so sánh text
nguồn/kết quả, thông tin duyệt và lịch sử tải trang tiếp. Editor TinyMCE hiện có
tiếp tục dùng để sửa bản chờ duyệt. Xác nhận duyệt chọn field/taxonomy; từ chối
bắt buộc nhập lý do. Mất kết nối/409/5xx khi POST yêu cầu GET kiểm lại trạng thái,
không tự lặp quyết định; bản nhập giữ khi lỗi. Header/footer dialog cố định.

Retention run vẫn mặc định 2 ngày. Cleanup có thể xóa nguồn/candidate đã duyệt
hoặc từ chối; Post/provenance/media đang dùng và Activitylog không bị command
này xóa. GET lịch sử theo candidate cần run còn tồn tại; Spatie có chính sách
cleanup riêng. Kho AI gốc v1 dưới đây giữ dữ liệu độc lập; khôi phục candidate đã
bị cleanup và lịch sử từng lần biên tập chưa được triển khai.

## Kho bài AI gốc v1 — 2026-10-07

Post text mới có `archive_version=1`. Sau khi output qua validate/sanitize,
worker giữ checkpoint hoàn tất trong `ai_imports` rồi chuyển candidate `ready`.
Ảnh tùy chọn được xếp hàng sau đó. Chưa có bản ghi bền vững ở thời điểm tạo bài.
Chỉ approve/Apply thành công mới chuyển checkpoint của bản được chọn vào
`ai_article_archives`, cùng transaction Post/provenance/quyết định/audit.
Snapshot giữ title/content/excerpt/SEO trước edit, nguồn đã dùng, brief/profile,
model và trace prompt/schema/usage. Field origins phân biệt AI mới, kế thừa và
deterministic; bản chỉ sinh title/SEO không tính thành bài content AI mới.

Kho `ai_article_archives` có khóa `(run_id, generation_no)`, hash canonical và
payload bất biến. PATCH candidate, Duyệt/Apply và ảnh đến sau chỉ đổi candidate
hoặc metadata lifecycle, không ghi đè bản AI gốc đã chọn. Ví dụ tạo ba bản rồi
duyệt bản 2: chỉ bản 2 có archive; bản 1/3 và checkpoint hết hạn theo run tạm.
Candidate chưa duyệt/từ chối/lỗi/hủy không có archive. Kho approved không cascade
theo run/Post/user; vẫn đọc được sau khi danh sách Content AI được dọn.

Lỗi kho rollback toàn bộ thao tác duyệt, giữ checkpoint để thử lại; không lấy
candidate đã biên tập làm original. Worker phục hồi checkpoint chỉ hoàn tất
`ready`, không archive hay gọi lại AI. Command `ai-articles:archive` chỉ phục hồi
archive của run đã approved, không gọi AI. Duyệt candidate cũ không có checkpoint
vẫn tạo Post nhưng kho mang nhãn `legacy_unverified`, content null. Kiểm kê dữ liệu
approved cũ bằng command cần `--include-legacy`; không sao chép bản đã sửa thành original.
Kho chưa có lịch tự xóa, endpoint hoặc màn hình xem; JSON nguồn/bài/context vẫn
private. Vòng đời binary ảnh thuộc Media Library hiện có.

Chi tiết contract, kiểm thử và command tại [QA kho bài AI](../qa/AI_ARTICLE_ARCHIVES_2026-10-07.md).
Evaluator, lịch và báo cáo tiếp tục theo [kế hoạch tự đánh giá](../plans/HE_THONG_TU_DANH_GIA_DINH_KY.md).

## Lỗi và giới hạn chất lượng

Validation request trả `422`; quyền `403` hoặc owner `404`; xung đột/trạng thái `409`; quota run `429`; AI tắt `503`. Lỗi bên trong queue đọc tại polling `status=failed`, `error_code`, `error`, diagnostics bounded.

| Mã/nhóm | Ý nghĩa / hành động |
| --- | --- |
| `SOURCE_EMPTY`, `SOURCE_TOO_LARGE` | Kiểm preview/chọn phần nguồn đúng; không cắt thầm hoặc dựng fallback |
| `AI_PROVIDER_INVALID_JSON`, `AI_PROVIDER_SCHEMA`, `AI_PROVIDER_INCOMPLETE`, `AI_PROVIDER_REFUSAL`, `AI_PROVIDER_TOOL_OUTPUT` | Provider chưa trả output hoàn chỉnh đúng contract; kiểm model/config rồi tạo/retry có chủ ý |
| `AI_PROVIDER_TIMEOUT`, `AI_PROVIDER_HTTP_*` | Kiểm upstream/timeout/quota; không lặp request mù sau mất kết nối |
| `AI_ARCHIVE_WRITE_FAILED` | Lỗi ghi checkpoint/kho; worker có checkpoint hợp lệ chỉ phục hồi ready; lỗi lưu khi duyệt rollback Post/quyết định để thử lại |
| `AI_ARCHIVE_CONFLICT`, `AI_ARCHIVE_CHECKPOINT_INVALID`, `AI_ARCHIVE_ORIGINAL_MISSING` | Kho/checkpoint không khớp hoặc thiếu bản gốc; giữ run, kiểm dữ liệu trước retry/cleanup |
| `AI_SOURCE_REFERENCE` | Facts/block IDs/excerpts/fact coverage hoặc ref ảnh không hợp lệ |
| `AI_QUALITY_EXACT_COPY` | Văn xuôi nguồn đủ dài bị sao chép nguyên văn, loại code/quote khỏi phép so sánh |
| `AI_QUALITY_LANGUAGE` | Detector có dấu hiệu ngôn ngữ chủ đạo trái yêu cầu |
| `AI_QUALITY_GROUNDING`, `AI_QUALITY_EDITOR` | Mất code/link/số liệu quan trọng hoặc Editor báo issue blocking |
| `AI_LEGACY_TAXONOMY` | Run cũ vẫn yêu cầu AI tạo taxonomy; tạo run mới với taxonomy thủ công |
| `AI_INPUT_BUDGET` | Tổng nguồn/brief/profile/schema/artifact vượt budget request của bước, mặc định 160000 ký tự; rút gọn có chủ ý, không cắt thầm |

Similarity là warning thống kê từ vựng, không phải điểm văn phong và không ngưỡng 60% chung. Mặc định warning `0.85`, copy gate chỉ khi mỗi prose đủ `160` ký tự; language detector hỗ trợ vi/en với ít nhất `60` từ và có thể `undetermined`. Số mới không có nguồn là warning cần editor đọc; kiểm regex/anchors không chứng minh đầy đủ ngữ nghĩa. Các ngưỡng chưa được hiệu chỉnh bằng đánh giá người dùng thật. UI đọc `quality_checks`, `image_warnings`, `editor_warning_count`, không tự suy bài đã fact-check chỉ vì `ready`.

OpenAI/Gemini/http-json dùng adapter generic task và schema validation server. JSON-object/MIME mode không đồng nghĩa provider hỗ trợ native strict JSON Schema; không tự bật tính năng model chưa khai báo. Ba call tăng chi phí/thời gian, không bảo đảm chất lượng hơn một call. [Kế hoạch đánh giá](../quality/AI_ARTICLE_QUALITY_EVALUATION.md) tách test kỹ thuật khỏi đánh giá bài thực tế.

## Queue và triển khai

- Migrate database và Spatie Settings bằng luồng `php artisan migrate` hiện có; kiểm default text model/profile trước khi tạo run.
- Dùng queue worker thực tế, ưu tiên `database`/`redis` hiện có; không chạy generation trong HTTP. Backend từ chối driver `sync`/`null`, kể cả connection alias trỏ về driver đó. Process manager và scheduler phải hoạt động, không chỉ bật `.env`.
- Tạo toàn bộ nội dung Post luôn dùng `three_step`: Analyze + Plan → Write → Edit. Luồng B `single_step` và switch môi trường `AI_CONTENT_PIPELINE` đã được gỡ ngày 2026-10-05. Snapshot/config cũ không thể bật lại B; run mới hoặc chạy lại ghi mode `three_step`. Prompt/schema/quality/output contracts vẫn được snapshot vào run. Chỉ sửa các trường riêng như title/SEO/excerpt tiếp tục dùng một request theo hợp đồng của trường.
- Job timeout = `max(AI_IMPORT_JOB_TIMEOUT, clamp(request_timeout,5,600) × số_call + 120)`. Ba call 600 giây cần tối đa **1920 giây**; đây là ngân sách, không phải ETA. Worker có PCNTL/runtime đủ timeout; cấu hình process manager `stopwaitsecs` lớn hơn job budget để không cắt bài khi restart.
- `retry_after` của database/redis/beanstalkd tối thiểu **2000 giây**, còn tăng theo `AI_IMPORT_JOB_TIMEOUT + 60`. Luôn giữ request timeout < job budget < lease. Nếu tăng job budget hoặc dùng gateway chậm hơn, rà lại lease/process manager.
- SQS visibility timeout thuộc cấu hình queue trên AWS, không dùng `retry_after` trong file Laravel; đặt visibility lớn hơn budget tương ứng trước khi dùng SQS. Chưa có xác nhận production/VPS/SQS đã cấu hình từ các test local.
- Production dùng cache lock chia sẻ giữa worker, không dùng cache array/file riêng từng container để chống redelivery. Checkpoint giữ output đã validate nhưng không thay lock/ràng buộc state.
- Chạy `php artisan queue:restart` sau deploy/config mới theo process manager; scheduler chạy `ai-import:cleanup` và `ai:cleanup-writing-profile-analyses` theo lịch đã đăng ký. Retention mặc định 2 ngày.

Editor và review workflow đã nối vào AI Content. API cũng có thể kiểm bằng client
admin; đánh giá chất lượng với provider/người đọc thật vẫn theo đợt riêng.
