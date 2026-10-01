# Kế hoạch hợp nhất Post, Media và AI Content Agent

**Phiên bản:** 2.0 — hợp nhất AI Content Agent
**Ngày:** 2026-09-30
**Trạng thái:** Đã triển khai end-to-end Post/Media, AI Agent và nền tảng Phase 16
provider/catalog/settings/image bằng HTTP server-side. Còn smoke test key thật,
Laravel AI SDK adapter, file input, Resource/Sound adapter và browser/staging.
**Phạm vi:** Admin Post Add/Edit, Media File, Media Asset và AI Content Agent cho Post, Resource, Sound và các model tương lai

## 1. Mục tiêu

Hoàn thiện ba nhóm công việc:

1. Nối API đầy đủ cho tất cả trường và thao tác trong `post/add/index`.
2. Nối API thật cho các màn hình `media/file` và `media/media-asset`.
3. Xây AI Content Agent dùng chung: nhận URL/text/file, chọn prompt phù hợp, gọi nhiều provider/model, tạo candidate và chuyển kết quả thành dữ liệu phù hợp cho từng model.

AI chỉ tạo candidate/bản nháp để người dùng review. Việc apply, tạo slug, lưu hoặc publish vẫn do người dùng và domain action thực hiện.

## 2. Hiện trạng đã kiểm tra

### Backend

- Đã có CRUD Post tại `/api/admin/posts`.
- Đã có API Media Asset cho list, upload, detail, update, delete, retry, download, attach, detach và reorder usage.
- Đã có API CRUD Category, Tag và Technology.
- Post đã có slug, SEO metadata và MediaAsset usage.
- Đã có queue/job cho scan và conversion media.

### Frontend

- `post/add/index.vue` đã gọi Post store cho create/update và load Post khi edit.
- `resources/js/services/post.js` đã xử lý phần lớn payload Post, SEO và media.
- `media/file/index.vue` đã dùng MediaAsset store/API thật.
- `media/media-asset/index.vue` đã dùng trực tiếp `useMediaAssetStore` làm source of truth.
- `PostSettingsSidebar.vue` đã tải Category/Tag từ API; các Post option vẫn là UI-only.
- `CreateWithAiDialog` vẫn giữ hierarchy giao diện cũ (`#view-moi`) nhưng đã có capability,
  prompt/provider/model, polling, candidate, regenerate và apply chọn lọc.
- Category và Tag đã round-trip; các option chưa được gửi lên backend vì vẫn là UI-only.

### Tài liệu liên quan

- [PLAN_ADDPOST.md](./PLAN_ADDPOST.md)
- [PLAN_MEDIA_LIBRARY.md](./PLAN_MEDIA_LIBRARY.md)
- [PLAN_SEO_METADATA.md](./PLAN_SEO_METADATA.md)

## 3. Nguyên tắc triển khai

- Laravel là source of truth cho validation, permission, slug, SEO và media usage.
- Frontend chỉ gọi API thông qua service/store; page không chứa HTTP trực tiếp.
- Không gửi SEO score từ client; score chỉ là dữ liệu hướng dẫn biên tập.
- Mọi mutation Post phải đồng bộ trong transaction gồm Post, SEO, taxonomy và media usage.
- AI xử lý bất đồng bộ qua queue, có retry, timeout và trạng thái rõ ràng.
- Nội dung từ URL được xem là dữ liệu không tin cậy; phải sanitize trước khi đưa vào editor hoặc prompt.
- Không tự động publish nội dung AI.
- Giữ JavaScript + Vue Composition API theo convention hiện tại.

### 3.1. Kiến trúc AI Agent hợp nhất

Không tạo một luồng AI riêng cho từng Post/Resource/Sound. AI Agent được chia thành
bốn lớp:

```text
Nút tạo bằng AI
  → AI Agent session
  → Source adapter (URL/text/file/record)
  → Prompt + schema registry
  → Provider adapter (OpenAI/Gemini/...)
  → Canonical AI response
  → Target adapter (Post/Resource/Sound)
  → Candidate/version
  → Preview, compare và Apply
```

- Laravel AI SDK là lớp provider/agent execution cho structured output, tools và
  nhiều model; không thay thế workflow nghiệp vụ của project.
- `Target Registry` khai báo model nào được phép dùng AI, operation, input và output.
- `Provider Registry` khai báo provider, model, logo và capability.
- `Prompt Registry` lưu prompt dùng chung theo key/version/schema; không viết prompt
  trực tiếp trong Controller, Job hoặc Vue component.
- Agent được phép đề xuất prompt trong allowlist. Rule lọc trước; AI prompt selector
  chỉ chạy khi còn nhiều lựa chọn phù hợp. Backend luôn kiểm tra lại `prompt_key`.
- Provider phải trả Structured Output theo schema. Response vẫn phải được validate,
  sanitize và kiểm tra nghiệp vụ trước khi tạo candidate.
- Candidate không phải Post/Resource thật và không có slug. Chỉ Apply mới cập nhật
  model thật và chạy SlugService.
- Một session có thể có nhiều run từ GPT/Gemini; người dùng có thể so sánh hoặc
  merge từng field.
- Provenance được lưu theo từng field (`provider`, `model`, `prompt_key`, version,
  applied_at) để hiển thị logo AI chính xác.

### 3.2. Registry và contract dùng chung

Registry tối thiểu cần hỗ trợ:

```text
Target: post, resource, sound
Operation: create, rewrite, translate, summarize, seo, metadata
Input: url, text, file, existing_record
Output: title, content, seo, taxonomy, media, audio, metadata
Provider: openai, gemini, deterministic
Prompt: key, version, template, schema, allowed targets/operations
```

API capability phải trả danh sách field/operation được phép để frontend render động;
không nhân bản dialog hoặc hard-code danh sách output theo Post.

## 4. Contract Post thống nhất

Payload create/update đề xuất:

```json
{
  "title": "Tiêu đề bài viết",
  "content": "<h2>...</h2><p>...</p>",
  "excerpt": "Mô tả ngắn",
  "status": "draft",
  "focus_keyword": "từ khóa chính",
  "seo_title": "SEO title",
  "seo_description": "SEO description",
  "canonical_url": "https://example.com/article",
  "robots_index": true,
  "robots_follow": true,
  "og_title": "Social title",
  "og_description": "Social description",
  "og_image_id": 10,
  "category_ids": [1, 2],
  "tag_ids": [3, 4],
  "media": {
    "thumbnail_id": 10,
    "content_image_ids": [11, 12]
  }
}
```

SEO hiện dùng field phẳng trong request. Giữ contract này để không phá API đang có; chỉ bổ sung `category_ids`, `tag_ids` và `options` nếu nhóm option được duyệt để lưu DB.

Các field UI hiện chưa có persistence cần được chốt trước khi code:

