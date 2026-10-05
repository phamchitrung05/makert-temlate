# Project Inventory — Makert Template

**Cập nhật:** 2026-09-30
**Mục đích:** Tài liệu tóm tắt để AI/developer mới nắm cấu trúc, domain, API, frontend, test và trạng thái project nhanh trước khi sửa code.

### Thống kê nhanh tại thời điểm cập nhật

| Khu vực | Số file |
|---|---:|
| `app/` | 136 |
| `resources/js/` | 965 |
| `routes/` | 3 |
| `database/migrations/` | 31 |
| `tests/` | 44 |
| `docs/` | 10 |
| Vue SFC (`.vue`) | 746 |
| PHP (`.php`) | 248 |
| JavaScript (`.js`) | 227 |

Các số liệu trên được đếm bằng `rg --files` và cần cập nhật khi project thay đổi lớn.

## 1. Thông tin tổng quát

- Repository: `makert-temlate`
- Backend: Laravel 12, PHP 8.2+
- Frontend: Vue 3.5, Vite 7, Vuetify 3, Pinia
- Authentication: Laravel Sanctum Bearer token cho admin; customer guard riêng.
- Permission: `spatie/laravel-permission`.
- Media: `spatie/laravel-medialibrary` + MediaAsset domain wrapper.
- Audit: `spatie/laravel-activitylog`.
- API envelope: `App\\Http\\Responses\\BaseResponse`.
- Frontend API boundary: `resources/js/utils/api.js` (`$api`).
- Frontend source language: JavaScript + Vue SFC; không thêm TypeScript cho module hiện tại nếu không có lý do rõ ràng.
- Database/queue/storage: đọc `.env`, `config/database.php`, `config/queue.php`, `config/filesystems.php` trước khi chạy integration.

## 2. Quy tắc làm việc bắt buộc

- Không sửa `vendor/`, `node_modules/`, cache sinh tự động hoặc file ngoài scope.
- Giữ thay đổi local đang có; không dùng `git reset --hard` hoặc `git checkout --` để dọn workspace.
- Backend source mới phải có PHPDoc tiếng Việt theo comment convention của repository.
- Mỗi class/method mới cần nêu rõ input, output, side effect và exception.
- Vue dùng Composition API + `<script setup>`, state tối thiểu, `computed` cho derived state, watcher có cleanup cho request bất đồng bộ.
- Page/route component giữ mỏng; logic HTTP nằm trong service/store/composable.
- Props đi xuống, events đi lên; không mutate props trong child.
- Không render HTML chưa sanitize bằng `v-html`.
- Mọi endpoint admin cần auth, ability, account active và permission phù hợp.
- Response mới phải giữ envelope `success/data/errors/meta` tương thích consumer hiện tại.

## 3. Cấu trúc thư mục chính

```text
app/
  Actions/                 Transactional use cases (Posts, Resources, Media)
  Enums/                   Status, visibility, media fields/kinds
  Http/Controllers/        HTTP boundary
  Http/Requests/           Validation boundary
  Http/Resources/          JSON serialization
  Jobs/                    Queue jobs (media processing/scanning)
  Models/                  Eloquent models and concerns
  Policies/                Authorization policy
  Repositories/            Resource/taxonomy repositories
  Services/                SEO, slug, media usage and security services
  Validators/              Domain validation
bootstrap/                 Laravel bootstrap/providers
config/                    Application, auth, media, permissions, slug config
database/migrations/       Schema history
database/factories/        Test factories
database/seeders/          Roles, permissions, catalog, media seed data
resources/js/
  pages/                   Route-level Vue pages
  views/                   Feature components
  services/                API clients
  stores/                  Pinia state/actions
  composables/             Reusable reactive logic
  plugins/fake-api/        MSW fake API handlers
routes/api.php             Admin/customer/auth API routes
tests/Feature/             Laravel HTTP/domain tests
tests/Unit/                Laravel unit/contract tests
tests/frontend/            Vitest/Vue Test Utils tests
docs/                      Plans, handoff notes and this inventory
```

## 4. Domain map

### Post

