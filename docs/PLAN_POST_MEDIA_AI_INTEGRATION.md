# Kế hoạch tích hợp API Post, Media và AI Import bài viết

**Phiên bản:** 1.0
**Ngày:** 2026-09-30
**Trạng thái:** Đã triển khai phần lõi; còn các hạng mục production/staging và hợp nhất Media Asset vào Pinia
**Phạm vi:** Admin Post Add/Edit, Media File, Media Asset và AI import bài viết từ URL

## 1. Mục tiêu

Hoàn thiện ba nhóm công việc:

1. Nối API đầy đủ cho tất cả trường và thao tác trong `post/add/index`.
2. Nối API thật cho các màn hình `media/file` và `media/media-asset`.
3. Xây luồng AI nhận một URL, đọc bài viết, viết lại nội dung, tạo dữ liệu SEO, gợi ý category/tag và tạo thumbnail để điền vào form Post.

AI chỉ tạo bản nháp để người dùng review. Việc lưu hoặc publish vẫn do người dùng thực hiện trong form Post.

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
- `media/media-asset/index.vue` đã dùng API thật qua composable riêng; cần hợp nhất về `useMediaAssetStore` để có một source of truth.
- `PostSettingsSidebar.vue` đã tải Category/Tag từ API; các Post option vẫn là UI-only.
- Nút `Fill All with AI` đã hoạt động theo luồng đồng bộ tối thiểu, chưa có polling/regenerate/preview từng phần.
- Category và Tag đã round-trip; các option chưa được gửi lên backend.

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
- [ ] Chốt thumbnail canonical. Đề xuất featured `1200x675` WebP và OG `1200x630`.
- [ ] Đối chiếu các conversion hiện tại (`thumb`/`web`) với kích thước mới và cập nhật nếu cần.
- [x] Chốt ngôn ngữ AI mặc định là tiếng Việt.
- [ ] Chốt provider/model AI và image generation.
- [ ] Chốt giới hạn URL, timeout, số lần retry và quota theo admin.

Kết quả: API contract, danh sách migration, endpoint/permission matrix và danh sách field không được bỏ quên khi submit.

## 6. Phase 1 — Nối đầy đủ Post Add/Edit

**Ước lượng:** 2–3 ngày.

### Backend

- [x] Thêm quan hệ Post–Category và Post–Tag.
- [x] Validate `category_ids`/`tag_ids` là array ID, distinct và tồn tại.
- [x] Đồng bộ taxonomy trong `CreatePostAction` và `UpdatePostAction`.
- [ ] Nếu giữ options, thêm `posts.options` JSON, default và validation boolean.
- [ ] Bổ sung taxonomy/options vào `PostResource` và eager load trong index/show.
- [x] Giữ top-level SEO hiện tại để không phá test/API consumer.
- [ ] Bổ sung filter `search`, `status`, `category_id`, `tag_id` cho `/admin/posts`.
- [x] Kiểm tra permission create/update/delete theo permission Post hiện có.
- [x] Giữ slug do backend quyết định; preview slug không giữ chỗ.

### Frontend

- [x] Bổ sung API taxonomy trong `postService` để lấy categories và tags.
- [x] Thay category hard-code trong `PostSettingsSidebar.vue` bằng API.
- [x] Cho phép chọn nhiều category/tag bằng ID.
- [x] Đồng bộ taxonomy khi mở Post edit; options vẫn reset vì là UI-only.
- [x] Bổ sung taxonomy vào `PostForm` và `postService.toPayload()`.
- [ ] Bổ sung `og_image_id` picker/payload riêng; hiện Featured Image vẫn là fallback.
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
- [ ] Sửa store mutation semantics cho attach/detach/reorder không trả `id`.

### `media/media-asset/index.vue`

Màn hình đã bỏ dữ liệu demo và nối API thật. Hạng mục còn lại là chuyển state từ composable riêng sang Pinia dùng chung:

- [ ] Thay data giả bằng `useMediaAssetStore`.
- [x] Bỏ data giả; hiện dùng `useMediaAssetManager` làm adapter API tạm thời.
- [x] Nối list với `GET /admin/media-assets`.
- [x] Nối upload với `POST /admin/media-assets`.
- [x] Nối detail với `GET /admin/media-assets/{id}`.
- [x] Nối update metadata bằng `PATCH`.
- [x] Nối delete, retry và download.
- [ ] Nối attach, detach và reorder.
- [x] Đồng bộ filter/pagination/sort vào query URL.
- [ ] Hiển thị pending, clean, rejected, error và conversion status.
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

## 8. Phase 3 — Backend AI Import từ URL

**Ước lượng:** 5–7 ngày.

### API đề xuất