- `comments`
- `sharing`
- `pin`
- `sponsored`
- `notification`

Nếu chưa có yêu cầu lưu các field này, giữ chúng là UI-only và không hiển thị như đã lưu thành công.

## 5. Phase 0 — Chốt contract và chuẩn dữ liệu

**Ước lượng:** 0.5–1 ngày.

- [x] Chốt payload Post và response PostResource.
- [x] Chốt quan hệ Post–Category và Post–Tag dựa trên pivot `categorizables`/`taggables` hiện có.
- [x] Chốt các option hiện tại là UI-only, chưa persistence.
- [x] Chốt thumbnail canonical: featured `1200x675` WebP và OG `1200x630` WebP.
- [x] Đối chiếu các conversion hiện tại (`thumb`/`web`): giữ alias legacy để không
  phá asset/UI cũ và bổ sung `featured`/`og` crop đúng kích thước trong config.
- [x] Chốt ngôn ngữ AI mặc định là tiếng Việt.
- [x] Chốt provider/model AI HTTP (OpenAI/Gemini/deterministic) và capability image nguồn;
  image generation vẫn là adapter tùy chọn.
- [x] Chốt giới hạn URL, timeout, số lần retry và quota theo admin; giá trị mặc định
  được đưa vào `config/ai-import.php` và `.env.example`.
- [x] Chốt `Target Registry`, `Provider Registry`, `Prompt Registry` và `Schema Registry`.
- [x] Chốt Canonical AI Response, candidate/version và provenance theo từng field.
- [ ] Kiểm tra PHP 8.3 và Laravel AI SDK; ghim version trong `composer.lock` nếu tương thích.
- [x] Không dùng conversation storage của SDK thay cho candidate/version domain của project.

Kết quả: API contract, danh sách migration, endpoint/permission matrix và danh sách field không được bỏ quên khi submit.

## 6. Phase 1 — Nối đầy đủ Post Add/Edit

**Ước lượng:** 2–3 ngày.

### Backend

- [x] Thêm quan hệ Post–Category và Post–Tag.
- [x] Validate `category_ids`/`tag_ids` là array ID, distinct và tồn tại.
- [x] Đồng bộ taxonomy trong `CreatePostAction` và `UpdatePostAction`.
- [ ] Nếu giữ options, thêm `posts.options` JSON, default và validation boolean.
- [x] Bổ sung taxonomy vào `PostResource` và eager load trong index/show; options vẫn là UI-only.
- [x] Giữ top-level SEO hiện tại để không phá test/API consumer.
- [x] Bổ sung filter `search`, `category_id`, `tag_id` và pagination cho `/admin/posts`.
- [x] Bổ sung filter `status` ở API và UI danh sách Post.
- [x] Kiểm tra permission create/update/delete theo permission Post hiện có.
- [x] Giữ slug do backend quyết định; preview slug không giữ chỗ.

### Frontend

- [x] Bổ sung API taxonomy trong `postService` để lấy categories và tags.
- [x] Thay category hard-code trong `PostSettingsSidebar.vue` bằng API.
- [x] Cho phép chọn nhiều category/tag bằng ID.
- [x] Đồng bộ taxonomy khi mở Post edit; options vẫn reset vì là UI-only.
- [x] Bổ sung taxonomy vào `PostForm` và `postService.toPayload()`.
- [x] Bổ sung `og_image_id` picker/payload riêng; Featured Image chỉ là fallback khi SEO chưa có OG image.
- [x] Hiển thị loading/error riêng cho taxonomy.
- [x] Chặn double submit cho Save Draft/Publish; lỗi API hiện hiển thị ở mức form.
- [x] Dùng response backend làm source of truth sau create/update.

### Tiêu chí nghiệm thu

- Tạo và edit Post round-trip đúng title, content, excerpt, status, SEO, category, tag, options và media.
- Reload vẫn giữ đúng dữ liệu.
- Đổi category/tag sync và detach đúng.
- Gallery giữ đúng thứ tự; thumbnail/content images không mất khi sửa SEO/taxonomy.
- Không gửi score hoặc field preview không thuộc API.
- Publish chỉ xảy ra khi người dùng chủ động bấm Publish.

## 7. Phase 2 — Nối API Media File và Media Asset

**Ước lượng:** 2–3 ngày.

### `media/file/index.vue`

- [x] Audit list server-side, search, kind, visibility, scan status.
- [x] Audit pagination, page size, sort và direction.
- [x] Hoàn thiện upload multipart có progress.
- [x] Hoàn thiện detail, update title/alt/visibility, delete, retry và download.
- [x] Hiển thị usage và lỗi permission.
- [x] Reload/cập nhật list sau mutation.
- [ ] Bổ sung owner/conversion filter nếu UI cần dùng.
- [x] Sửa store mutation semantics cho attach/detach/reorder không giả định response có `id`;
  detail/list được refresh sau mutation.

### `media/media-asset/index.vue`

Màn hình đã bỏ dữ liệu demo và nối API thật. Hạng mục còn lại là chuyển state từ composable riêng sang Pinia dùng chung:

- [x] Hợp nhất page về `useMediaAssetStore` để dùng chung một source of truth.
- [x] Bỏ data giả; list/detail/mutation chính đã gọi Media API thật.
- [x] Nối list với `GET /admin/media-assets`.
- [x] Nối upload với `POST /admin/media-assets`.
- [x] Nối detail với `GET /admin/media-assets/{id}`.
- [x] Nối update metadata bằng `PATCH`.
- [x] Nối delete, retry và download.
- [x] Nối attach, detach và reorder ở API/service/store và picker; Post Save vẫn là boundary
  atomic cho usage. Trang library độc lập chưa có linkable context để tự attach.
- [x] Đồng bộ filter/pagination/sort vào query URL.
- [x] Hiển thị pending/clean/rejected/error và conversion status trong filter, card và detail.
- [x] Bỏ snackbar “demo” sau khi thao tác thật.

### Contract Media

| Thao tác | Endpoint | Ghi chú |
|---|---|---|
| List | `GET /admin/media-assets` | kind, field, visibility, owner, search, scan/conversion status, sort, pagination |
| Upload | `POST /admin/media-assets` | multipart: file, kind, title, alt_text, visibility |
| Detail | `GET /admin/media-assets/{id}` | trả file, preview, usage |
| Update | `PATCH /admin/media-assets/{id}` | title, alt_text, visibility |
| Delete | `DELETE /admin/media-assets/{id}` | soft delete, chặn nếu còn usage |
| Retry | `POST /admin/media-assets/{id}/retry` | scan hoặc conversion |
| Download | `GET /admin/media-assets/{id}/download` | public URL, temporary URL hoặc stream private |
| Attach | `POST /admin/media-assets/{id}/usages` | field, linkable_type, linkable_id, sort_order |
| Detach | `DELETE /admin/media-assets/{id}/usages/{usage}` | kiểm tra usage thuộc asset |
| Reorder | `POST /admin/media-assets/usages/reorder` | field, linkable, usage_ids |