- Model: `app/Models/Post.php`
- Controller: `app/Http/Controllers/Admin/PostController.php`
- Actions: `app/Actions/Posts/CreatePostAction.php`, `UpdatePostAction.php`
- Requests: `app/Http/Requests/Admin/PostCreateRequest.php`, `PostUpdateRequest.php`
- Resource: `app/Http/Resources/PostResource.php`
- Frontend page: `resources/js/pages/apps/blog/post/add/index.vue`
- Frontend form: `resources/js/views/apps/blog/post/PostForm.vue`
- Frontend services/store: `resources/js/services/post.js`, `resources/js/stores/post.js`
- SEO: `app/Services/SeoMetadataService.php`, `app/Support/SeoRules.php`, `resources/js/composables/seoMetadata.js`, `useSeoMetadata.js`
- Slug: `app/Services/SlugService.php`, `resources/js/composables/useSlug.js`, `resources/js/stores/slug.js`
- Media fields: `post.thumbnail`, `post.content_images`
- Status enum: `draft`, `published`, `archived`

### Taxonomy

- Models: `Category`, `Tag`, `Technology`
- Controllers: `CategoryController`, `TagController`, `TechnologyController`
- Resource: `TaxonomyItem`
- Validators/requests: corresponding files under `app/Validators` and `app/Http/Requests/Admin`
- Pivot schemas: `categorizables`, `taggables`
- Existing taxonomy reads are permission-scoped; check permission before using them in Post editor.

### Media

- Models: `MediaAsset`, `MediaAssetUsage`
- Controller: `app/Http/Controllers/Admin/MediaAssetController.php`
- Actions: upload and metadata update under `app/Actions/Media`
- Service: `app/Services/MediaAssetUsageService.php`
- Security: `MediaUploadValidator`, `ArchiveSecurityScanner`
- Enums: kind, visibility, scan status, conversion status, field
- Frontend service/store: `resources/js/services/mediaAsset.js`, `resources/js/stores/mediaAsset.js`
- File page: `resources/js/pages/apps/media/file/index.vue`
- Asset page: `resources/js/pages/apps/media/media-asset/index.vue`
- Media Asset giữ bố cục ba cột gốc; `useMediaAssetStore` quản lý list/detail/mutation và giữ panel ổn định khi đổi item. Search/filter/sort/pagination chạy server-side. Download hỗ trợ JSON URL và stream private. Các nhóm bên trái lọc kind, không phải thư mục DB; chưa có Trash/restore API.
- File page, picker và Asset page đã dùng chung `useMediaAssetStore`; không lưu File/Blob nhị phân trong Pinia. Browser/staging với storage thật còn pending.
- Picker: `resources/js/views/apps/media/field/MediaLibraryDialog.vue`, `MediaAssetField.vue`

### AI import

- Task 2 backend: Post `content` dùng Analyze + Plan → Write → Edit qua generic task DTO/adapter; source/profile/config/parent baseline snapshot, `ai_import_steps` checkpoints và quality gates. `ai_writing_profiles`/analyses có CRUD/default/version/evidence và queue phân tích bài mẫu; taxonomy thủ công; inline media validate ID/URL/quyền/usage/retention. UI mới và đánh giá chất lượng provider/người đọc còn mở. Contracts: `docs/AI_ARTICLE_PIPELINE_API.md`, `docs/AI_WRITING_PROFILES_API.md`, `docs/TASK2_INLINE_MEDIA_API.md`.
- Implemented boundary: `ai_imports` persistence, generic session/admin job API, queue job, extractor/sanitizer, OpenAI/Gemini HTTP provider adapters, registry/target adapter and Post form AI dialog.
- Main files: `app/Models/AiImport.php`, `app/Models/AiProvenance.php`, `app/Jobs/ProcessAiImportJob.php`, `app/Services/Ai/Content/ArticleImportService.php`, `app/Services/Ai/Providers/Adapters/StructuredAiProvider.php`, `app/Services/Ai/Contracts/`, `app/Services/Ai/Registries/`, `app/Services/Ai/Targets/PostAiAdapter.php`, `app/Http/Controllers/Admin/AiImportController.php`, `config/ai-import.php`, `config/ai-agent.php`.
- Frontend files: `resources/js/components/ai/`, `resources/js/services/aiAgent.js`,
  `resources/js/stores/aiAgent.js` và `resources/js/views/apps/blog/post/dialog/CreateWithAiDialog.vue`.