```http
POST   /api/admin/posts/ai/import
GET    /api/admin/posts/ai/import/{job}
POST   /api/admin/posts/ai/import/{job}/regenerate
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

### Pipeline xử lý

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

## 9. Phase 4 — Frontend AI trong Post Add

**Ước lượng:** 2–3 ngày.

- [ ] Tạo `PostAiImportDialog.vue`.
- [ ] Tạo `postAiImportService.js` và store/composable polling job.
- [x] Enable nút `Fill All with AI`.
- [ ] Nhập URL, language, rewrite style và thumbnail mode.
- [ ] Hiển thị progress theo từng bước.
- [ ] Preview Content, SEO, Taxonomy và Thumbnail.
- [ ] Apply toàn bộ hoặc từng nhóm field.
- [ ] Regenerate riêng title/content/SEO/thumbnail.
- [ ] Cảnh báo trước khi ghi đè field người dùng đã nhập.
- [ ] Hiển thị source URL và thời điểm tạo.
- [ ] Hủy polling khi component unmount hoặc dialog đóng.
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
- [ ] Permission cho từng Post/Media/AI endpoint.
- [x] Media upload, metadata update, delete, retry, download.
- [x] Asset đang được sử dụng không xóa được.
- [x] SSRF localhost/private IP được kiểm thử; redirect/oversize/non-HTML cần bổ sung coverage.
- [ ] AI provider mock: success, malformed JSON, timeout, quota error.
- [ ] Queue retry, cancel, expiry và cleanup orphan asset.
- [ ] Thumbnail resize/crop/format/size.

### Frontend

- [x] Post form create/edit và taxonomy persistence.
- [x] Post SEO/media round-trip.
- [x] Media list/upload/detail/delete/retry/download.
- [x] Media Asset page không còn data demo.
- [ ] Fake API có handler cho usage nếu vẫn dùng trong test.
- [ ] AI dialog polling/backoff/cancel/retry.
- [ ] Apply selected merge giữ nguyên field không chọn.
- [ ] Overwrite confirmation và lỗi 422/429/timeout.

### Release gate

- [x] Backend tests pass tại lần kiểm chứng gần nhất.
- [x] Frontend Vitest pass tại lần kiểm chứng gần nhất.
- [x] Targeted ESLint pass.
- [x] Laravel Pint pass.
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
| 4 | AI frontend trong Post Add | 2–3 ngày |
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

- [ ] Tất cả field có trên Post Add/Edit có contract rõ ràng và được xử lý end-to-end.
- [x] Category/tag không còn là dữ liệu hard-code.
- [x] Hai màn hình Media dùng API thật cho các thao tác chính.
- [ ] AI import từ URL trả được draft có content, SEO, taxonomy và thumbnail.
- [ ] Thumbnail đạt kích thước/format/dung lượng đã chốt.
- [x] AI không tự publish; người dùng vẫn phải Save Draft/Publish.
- [ ] Permission, validation, audit log và cleanup hoạt động.
- [x] Backend/frontend tests, targeted lint và production build pass tại lần kiểm chứng gần nhất.
- [ ] Staging browser test hoàn tất với queue worker và storage thật.

## 15. Báo cáo triển khai — 2026-09-30

### Đã hoàn thành

- Post đã hỗ trợ quan hệ và đồng bộ Category/Tag ở backend và frontend.
- Post list có filter taxonomy; Post form gửi/nhận taxonomy qua API.
- Media File đã có update metadata; Media Asset demo đã được thay bằng list/grid API thật.
- Media Asset hỗ trợ detail, update, upload, delete, retry, download và pagination/filter.
- AI import đã có migration `ai_imports`, model, request, controller, queue job và polling endpoint.
- URL import có kiểm tra localhost/private IP, trích xuất HTML, loại script/style và sanitize HTML.
- Có provider HTTP JSON tùy chọn qua `AI_IMPORT_ENDPOINT`, `AI_IMPORT_KEY`, `AI_IMPORT_MODEL`.
- Nút Fill All with AI đã điền title/content/excerpt/SEO vào Post form.
- Tạo file inventory trung tâm tại `docs/PROJECT_INVENTORY.md`.

### Kiểm chứng

- Backend: `96 tests`, `539 assertions` pass.
- AI unit tests: `3 tests`, `5 assertions` pass.
- Frontend: `59 tests` pass.
- Production build: pass; còn warning asset `section-title-icon.png` đã tồn tại từ trước.
- Targeted ESLint: 0 errors; một số Vue formatting warnings còn ở component legacy/mới.
- `git diff --check`: không có whitespace error.

### Điều kiện để bật AI rewrite/thumbnail thật

- Cần cấu hình provider server-side trong `.env` bằng các biến `AI_IMPORT_*`.
- Khi chưa có provider credential, hệ thống dùng deterministic extraction/sanitization và trả source thumbnail URL; chưa tự tạo MediaAsset thumbnail từ ảnh nguồn.
- Cần chạy queue worker nếu chuyển controller từ `dispatchSync` sang async production mode.