Post save vẫn là nơi sync thumbnail/gallery atomically qua `MediaAssetUsageService`; picker chỉ cập nhật form selection.

### Folder và Trash

Backend hiện chưa có domain folder/trash đầy đủ. Chốt một trong hai hướng:

1. Giai đoạn đầu bỏ folder/trash thật, chỉ dùng search/filter và soft-delete hiện có.
2. Bổ sung folder, `withTrashed`, restore endpoint và permission trước khi bật UI.

Không giữ UI giả khiến người dùng hiểu dữ liệu đã được lưu.

### Tiêu chí nghiệm thu

- File và Media Asset dùng chung API client/store.
- Upload file thật và thấy asset trong list.
- Asset private không lộ storage path.
- Asset còn usage không xóa được.
- Reorder gallery vẫn đúng khi mở lại Post.
- Retry chỉ chạy khi asset ở trạng thái phù hợp.
- Permission và lỗi validation hiển thị rõ.
- Không còn thao tác chính nào dùng fake API trong production.

## 8. Phase 3 — AI Agent foundation và Post URL adapter

**Ước lượng:** 5–7 ngày.

**Trạng thái triển khai:** Đã chốt và triển khai backend nền tảng của Post adapter (queue lifecycle,
SSRF-safe fetch, DOM sanitize, structured provider validation, taxonomy mapping,
thumbnail MediaAsset, cancel/regenerate/cleanup API). Image generation vẫn là
adapter tùy chọn; khi chưa cấu hình provider, hệ thống chỉ dùng ảnh nguồn hợp lệ.

Phase này là nền tảng AI Agent, không phải một pipeline riêng chỉ dành cho Post. Các
class và API hiện có phải được mở rộng qua contract/adapter, không tạo thêm một bộ
`AgentService`/`Job`/`Provider` song song.

### API (đã triển khai)

```http
GET    /api/admin/ai-agent/capabilities/{target}
POST   /api/admin/posts/ai/import
GET    /api/admin/posts/ai/import/{job}
POST   /api/admin/posts/ai/import/{job}/regenerate
POST   /api/admin/posts/ai/import/{job}/retry
GET    /api/admin/posts/ai/import/{job}/candidates
POST   /api/admin/posts/ai/import/{job}/apply
POST   /api/admin/posts/ai/import/{job}/cancel
DELETE /api/admin/posts/ai/import/{job}
```

Request:

```json
{
  "url": "https://example.com/article",
  "language": "vi",
  "rewrite_style": "informative",
  "generate_thumbnail": true,
  "thumbnail_mode": "auto"
}
```

Response tạo job:

```json
{
  "job_id": "uuid",
  "status": "queued"
}
```

Trạng thái đề xuất: `queued`, `fetching`, `extracting`, `rewriting`, `seo`, `thumbnail`, `ready`, `failed`, `cancelled`, `expired`.

### Pipeline xử lý (đã triển khai)

1. Validate và normalize URL.
2. Fetch HTML với timeout, giới hạn redirect và giới hạn response size.
3. Trích xuất title, description, canonical, article body, heading, author/date và ảnh ứng viên.
4. Sanitize HTML, loại script/style/nav/ads/tracking.
5. Gọi AI bằng structured output/schema cố định.
6. Validate lại độ dài, heading, link, keyword và HTML.
7. Map category/tag vào taxonomy hiện có.
8. Tạo hoặc chuẩn hóa thumbnail.
9. Lưu kết quả job và trả draft cho frontend.

### Structured output AI

AI phải trả các field:

- `title`
- `content_html`
- `excerpt`
- `focus_keyword`
- `seo_title`
- `seo_description`
- `canonical_url`
- `robots_index`
- `robots_follow`
- `og_title`
- `og_description`
- `suggested_category_ids`
- `suggested_tag_ids`
- `thumbnail_prompt`
- `thumbnail_alt_text`

Prompt yêu cầu viết lại bằng tiếng Việt, giữ dữ kiện chính, không sao chép nguyên văn, không bịa thêm dữ kiện, tạo H2/H3 và bám bộ SEO rule hiện có. Nội dung lấy từ URL được truyền như dữ liệu, không được coi là instruction.

### Bảng `ai_imports`

Đề xuất các cột:

- `id` hoặc UUID public id.
- `created_by`, `source_url`, `normalized_url`, `source_hash`.
- `status`, `current_step`, `progress`.
- `input_json`, `result_json`, `source_meta_json`.
- `provider`, `model`, `prompt_version`.
- `error_code`, `error_message`.
- `started_at`, `completed_at`, `expires_at`, timestamps.
- soft delete nếu cần audit.

Đặt idempotency theo user + source hash + prompt version trong một khoảng thời gian để chống submit trùng.

### Bảo mật và độ tin cậy

- Chỉ cho phép `http`/`https`.
- Chặn localhost, loopback, RFC1918/private IP, link-local và cloud metadata endpoint.
- Re-validate DNS/IP ở mỗi redirect, tối đa 3 redirect.
- Timeout kết nối và tổng request; giới hạn khoảng 5 MB HTML.
- Không truyền API key AI xuống browser.
- Rate limit theo admin, quota số job/giờ và giới hạn concurrency.
- Queue retry tối đa 3 lần, backoff và timeout riêng.
- Log job id, model, prompt version; không log token/API key hoặc query nhạy cảm.
- Sanitize HTML trước khi render/lưu.
- Không auto-create taxonomy mới và không auto-publish.

### Thumbnail

- Ưu tiên ảnh đại diện hợp lệ từ URL nguồn.
- Kiểm tra MIME, kích thước, dung lượng và file signature.
- Strip EXIF và xử lý ảnh qua Media Library.
- Crop/resize theo chuẩn đã chốt; cập nhật conversion nếu `thumb`/`web` hiện tại không phù hợp.
- Chuyển WebP/JPEG và giới hạn dung lượng.
- Nếu không có ảnh hợp lệ, gọi image generation theo `thumbnail_mode`.
- Tạo MediaAsset nhưng chưa attach usage cho tới khi Apply/Save.
- Có cleanup asset tạm khi cancel, fail hoặc expire.

### 8.1. Hợp nhất provider và Laravel AI SDK

- [x] Tạo `AiProviderContract` nội bộ làm boundary ổn định của project.
- [ ] Tạo `LaravelAiSdkProvider` để dùng Laravel AI SDK cho OpenAI/Gemini và
  Structured Output khi PHP 8.3/Laravel 12 tương thích.
- [x] Giữ `StructuredAiProvider` hiện tại như compatibility/fallback trong lúc
  chuyển đổi; không gọi SDK trực tiếp từ Controller hoặc Vue.