- Candidate có session/parent lineage, regenerate khác retry kỹ thuật, apply toàn bộ
  hoặc từng field và ghi provenance. Candidate không tạo Post/slug trước khi Apply.
- The provider is optional/configurable; without credentials the deterministic sanitizer/extractor returns a safe draft and source thumbnail URL. Inline text input shares the same queue and candidate lifecycle.
- Thumbnail asset creation and actual rewrite quality depend on configured provider/media pipeline; no provider credential is committed to the repository.
- API keys stay server-side and are configured through environment/config.
- AI result is a draft preview; it must not auto-create/publish a Post without user action.
- Laravel AI SDK chưa bật vì môi trường hiện tại PHP 8.2; cần PHP 8.3 trước khi
  cài provider SDK tương thích. HTTP OpenAI/Gemini adapters đã hoạt động qua
  `ProviderRegistry` và không đưa secret xuống browser.

## 5. API route map

All admin routes are under `/api/admin`, protected by Sanctum admin middleware and account status. Check `routes/api.php` for the latest exact permission middleware.

### Auth and health

- `GET /api/health`
- `POST /api/admin/login`
- `GET /api/admin/me`
- `POST /api/auth/token/revoke`
- `POST /api/auth/token/revoke-all`

### Posts

- `GET /api/admin/posts`
- `POST /api/admin/posts`
- `GET /api/admin/posts/{post}`
- `PUT /api/admin/posts/{post}`
- `DELETE /api/admin/posts/{post}`
- `POST /api/admin/slugs/preview`
- `GET /api/admin/ai-agent/capabilities/{target}`
- `POST|GET /api/admin/ai-agent/sessions`, `/api/admin/ai-agent/sessions/{id}`
- `POST /api/admin/ai-agent/sessions/{id}/regenerate|retry|cancel`
- `GET /api/admin/ai-agent/sessions/{id}/candidates`
- `POST /api/admin/ai-agent/candidates/{id}/apply`
- `POST|GET /api/admin/posts/ai/import`, `/api/admin/posts/ai/import/{id}`
- `POST /api/admin/posts/ai/import/{id}/regenerate`
- `POST /api/admin/posts/ai/import/{id}/retry`
- `GET /api/admin/posts/ai/import/{id}/candidates`
- `POST /api/admin/posts/ai/import/{id}/apply`
- `POST /api/admin/posts/ai/import/{id}/cancel`, `DELETE /api/admin/posts/ai/import/{id}`

### Media Asset

- `GET /api/admin/media-assets`
- `POST /api/admin/media-assets`
- `GET /api/admin/media-assets/{mediaAsset}`
- `PATCH /api/admin/media-assets/{mediaAsset}`
- `DELETE /api/admin/media-assets/{mediaAsset}`
- `POST /api/admin/media-assets/{mediaAsset}/retry`
- `GET /api/admin/media-assets/{mediaAsset}/download`
- `POST /api/admin/media-assets/usages/reorder`
- `POST /api/admin/media-assets/{mediaAsset}/usages`
- `DELETE /api/admin/media-assets/{mediaAsset}/usages/{usage}`

### Taxonomy and resources

- Category, Tag, Technology list/show/create/update/delete routes are in `routes/api.php`.
- Resource and ResourceVersion CRUD/action routes are in the same file.

## 6. Frontend route map

- `/apps/blog/post/add` — create Post.
- `/apps/blog/post/add?post={id}` — edit Post.
- `/apps/blog/post/list` — Post list.
- `/apps/media/file` — API-backed file library.
- `/apps/media/media-asset` — media asset grid/library; must not use demo data in production.
- `/apps/media/media-asset/folder/:folder` — verify folder contract before enabling as persisted feature.

## 7. Database schema map

Core migrations include users/admin extension, customers, Sanctum tokens, permissions, activity log, slugable, resources, resource versions, downloads, categories, tags, technologies, taxonomy pivots, media, media assets/usages, posts and SEO metadata.

When adding Post options or AI imports, add a new timestamped migration. Never edit an already-applied migration to change production schema.

## 8. Test map and commands

### Backend