- [ ] Chuẩn hóa token usage/cost qua provider contract và telemetry; nội dung này được
  hoãn, chưa có bảng thống kê trong Phase 16.
- [x] Dùng deterministic/mock HTTP provider trong test; không gọi mạng từ test mặc định.

### 8.2. Prompt, schema và target registry

- [x] Di chuyển prompt Post hiện tại vào Prompt Registry có `prompt_key` và version.
- [x] Tạo schema version cho Post content/SEO/taxonomy.
- [x] Tạo Target Registry cho Post trước; capability Resource/Sound đã khai báo nhưng
  chưa bật adapter.
- [x] Tạo prompt selector: ưu tiên prompt user chọn, sau đó rule filter và
  deterministic fallback; AI selector nâng cao chỉ còn cần khi nhiều prompt cùng điểm.
- [x] Lưu `prompt_key`, `prompt_version`, `schema_version` trong mỗi run.

### 8.3. Candidate, regenerate và provenance

- [x] Mở rộng `ai_imports` hiện tại thành run/session compatibility hoặc tạo lớp
  candidate dùng chung mà không tạo pipeline xử lý thứ hai.
- [x] Lưu nhiều run/provider trong cùng session; candidate chưa có slug và chưa
  phải Post/Resource thật (provider thật sẽ dùng chung contract này khi bật).
- [x] Regenerate phân biệt với retry kỹ thuật, tạo run/candidate mới và hỗ trợ đổi
  prompt hoặc instruction bổ sung.
- [x] Cho phép regenerate đổi provider/model theo allowlist khi adapter OpenAI/Gemini
  đã được bật; request được kiểm tra lại ở backend.
- [x] Preview/compare và Apply toàn bộ hoặc từng field; field không chọn phải giữ nguyên.
- [x] Chỉ chạy SlugService khi Apply vào Post.
- [x] Lưu provenance theo từng field: provider, model, prompt, run, applied_by/time.
- [x] Hiển thị provider/model/prompt/source và logo theo capability backend; không nhận
  logo hoặc identity từ response AI.

### 8.4. Target adapter mở rộng

- [x] Đưa mapping Post hiện tại vào `PostAiAdapter`.
- [x] Tạo contract `AiTargetAdapter` cho Resource/Sound.
- [ ] Resource adapter xử lý description/documentation/changelog/taxonomy.
- [ ] Sound adapter để phase sau, hỗ trợ metadata/audio/cover theo capability.
- [x] Mọi adapter dùng Action/Service domain hiện có và transaction khi Apply Post.

## 9. Phase 4 — Frontend AI Agent dùng chung

**Ước lượng:** 3–5 ngày (dialog tương thích layout cũ, candidate và comparison).

- [x] Tách `CreateWithAiDialog.vue` thành `AiAgentDialog.vue` dùng chung.
- [x] Tạo `aiAgentService.js` và store/composable polling session/run.
- [x] Enable nút `Fill All with AI`.
- [x] Render input/operation/output theo capability của target, không hard-code Article.
- [x] Nhập URL/text, language, rewrite style và instruction bổ sung trong capability
  hiện tại.
- [ ] Bổ sung input file/record khi target adapter và storage/scan contract sẵn sàng.
- [x] Cho phép chọn prompt thủ công hoặc chấp nhận prompt Agent đề xuất.
- [x] Cho phép chọn provider/model trong allowlist.
- [x] Hiển thị progress theo từng bước.
- [x] Preview candidate và so sánh nhiều run/provider.
- [x] Apply toàn bộ hoặc từng field/nhóm field.
- [x] Regenerate riêng từng field/nhóm field; run mới giữ lại field không được chọn từ parent.
- [x] Cảnh báo trước khi ghi đè field người dùng đã nhập.
- [x] Hiển thị source, prompt, provider/model và provenance/logo AI; lineage được gửi cùng
  Post Save và ghi audit server-side.
- [x] Hủy polling khi component unmount hoặc dialog đóng.
- [x] Dùng form Post hiện tại để Save Draft/Publish.

### Tiêu chí nghiệm thu

- URL hợp lệ tạo được job và hiển thị trạng thái.
- Job hoàn tất điền được các field vào form nhưng chưa tạo Post ngoài ý muốn.
- Người dùng sửa được mọi field AI trả về.
- Apply từng phần không làm mất dữ liệu phần khác.
- Job lỗi có thông báo và Retry.
- Đóng dialog không làm mất nội dung form hiện có.
- Người dùng vẫn phải bấm Save/Publish.

## 10. Kiểm thử

### Backend

- [x] Post create/update round-trip các field đã có contract.
- [x] Category/tag attach, detach và update.
- [x] Permission/ownership cho Post, Media và AI endpoint; target Resource/Sound vẫn tắt
  nên chưa tách permission riêng.
- [x] Media upload, metadata update, delete, retry, download.
- [x] Asset đang được sử dụng không xóa được.
- [x] SSRF localhost/private IP được kiểm thử; redirect/oversize/non-HTML cần bổ sung coverage.
- [x] AI provider mock: success, malformed JSON, timeout, refusal và quota error.
- [x] Queue retry, cancel, expiry và cleanup orphan asset; lifecycle API/worker và
  `ai-import:cleanup` đã có coverage.
- [x] Thumbnail resize/crop/format/size cho conversion canonical; asset cũ vẫn dùng
  được qua `thumb` fallback.
- [x] Prompt selector chỉ chọn prompt trong allowlist, ưu tiên lựa chọn thủ công,
  tiếp đến rule theo source/context và deterministic fallback; AI selector nâng cao
  chỉ còn cần khi nhiều prompt cùng điểm.
- [x] Candidate không tạo Post/slug trước khi Apply (đã khóa qua adapter/apply API).
- [x] Regenerate tạo run mới, không làm mất candidate cũ.
- [x] Apply từng field giữ nguyên field không chọn và ghi provenance đúng.
- [x] Logo/model hiển thị theo provider thực tế do capability backend ghi nhận;
  candidate không được tự cung cấp branding.

### Frontend

- [x] Post form create/edit và taxonomy persistence.
- [x] Post SEO/media round-trip.
- [x] Media list/upload/detail/delete/retry/download.
- [x] Media Asset page không còn data demo.
- [x] Fake API có handler attach/detach/reorder usage cùng envelope Laravel.
- [x] AI dialog polling/backoff/cancel/retry.
- [x] Apply selected merge giữ nguyên field không chọn.
- [x] Overwrite confirmation và lỗi 422/429/timeout được xử lý ở service/store/dialog;
  component test UI đầy đủ vẫn có thể mở rộng.
- [x] Candidate comparison và chọn provider/model theo capability; prompt recommendation
  tự động nâng cao vẫn còn pending.
- [x] Capability-driven dialog hoạt động cho target ngoài Post bằng fixture Resource.

### Release gate

- [x] Backend tests pass tại lần kiểm chứng gần nhất.
- [x] Frontend Vitest pass tại lần kiểm chứng gần nhất.
- [x] ESLint toàn bộ `resources/js` và `tests/frontend` pass.
- [ ] Laravel Pint toàn repo pass; baseline còn lỗi line ending/style ở nhiều file legacy.
  Các file provider/provenance mới đã qua targeted Pint.
- [x] Production build pass.
- [ ] Browser test với API Laravel thật.
- [ ] Staging có queue worker và storage disk hoạt động.

## 11. Phân công 4–5 AI cùng triển khai

Môi trường hiện giới hạn tối đa 4 agent đồng thời, gồm agent điều phối. Vì vậy lượt hiện tại dùng 1 agent lead + 3 agent phụ; nếu có thêm slot hoặc chạy lượt tiếp theo, tách thành 5 track như dưới đây.

### Agent A — Lead/contract và Post backend

- Khóa contract và file ownership.
- Migration/model/relations Post–Category–Tag/options.
- Request, action, controller, resource và permission.
- Backend Feature tests.

### Agent B — Post frontend

- Post Add/Edit, taxonomy service/store/sidebar.
- Payload/normalize/response handling.
- Form tests và regression SEO/media.

### Agent C — Media

- File page audit.
- Thay Media Asset demo bằng API thật.
- Detail/update/usage/permissions và media tests.

### Agent D — AI backend

- `ai_imports`, API, queue lifecycle.
- URL normalizer, SSRF guard, extractor và sanitizer.
- AI adapter, schema, prompt, SEO/taxonomy mapper.
- Thumbnail integration, cleanup và backend tests.

### Agent E — AI frontend và QA tích hợp

- Dialog, polling, preview, apply/regenerate.
- Merge vào PostForm không mất dữ liệu.
- Integration/E2E, browser test, build và tài liệu.

### Cách phối hợp

1. Lead khóa contract và file ownership trước khi code.
2. Agent B và C có thể làm song song sau Phase 0.
3. Agent D bắt đầu sau khi contract taxonomy/media ổn định.
4. Agent E bắt đầu khi API job có response mẫu ổn định.
5. Mỗi agent làm test cùng thay đổi và báo cáo endpoint/file đã sửa.
6. Không để hai agent sửa cùng một file trong cùng thời điểm.
7. Integration theo thứ tự: contract → Post/Media backend → AI backend → frontend → QA.

## 12. Ước lượng và thứ tự release

| Phase | Nội dung | Ước lượng |
|---|---|---:|
| 0 | Contract, taxonomy, option, thumbnail standard | 0.5–1 ngày |
| 1 | Post Add/Edit và category/tag | 2–3 ngày |
| 2 | Media File và Media Asset | 2–3 ngày |
| 3 | AI backend, queue, extractor, thumbnail | 5–7 ngày |
| 4 | AI Agent frontend dùng chung, candidate và comparison | 3–5 ngày |
| 5 | Regression, browser test, build, staging | 2 ngày |

Tổng dự kiến là 12–16 ngày công cho một người. Với 4 agent làm đúng boundary, thời gian lịch dự kiến khoảng 7–10 ngày; riêng AI pipeline có thể dao động 9–14 ngày công tùy provider và image generation.

Thứ tự release:

1. Post API + taxonomy.
2. Media API thật trên cả hai màn hình.
3. AI import chỉ tạo draft preview.
4. Thumbnail generation.
5. Staging test và bật feature cho nhóm admin giới hạn.

## 13. Rủi ro và phương án xử lý

| Rủi ro | Ảnh hưởng | Xử lý |
|---|---|---|
| Category/tag chưa có quan hệ Post hoàn chỉnh | Không lưu được taxonomy | Chốt migration/quan hệ ở Phase 0 |
| Option UI chưa có cột DB | Dữ liệu mất sau reload | Chốt UI-only hoặc thêm migration trước khi nối |
| Media Asset page còn folder/trash demo | Người dùng tưởng dữ liệu đã lưu | Bỏ UI hoặc xây domain + endpoint thật |
| URL chặn bot hoặc HTML không chuẩn | AI không đọc được bài | Báo lỗi rõ và cho phép nhập thủ công |
| SSRF hoặc file độc hại | Rủi ro bảo mật | Block private IP, validate redirect, scan file |
| AI trả HTML/JSON sai schema | Form lỗi hoặc XSS | Structured output, validate và sanitize |
| Chi phí AI/image cao | Vượt quota | Rate limit, quota/admin, cache source hash |
| Người dùng bỏ dở nhiều job | Rác DB/storage | TTL và cleanup job |
| Provider AI chậm/lỗi | UX chờ lâu | Queue, retry, progress và retry thủ công |

## 14. Definition of Done

- [x] Tất cả field có trên Post Add/Edit có contract rõ ràng và được xử lý end-to-end;
  các option comments/sharing/pin/sponsored/notification được ghi rõ là UI-only.
- [x] Category/tag không còn là dữ liệu hard-code.
- [x] Hai màn hình Media dùng API thật cho các thao tác chính.
- [x] AI Agent từ URL/text trả được candidate theo target capability.
- [ ] File input còn chờ storage/scan contract.
- [x] Post là target adapter đầu tiên; Resource/Sound có contract mở rộng mà không
  tạo pipeline AI riêng.
- [x] Có provider contract, structured schema và adapter OpenAI/Gemini HTTP; Laravel AI SDK
  vẫn pending do môi trường hiện tại là PHP 8.2.
- [x] Prompt Registry versioned và backend kiểm tra prompt trong allowlist; AI selector
  tự động nâng cao vẫn pending.
- [x] Có nhiều candidate, regenerate, comparison và apply từng field.
- [x] Candidate không tạo slug/Post thật trước khi Apply.
- [x] Thumbnail đạt kích thước/format đã chốt ở conversion `featured`/`og`; giới hạn
  dung lượng gốc vẫn do MediaUploadValidator kiểm soát.
- [x] AI không tự publish; người dùng vẫn phải Save Draft/Publish.
- [x] Permission, validation, provenance theo run/field và logo provider đã có; token
  usage/cost được hoãn, audit history UI và cleanup toàn bộ asset orphan vẫn có thể mở rộng.
- [x] Backend/frontend tests và lint JavaScript/Vue pass tại lần kiểm chứng gần nhất;
  production build đã chạy lại sau lượt selector/fake usage cuối.
- [ ] Staging browser test hoàn tất với queue worker và storage thật.

## 15. Báo cáo triển khai — 2026-09-30

### Đã hoàn thành