- Media: `MediaApiTest`, `MediaAssetTest`, `MediaAssetUsageTest`, `MediaUploadPipelineTest`, `MediaTask8IntegrationTest`.
- Post/SEO/slug: `PostSeoSlugTest`.
- Taxonomy: `TaxonomyCrudTest`.
- Auth/permissions/routes: `AuthenticationTest`, `SanctumFoundationTest`, `RouteBoundaryTest`, `ResourcePermissionTest`.

```powershell
php artisan test
php artisan test --filter=Post
php artisan test --filter=Media
php artisan route:list --path=api
```

### Frontend

- Post: `postService.test.js`, `postForm.test.js`, `postEditor.test.js`, `postSeo.test.js`, `postMediaPanel.test.js`, slug tests.
- Media: `mediaAssetService.test.js`, `mediaAssetStore.test.js`, `mediaLibraryDialog.test.js`, `fakeMediaApi.test.js`.
- AI: `aiAgentService.test.js` và các feature/unit test `AiImportApiTest`,
  `AiCandidateApiTest`, `AiRegistriesTest`, `ArticleImportServiceTest`.

```powershell
npm run test:run
npm run test:run -- tests/frontend/postForm.test.js
npm run test:run -- tests/frontend/mediaAssetStore.test.js
npm run build
npm run lint
```

## 9. Environment/config checklist

- `VITE_API_BASE_URL`: frontend API base.
- `VITE_PUBLIC_URL`: public permalink origin.
- `VITE_TINYMCE_API_KEY` or `VITE_TINYMCE_LICENSE_KEY`: editor runtime.
- Laravel app key, DB, Sanctum, filesystem disks, queue connection.
- AI provider key/model/base URL: server-side only; `AI_IMPORT_ENABLED`,
  `AI_IMPORT_PROVIDER`, `AI_IMPORT_ENDPOINT`, `AI_IMPORT_KEY`, `AI_IMPORT_MODEL`,
  `AI_IMPORT_TIMEOUT`, `AI_IMPORT_CONNECT_TIMEOUT`, `AI_IMPORT_JOB_TIMEOUT`,
  `AI_IMPORT_MAX_REDIRECTS`, `AI_IMPORT_MAX_HTML_BYTES`,
  `AI_IMPORT_MAX_IMAGE_BYTES`, `AI_IMPORT_PROMPT_VERSION`,
  `AI_IMPORT_RETENTION_DAYS`, `AI_IMPORT_QUOTA_PER_HOUR` và
-  `AI_IMPORT_IDEMPOTENCY_WINDOW_MINUTES`. OpenAI/Gemini adapters additionally
  dùng `AI_OPENAI_KEY`, `AI_OPENAI_ENDPOINT`, `AI_OPENAI_MODEL`,
  `AI_OPENAI_TEMPERATURE`, `AI_GEMINI_KEY`, `AI_GEMINI_ENDPOINT`,
  `AI_GEMINI_MODEL` và `AI_GEMINI_TEMPERATURE`.
- Media conversion/size/disk policy: `config/media-assets.php`, `config/media-library.php`, `config/filesystems.php`.

## 10. How future AI should use this file

1. Read this inventory and the relevant plan in `docs/`.
2. Check `git status --short` and preserve unrelated local changes.
3. Inspect the current route, request, action, resource, service, store and tests.
4. Run the smallest relevant test before editing.
5. Update this inventory when adding a domain, route, migration, queue, service or feature.
6. Add a dated entry to the change log below.

## 11. Change log

| Date | Change | Files/area | Tests/build |
|---|---|---|---|
| 2026-09-30 | Created central project inventory | `docs/PROJECT_INVENTORY.md` | `git diff --check` |
| 2026-09-30 | Implemented Post taxonomy, Media UI integration and AI import vertical slice | Post, Media, AI | Backend 96 tests/539 assertions; frontend 59 tests; build pass |
| 2026-09-30 | Restored original Media Asset layout, removed folder counters and stabilized item selection | Media Asset UI/composable | Targeted Media tests and build pass |
| 2026-09-30 | Unified AI Content Agent registry, candidate lineage, provenance, HTTP providers and capability-driven dialog; synchronized plan documents | `app/Services/Ai`, `resources/js/components/ai`, `docs/PLAN_POST_MEDIA_AI_INTEGRATION.md` | Backend 127 tests/670 assertions; frontend 75 tests; ESLint/build pass |