- Post đã hỗ trợ quan hệ và đồng bộ Category/Tag ở backend và frontend.
- Post list API có filter taxonomy và status; Post form gửi/nhận taxonomy qua API.
- Media File đã có update metadata; Media Asset demo đã được thay bằng list/grid API thật.
- Media Asset hỗ trợ detail, update, upload, delete, retry, download và pagination/filter.
- AI import đã có migration `ai_imports`, model, request, controller, queue job và polling endpoint.
- URL import có kiểm tra localhost/private IP, trích xuất HTML, loại script/style và sanitize HTML.
- Có provider HTTP JSON tùy chọn qua `AI_IMPORT_ENDPOINT`, `AI_IMPORT_KEY`, `AI_IMPORT_MODEL`.
- Nút Fill All with AI đã điền title/content/excerpt/SEO vào Post form.
- Tạo file inventory trung tâm tại `docs/PROJECT_INVENTORY.md`.

### Kiểm chứng (snapshot trước lượt tiếp tục)

- Backend: `112 tests`, `600 assertions` pass.
- Frontend: `64 tests` pass.
- Production build: pass; còn warning asset `section-title-icon.png` đã tồn tại từ trước.
- Targeted ESLint: 0 errors; một số Vue formatting warnings còn ở component legacy/mới.
- `git diff --check`: không có whitespace error.

### Điều kiện để bật AI rewrite/thumbnail thật

- Cần cấu hình provider server-side trong `.env` bằng các biến `AI_IMPORT_*`.
- Khi chưa có provider credential, hệ thống dùng deterministic extraction/sanitization và trả source thumbnail URL; chưa tự tạo MediaAsset thumbnail từ ảnh nguồn.
- Cần chạy queue worker nếu chuyển controller từ `dispatchSync` sang async production mode.

### Quyết định hợp nhất sau khi rà soát AI Agent

- Không tạo file plan AI riêng và không tạo pipeline AI thứ hai.
- `PLAN_POST_MEDIA_AI_INTEGRATION.md` là plan duy nhất; Post URL import là adapter đầu tiên
  của AI Agent dùng chung.
- Laravel AI SDK, sau khi xác nhận PHP 8.3/Laravel 12 tương thích, chỉ làm provider/
  structured-output layer. Session, candidate, target adapter, prompt registry,
  apply, slug và provenance vẫn thuộc domain của project.
- GPT/Gemini có thể tạo nhiều candidate trong một session; chỉ candidate được Apply mới
  cập nhật Post/Resource và tạo slug.
- Prompt selector được giới hạn bởi Target/Prompt Registry; ưu tiên lựa chọn thủ công,
  sau đó rule, rồi mới dùng AI selector khi cần.
- Nhãn logo AI được ghi theo provider/model thực tế và theo từng field đã Apply.

### Cập nhật đối chiếu và triển khai tiếp — 2026-09-30

Đã hoàn thành thêm trong lượt này:

- Sửa lỗi runtime trong `ArticleImportService`: lưu metadata prompt trước khi
  trả `prompt_version`/`schema_version` (deterministic import chạy ổn định).
- Thêm endpoint capability dùng chung:
  `GET /api/admin/ai-agent/capabilities/{target}`. Endpoint lấy dữ liệu trực tiếp
  từ Target/Prompt/Schema/Provider Registry và không trả secret.
- `AiAgentDialog`/`CreateWithAiDialog` có thể lấy capability backend; khi endpoint
  generic chưa có thì service vẫn fallback an toàn về Post import cũ.
- Sửa lỗi fallback frontend làm mất error gốc do tham chiếu biến `error` ngoài scope.
- Sửa parse boolean `AI_IMPORT_ENABLED`, để giá trị `.env=false` thật sự tắt
  provider/import thay vì bị PHP coi chuỗi `false` là `true`.
- Bổ sung test capability và test API fallback; các test AI liên quan hiện đạt
  `17 tests, 55 assertions` backend và `4 tests` frontend riêng cho AI service.
- Kiểm chứng toàn bộ sau lượt sửa: backend `112 tests, 600 assertions`, frontend
  `64 tests`, production build đạt; chỉ còn warning asset legacy
  `section-title-icon.png` đã có từ trước.
- Giữ nguyên hierarchy giao diện `CreateWithAiDialog` (`#view-moi`, stepper,
  hàng URL/ngôn ngữ, preview và footer); chỉ bổ sung field/payload trong các hàng
  hiện có, không thay bằng layout mới.

Trạng thái tại snapshot cũ cần tiếp tục:

- Đã có contract/registry/provider boundary, Post adapter, candidate lineage,
  regenerate/retry/apply/provenance và generic AI dialog.
- Chưa bật Laravel AI SDK vì môi trường hiện tại là PHP 8.2, trong khi SDK bản
  tương thích yêu cầu PHP 8.3; `StructuredAiProvider` vẫn là compatibility layer.
- Chưa hoàn tất Laravel AI SDK, Resource/Sound adapter, thumbnail conversion chuẩn và
  browser/staging test với queue worker/storage thật.
- Capability endpoint hiện dùng quyền `posts.manage`; khi mở Resource/Sound cần
  tách permission theo target trước khi bật target tương ứng.

### Cập nhật tiếp tục — 2026-09-30

Đã hoàn thành thêm trong lượt tiếp tục:

- Gắn provenance vào luồng Post create/update thông thường: PostForm truyền `ai_run_id`
  và `ai_fields`, `AiProvenanceService` kiểm tra owner/status/expiry rồi ghi provider,
  model, prompt, hash giá trị và actor từ dữ liệu server-side.
- Bổ sung generic session routes `/api/admin/ai-agent/sessions/*` và candidate apply;
  payload nested `input.url/text` được chuẩn hóa bởi FormRequest nhưng vẫn giữ các route
  legacy để tương thích client cũ.
- Hỗ trợ nguồn `text` inline qua migration `source_text`, queue pipeline dùng chung
  extractor/sanitizer và không gọi HTTP; source preview frontend đã bỏ dữ liệu demo tĩnh.
- Regenerate theo field/nhóm field: child run ghi `fields`, giữ field không chọn từ parent
  và tránh tạo thumbnail mới khi thumbnail không được yêu cầu.
- Media Asset page đã dùng trực tiếp Pinia store; filter scan/conversion, status card/detail,
  upload/update/delete/retry/download và usage mutation được đồng bộ cùng API.

Kiểm chứng lượt này:

- Backend: `127 tests`, `670 assertions` pass (`php artisan test`).
- Frontend: `75 tests` pass (`npx vitest run`).
- ESLint toàn bộ `resources/js` và `tests/frontend`: 0 lỗi.
- Targeted Pint cho file mới pass; Pint toàn repo vẫn gặp baseline line-ending/style ở file legacy.
- Production build: pass (`npm run build`); vẫn có warning asset legacy
  `section-title-icon.png` không resolve lúc build, không làm build thất bại.
- `git diff --check`: không có whitespace error.

### Kiểm thử URL thực tế sau lượt triển khai

- Đã chạy migration `2026_09_30_140000_add_text_source_to_ai_imports_table` trên
  database development MySQL để schema runtime khớp với code.
- URL `https://laravel-news.com/elastic-bridge` đã đi qua validate URL, fetch,
  sanitize và deterministic provider; candidate đạt `ready` với prompt
  `post.create.from_url`.
- Candidate đã được Apply thành một Post `draft`; provenance được ghi theo bốn
  nhóm field `title`, `excerpt`, `content`, `seo`.
- Queue development đang có các job conversion ảnh cũ; PHP runtime hiện không có
  GD/Imagick nên conversion worker có thể đánh dấu `failed`. Lượt kiểm thử AI này
  tắt thumbnail và chạy riêng `ProcessAiImportJob` để xác nhận pipeline nội dung.

## 16. Kế hoạch tiếp theo — Provider thật, AI Settings và Model Catalog

**Trạng thái:** `Đã triển khai nền tảng HTTP + UI; còn smoke test key thật và adapter Laravel AI SDK`

Phần catalog/provider, settings, resolver, content/image pipeline, provenance,
security boundary và giao diện quản trị đã được triển khai. Các test hiện dùng
HTTP fake và deterministic fallback để không ghi secret hoặc phát sinh chi phí;
staging với key thật và adapter Laravel AI SDK vẫn là bước tích hợp sau khi môi
trường PHP 8.3 sẵn sàng.

### 16.1. Mục tiêu và quyết định triển khai

Mục tiêu của phase này là đưa AI Agent từ registry/config tĩnh sang kết nối
provider thật có thể cấu hình trong Admin, đồng thời vẫn giữ deterministic provider
làm fallback an toàn.

Quyết định triển khai:

- Tách rõ hai loại onboarding: provider chính thức (OpenAI/Gemini/DeepSeek chính
  thức) dùng endpoint/driver đã biết; provider gateway bên thứ ba dùng endpoint
  OpenAI-compatible do admin nhập.
- Với provider chính thức, endpoint và driver là preset an toàn; admin chủ yếu nhập
  API key, bật provider và chọn model mà key thực tế được phép gọi.
- Với gateway bên thứ ba (ví dụ endpoint chứa nhiều model DeepSeek/Qwen/GLM),
  sau khi kiểm tra key hệ thống gọi `GET {base_url}/models` để import model catalog;
  nếu gateway không có API này thì cho phép thêm model thủ công.
- Ưu tiên adapter HTTP trực tiếp trước để có thể chạy cả provider chính thức và
  OpenAI-compatible gateway mà không bị chặn bởi PHP 8.3 hoặc Laravel AI SDK.
- Laravel AI SDK là lớp tích hợp bổ sung sau, không thay thế domain `AiImport`,
  candidate, provenance, target adapter và settings của project.
- Một provider/endpoint có thể có nhiều model. Không hard-code danh sách model trong
  `config/ai-agent.php`; model được đồng bộ và quản lý trong database.
- Text generation và image generation là hai capability độc lập. Model text-only
  không được chọn cho thao tác tạo ảnh.

### 16.2. Domain và database

- [x] Tạo `ai_providers` cho endpoint/connection:
  `name`, `kind` (`official`/`gateway`), `driver`, `base_url`, `api_key` encrypted,
  `discovery_mode` (`fixed`/`models_endpoint`/`manual`), `is_active`, trạng thái
  test, `last_synced_at`, `last_tested_at` và metadata an toàn.
- [x] Tạo `ai_models` thuộc một provider:
  `remote_model_id`, label, capability JSON, metadata, `is_enabled`,
  `is_available`, `last_seen_at` và timestamps.
- [x] Thêm unique constraint `(ai_provider_id, remote_model_id)`.
- [x] Chưa tạo bảng `ai_model_usage` hoặc `ai_model_usage_daily`; thống kê request,
  token và cost được hoãn cho một phase riêng, không thuộc catalog model hiện tại.
- [x] Không xóa model đã từng được đồng bộ nhưng hiện không còn xuất hiện; đánh dấu
  `is_available=false` để không phá provenance, AI run cũ hoặc default đang tham chiếu.
- [x] Tạo bảng `settings` key-value có namespace/group, type, JSON value,
  `updated_by` và unique `(group, key)`.
- [x] Dùng `settings` cho default/fallback và thông số chung, ví dụ:
  `ai.default_text_model_id`, `ai.default_image_model_id`,
  `ai.fallback_text_model_id`, `ai.default_temperature`, `ai.request_timeout`.
- [x] Không lưu API key vào bảng settings chung; provider key phải được encrypt,
  hidden khỏi serialization và không xuất hiện trong log/API response.
- [x] Có default typed trong `AiSettingsService`; deterministic provider vẫn chạy khi
  chưa có provider thật.

### 16.3. Provider contract và registry

- [x] Giữ `AiProviderContract`/`ProviderRegistry` làm boundary text hiện tại và
  chuyển registry sang đọc provider/model active từ database, có fallback config
  cho môi trường chưa migrate.
- [x] Tạo driver `openai-compatible` dùng `base_url` của provider cho các endpoint
  `/chat/completions`; không viết adapter riêng cho từng model DeepSeek/Qwen/GLM
  nếu payload/response tương thích OpenAI.
- [x] Giữ native adapter cho Gemini hoặc provider chính thức có request/response
  khác biệt; không ép mọi provider vào OpenAI-compatible nếu contract không tương thích.
- [x] Định nghĩa preset driver/endpoint cho provider chính thức để không cho phép
  request tùy ý từ giao diện; gateway mới được nhập `base_url` theo policy HTTPS/
  allowlist.
- [x] Tạo `AiImageProviderContract` và registry capability riêng cho image generation;
  không dùng text provider để giả định rằng model có thể tạo ảnh.
- [x] Chuẩn hóa response về canonical output hiện tại và giữ `provider_id`,
  `remote_model_id`, driver, prompt/schema version trong provenance.
- [x] Bổ sung `ModelResolver`/`AiSettingsService` với thứ tự:
  model request override → default theo capability → fallback → lỗi cấu hình rõ ràng.
- [x] Resolve provider/model ngay khi tạo run và lưu snapshot vào `ai_imports.input_json`;
  queue không đọc lại default setting đã thay đổi sau khi job được xếp hàng.

### 16.4. Đồng bộ model và kiểm tra kết nối

- [x] API test connection server-side, có timeout/connection timeout, kiểm tra HTTPS,
  không trả API key về browser.
- [x] Test model không tự chặn theo capability trước khi gọi provider; lỗi model,
  quyền hoặc endpoint được nhận từ upstream và hiển thị an toàn cho quản trị viên.
- [x] Với provider `discovery_mode=models_endpoint`, sync model qua
  `GET {base_url}/models` với Bearer token; chuẩn hóa cả trường hợp `base_url` đã
  có hoặc chưa có hậu tố `/v1`, tránh ghép URL thành `/v1/v1/models`.
- [x] Với provider chính thức, giữ catalog preset tối thiểu
  hoặc gọi endpoint model chính thức khi provider hỗ trợ; việc sync chỉ cập nhật
  model được key nhìn thấy, không biến catalog công khai thành quyền truy cập.
- [x] Chuẩn hóa `data[].id`, `owned_by`, capability metadata nếu endpoint có trả;
  cho phép admin chỉnh capability thủ công khi API chỉ trả model ID.
- [x] Cho phép thêm model thủ công với provider không hỗ trợ `/models`.
- [x] Không dùng catalog public làm nguồn quyền truy cập cuối cùng; `/models` với
  key thực tế là nguồn authoritative cho model mà key đó được phép gọi.
- [x] Nếu `/models` thành công nhưng model thiếu metadata capability, gán capability
  ở trạng thái `unknown` và yêu cầu admin xác nhận trước khi dùng làm model ảnh;
  không suy đoán chỉ từ tên `vision`, `image` hoặc model family.
- [x] Có nút resync thủ công và job sync định kỳ tùy chọn; sync lỗi không được làm
  mất danh sách model đang dùng.

### 16.5. Admin UI — Settings → AI Providers

- [x] Thêm navigation và page quản lý provider/endpoint.
- [x] Form tạo/sửa provider theo hai chế độ:
  provider chính thức (chọn preset, nhập API key) hoặc gateway (nhập driver,
  base URL, API key); có active/inactive, test connection và rotate key.
- [x] Hiển thị discovery mode rõ ràng: tự động đồng bộ `/models` hoặc thêm model
  thủ công khi endpoint không cung cấp catalog.
- [x] Hiển thị provider theo dạng expandable list với số model, thời điểm sync và
  trạng thái kết nối.
- [x] Hiển thị model thuộc provider với search, active/available, remote model ID
  và thao tác test kết nối; capability không còn là cột thao tác trong catalog.
- [x] Thêm hai selector mặc định riêng:
  `Default text model` và `Default image model`; chỉ hiển thị model đúng capability.
- [x] Thêm selector fallback và các thông số chung có type/validation rõ ràng.
- [x] API chỉ trả masked key/metadata public; frontend không tự gửi key trực tiếp
  tới provider.
- [x] Dùng permission riêng `ai_settings.manage` cho provider/settings và activity log
  cho thao tác thêm, sửa, test, sync, rotate hoặc disable; không mở rộng quyền settings chung.

### 16.6. Nối vào luồng Post/AI Agent

- [x] Nếu request không truyền model, AI Agent dùng `ai.default_text_model_id`.
- [x] Dialog Post vẫn cho phép override provider/model cho một run; override không
  thay đổi default toàn hệ thống.
- [x] Nút `Tạo ảnh AI` trong field media dùng `ai.default_image_model_id` khi không
  có model override và chỉ cho chọn model có `image_generation`.
- [x] Tách image generation thành job/candidate asset riêng; content candidate vẫn
  hoàn tất nếu image provider không có hoặc tạo ảnh thất bại.
- [x] Ảnh tạo ra phải qua Media Library, preview và Apply trước khi attach vào Post;
  không tự publish hoặc tự ghi đè field khi chưa có xác nhận.
- [x] Post Save tiếp tục ghi `ai_run_id`, `ai_fields` và provenance provider/model
  đã resolve; không tin identity do frontend tự gửi.

### 16.7. Bảo mật, retry và kiểm thử

- [x] Mã hóa API key ở database, dùng hidden/encrypted cast, không log request header
  hoặc payload chứa secret.
- [x] Validate provider URL (HTTPS, host allowlist/egress policy, chặn localhost và
  private network trong môi trường production) trước khi test hoặc gọi outbound.
- [x] Dùng timeout, retry/backoff có giới hạn cho 429/5xx/connection failure; không
  retry lỗi validation hoặc malformed schema.
- [x] Bổ sung `Http::fake()` cho OpenAI-compatible, Gemini, `/models`, timeout,
  unauthorized, rate-limit và malformed output.
- [x] Feature tests cho provider CRUD, permission, encrypted key, sync model,
  default resolver, model capability và không lộ secret.
- [x] Frontend tests cho provider/model selector, default fallback, unavailable model,
  test/sync loading-error-success và image model filtering.
- [ ] Staging test bằng key thật cho ít nhất một text provider và một image provider;
  kiểm tra queue worker, provenance, quota, timeout và cleanup asset.

### 16.8. Definition of Done

- [ ] Admin thêm được provider chính thức bằng API key và test connection thành công (chờ key/staging).
- [x] Admin thêm được một OpenAI-compatible gateway, test connection và import được
  nhiều model từ `/models` bằng HTTP fake; gateway không có `/models` vẫn dùng được bằng model thủ công.
- [x] Provider có nhiều model được sync, hiển thị và enable/disable riêng từng model.
- [x] Model mặc định text/image được lưu trong settings và được dùng khi request không
  chỉ định model.
- [x] Request chỉ được chọn model đúng capability; model không khả dụng trả lỗi rõ ràng.
- [ ] AI Agent chạy thật qua key server-side và vẫn giữ structured output/provenance (adapter đã sẵn sàng, chưa chạy key thật).
- [x] Image generation là optional, không làm content run thất bại khi image provider lỗi.
- [x] Không có API key thật trong frontend response, log, test fixture hoặc Git;
  test fixture chỉ dùng key giả và response chỉ trả `has_api_key`.
- [x] Deterministic fallback và route legacy vẫn hoạt động khi chưa cấu hình provider thật.
- [~] Backend/frontend tests, lint và build đạt; staging smoke test còn chờ key thật.

### 16.9. Thứ tự triển khai đề xuất

1. Chốt preset driver chính thức và contract gateway; thêm migration/model
   `ai_providers`, `ai_models`, `settings` cùng encrypted secret boundary.
2. `AiSettingsService`, `ModelResolver`, database-backed `ProviderRegistry` và
   snapshot provider/model lúc tạo run.
3. Test connection provider chính thức; sau đó làm OpenAI-compatible driver,
   normalize base URL và sync `/models`/manual model.
4. API CRUD/sync/test và permission; chưa nối UI trước khi API contract ổn định.
5. Admin Settings UI, navigation, provider onboarding và selector default text/image.
6. Nối default/override vào Post AI Agent; kiểm tra capability trước khi dispatch.
7. Image provider contract/job, candidate asset và nút tạo ảnh trong Post Media field.
8. Security, tests, quota/retry và staging smoke test với ít nhất một provider chính
   thức và một gateway nhiều model.
9. Sau khi PHP 8.3 sẵn sàng, đánh giá `LaravelAiSdkProvider` như adapter bổ sung,
   không thay đổi domain/session/candidate/provenance đã ổn định.
