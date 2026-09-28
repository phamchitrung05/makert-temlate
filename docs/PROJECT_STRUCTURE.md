# Cấu trúc dự án Laravel và Vue 3

**Mục đích:** tài liệu tham chiếu kiến trúc và vị trí file cho developer, reviewer
và Codex khi bắt đầu một phiên làm việc mới.

**Cập nhật lần cuối:** 2026-09-27  
**Tài liệu tiến độ:** [PLAN.md](./PLAN.md)  
**Kế hoạch Media Library:** [PLAN_MEDIA_LIBRARY.md](./PLAN_MEDIA_LIBRARY.md)  
**Tài liệu môi trường:** [ENVIRONMENT.md](./ENVIRONMENT.md)

> `PLAN.md` trả lời câu hỏi “đã làm đến đâu?”. File này trả lời câu hỏi “code
> nằm ở đâu, chạy theo luồng nào và nên mở rộng ở vị trí nào?”. Khi architecture
> thay đổi, cập nhật file này trong cùng thay đổi với code.

## 1. Bức tranh tổng thể

Project có hai boundary chính:

```text
Browser
├── Public website
│   └── Laravel Blade → routes/web.php → Public controllers/views
└── Admin dashboard
    └── Vue 3 + Vuetify → /admin → routes/api.php → Laravel API

Laravel là source of truth cho:
authentication · authorization · resource · order · payment · download
```

Nguyên tắc boundary:

- Public catalog và các trang public được render bằng Blade để giữ SEO.
- Admin là SPA Vue 3 chạy dưới prefix `/admin`.
- Vue chỉ gọi API; không truy cập database và không quyết định quyền nghiệp vụ.
- Laravel API trả response theo envelope thống nhất của `BaseResponse`.
- Admin authentication dùng Sanctum Bearer token; token hiện được giữ trong
  `sessionStorage` ở frontend.
- Frontend module mới dùng JavaScript, Vue Composition API và `<script setup>`;
  không thêm TypeScript nếu chưa có quyết định mới trong `PLAN.md`.

## 2. Bản đồ thư mục quan trọng

```text
app/                         Laravel domain và HTTP backend
├── Actions/                 nghiệp vụ mutation theo use case
├── Enums/                   enum domain
├── Http/
│   ├── Controllers/         điều phối HTTP, không chứa business rule dài
│   ├── Middleware/          middleware nghiệp vụ dùng chung
│   ├── Requests/            validation ở cổng HTTP
│   ├── Resources/           mapping model → JSON data
│   └── Responses/           response envelope/error chung
├── Models/                  Eloquent model, relation, cast, scope, invariant
├── Providers/               service container và bootstrapping
├── Repositories/            contract, criteria và Eloquent implementation
├── Services/                domain service dùng chung
└── Validators/              business validation ở repository/domain boundary

bootstrap/                   khởi tạo app, middleware alias, exception renderer
config/                      cấu hình Laravel/package
database/
├── factories/               factory test/seeder
├── migrations/              schema và foreign key/index
└── seeders/                 dữ liệu local/quyền/catalog mẫu
resources/
├── js/                      admin SPA Vue 3
└── views/                   Blade public/admin shell
routes/
├── api.php                  API health/admin/account/token
└── web.php                  public web, OAuth và admin SPA catch-all
tests/
├── Feature/                 HTTP, auth, permission, CRUD, boundary
└── Unit/                    response/domain unit tests
docs/                        kế hoạch, môi trường và tài liệu kiến trúc

docs/PLAN.md                 roadmap và trạng thái tổng
docs/PLAN_MEDIA_LIBRARY.md   task/dependency cho Media Library trung tâm
docs/PROJECT_STRUCTURE.md    vị trí file và luồng kiến trúc
docs/ENVIRONMENT.md          service local, disk, queue và biến môi trường
```

Các thư mục `resources/js/views/demos`, `resources/js/pages/components` và các
feature Vuexy mẫu là reference UI. Không mass-edit chúng khi triển khai domain
nghiệp vụ mới.

## 3. Laravel backend

### 3.1. Luồng request

```text
HTTP request
  → routes/api.php hoặc routes/web.php
  → middleware/auth/permission
  → FormRequest (nếu có input)
  → Controller
  → Action/Service/Repository
  → Model/Criteria/database
  → JsonResource hoặc BaseResponse
```

Controller chỉ điều phối. Query phức tạp, transaction và business rule phải đi
vào Action, Service, Repository hoặc Model phù hợp.

### 3.2. Route và bootstrap

- `routes/web.php`
  - OAuth customer `/auth/{provider}/...`.
  - Public home `/` dùng `HomeController` và Blade.
  - `/admin` trả `resources/views/admin.blade.php`.
  - `/admin/{any?}` là catch-all cho Vue Router; không dùng catch-all root cho
    public website.
- `routes/api.php`
  - `/api/health` không cần đăng nhập.
  - `/api/admin/login` cấp admin Sanctum token.
  - `/api/admin/*` yêu cầu `auth:sanctum`, ability `admin`, account active và
    permission tương ứng.
  - `/api/account/*` dành cho customer.
  - `/api/auth/token/*` revoke token.
- `bootstrap/app.php`
  - Đăng ký middleware alias `account.active`, `abilities`, `permission`,
    `role`, `role_or_permission`.
  - Chuyển API exception qua `BaseResponse::fromException()`.
- `bootstrap/providers.php`
  - Đăng ký `AppServiceProvider` và `RepositoryServiceProvider`.

### 3.3. Các lớp backend

| Vị trí | Trách nhiệm | Ví dụ |
| --- | --- | --- |
| `app/Http/Controllers/Admin` | Điều phối admin API | `ResourceController`, `CategoryController` |
| `app/Http/Controllers/Auth` | Token/OAuth authentication | `AdminTokenController` |
| `app/Http/Controllers/Public` | Public web/Blade | `HomeController` |
| `app/Http/Requests/Admin` | Validate request admin | `ResourceCreateRequest`, `ResourceUpdateRequest` |
| `app/Actions/Resources` | Mutation nghiệp vụ có tên | `CreateResourceAction`, `PublishResourceAction` |
| `app/Repositories/Contracts` | Interface query/write | `ResourceRepositoryInterface` |
| `app/Repositories/Eloquent` | Eloquent implementation | `ResourceRepositoryEloquent` |
| `app/Repositories/Criteria` | Filter reusable | `ResourceStatusCriteria`, `PublishedResourceCriteria` |
| `app/Validators` | Business validation | `ResourceValidator` |
| `app/Models` | Relation, cast, scope, invariant | `Resource`, `ResourceVersion`, `Slug` |
| `app/Http/Resources` | Shape dữ liệu trả frontend | `ResourceSummary`, `ResourceItem` |
| `app/Http/Responses` | Envelope thành công/lỗi | `BaseResponse` |
| `app/Enums` | Giá trị domain hữu hạn | `ResourceStatus`, `ResourceType`, `MediaAssetKind`, `MediaAssetVisibility`, `MediaAssetField` |

File PHP mới hoặc file PHP được chỉnh sửa phải giữ comment convention của
repository: mô tả chức năng file/class, inventory method, input/output, side
effect và transaction/exception khi có liên quan.

### 3.4. API response contract

Response thành công và lỗi đều theo shape:

```json
{
  "success": true,
  "message": null,
  "data": {},
  "errors": [],
  "meta": {}
}
```

Danh sách dành cho `VDataTableServer` dùng:

```json
{
  "data": {
    "items": [],
    "itemsLength": 0
  },
  "meta": {
    "pagination": {}
  }
}
```

Không tạo response JSON riêng trong controller nếu `BaseResponse` đã có factory
phù hợp. Frontend service là boundary unwrap envelope; page/component không nên
biết chi tiết xử lý HTTP lỗi.

### 3.5. Resource domain hiện tại

```text
ResourceController
├── ResourceCreateRequest / ResourceUpdateRequest
├── CreateResourceAction / UpdateResourceAction
├── PublishResourceAction / ArchiveResourceAction
├── ResourceRepositoryInterface
├── ResourceValidator
├── ResourceSummary / ResourceItem
└── Resource model + SlugService + taxonomy relations
```

Schema chính nằm ở các migration `2026_09_25_090204` đến
`2026_09_25_090212`, gồm resource, taxonomy, versions và downloads. Catalog
seed mẫu nằm ở `database/seeders/CatalogSeeder.php`.

## 4. Vue 3 admin frontend

### 4.1. Bootstrap và plugin order

Entry point là `resources/js/main.js`. Hàm
`@core/utils/plugins.js:registerPlugins()` tự động import các file trong
`resources/js/plugins` theo thứ tự tên.

Các plugin quan trọng:

```text
resources/js/plugins/
├── 1.router/       Vue Router, file-based routes, guards, additional routes
├── 2.pinia.js      createPinia() và export store instance
├── vuetify/         Vuetify components/theme/icons/defaults
├── i18n/            locale/i18n
├── casl/            ability UI tạm thời, không phải security boundary
└── fake-api/        MSW handlers khi VITE_ENABLE_MSW=true
```

Router guard dùng `useAdminAuthStore(store)` với instance Pinia tường minh vì
guard được đăng ký trong giai đoạn plugin. Component/page dùng
`useAdminAuthStore()` sau khi app đã cài Pinia.

### 4.2. Alias và auto-import

Các alias thường dùng:

```text
@       → resources/js
@core   → resources/js/@core
@layouts→ resources/js/@layouts
@images → resources/images
@styles → resources/styles
@themeConfig → resources/js/@themeConfig.js
```

`vite.config.js` dùng `unplugin-auto-import` cho Vue, Vue Router, VueUse,
Pinia và các composable nội bộ. Vì vậy `ref`, `computed`, `useRoute`,
`useRouter`, `defineStore`, `useApi`, `createUrl` có thể không cần import trong
một số file hiện có. Khi thêm import tường minh, giữ nhất quán với file đang
chỉnh sửa và chạy ESLint.

### 4.3. Quy ước thư mục Vue

| Vị trí | Trách nhiệm |
| --- | --- |
| `resources/js/pages` | Route component được file-based router sinh tự động |
| `resources/js/views` | Feature/page component tái sử dụng, table, form, widget |
| `resources/js/stores` | Store nghiệp vụ dùng chung toàn admin |
| `resources/js/services` | API client/mapping response theo domain |
| `resources/js/composables` | Logic reactive dùng lại nhưng không nhất thiết là global state |
| `resources/js/@core` | Component, composable, store và util nền của template |
| `resources/js/@layouts` | Layout, navigation shell, layout store |
| `resources/js/navigation` | Cấu hình menu vertical/horizontal |
| `resources/js/plugins` | Router, Pinia, Vuetify, i18n, MSW và plugin bootstrap |
| `resources/js/components` | Component dùng chung cấp ứng dụng |
| `resources/js/utils` | HTTP client và utility cấp ứng dụng |

Page nên là composition surface: giữ route/query state và nối các component;
table, form, filter và dialog nên tách ra khi có logic đáng kể.

### 4.4. Pinia hiện tại

Project đang có các nhóm store sau:

```text
resources/js/stores/adminAuth.js                 Setup Store nghiệp vụ auth
resources/js/@core/stores/config.js              Setup Store theme/config
resources/js/@layouts/stores/config.js           Setup Store layout
resources/js/views/apps/chat/useChatStore.js     Options Store feature demo
resources/js/views/apps/calendar/useCalendarStore.js
```

Quy tắc khi thêm store:

- Dùng `defineStore('domainName', ...)` và đặt store global nghiệp vụ ở
  `resources/js/stores`.
- API gọi qua `resources/js/services/<domain>.js`; action store điều phối state
  và gọi service, không nhúng query HTTP vào template.
- Setup Store phải return toàn bộ reactive state, getter và action cần Pinia
  theo dõi.
- Không destructure trực tiếp state/getter làm mất reactivity; dùng
  `storeToRefs(store)` khi cần destructure.
- Không gọi `useXxxStore()` ở module scope trước khi Pinia được cài. Trong router
  guard hoặc utility chạy ngoài component, truyền `store` instance rõ ràng.
- Chỉ persist state thật sự cần. Auth hiện dùng `sessionStorage`; layout dùng
  cookie; chưa cài `pinia-plugin-persistedstate`.

Đối với Resource, store/service dự kiến nên là:

```text
resources/js/stores/resource.js       state list/detail/loading/error + actions
resources/js/services/resource.js     GET/POST/PUT/publish/archive API mapping
```

Search, filter, sort và pagination có thể để ở URL/page state nếu cần refresh,
bookmark hoặc chia sẻ link; không đưa mọi state tạm thời vào store.

Media Library trung tâm dùng contract tại `app/Enums/MediaAsset*`. Alias
`resource_version`, `media_asset` và `post` đã được đăng ký trong
`AppServiceProvider`.

Các file Media Library đã triển khai đến Task 9:

```text
app/Models/MediaAsset.php
app/Policies/MediaAssetPolicy.php
database/migrations/*_create_media_assets_table.php
database/factories/MediaAssetFactory.php
database/seeders/MediaAssetSeeder.php
app/Models/MediaAssetUsage.php
app/Models/Concerns/HasMediaAssets.php
app/Services/MediaAssetUsageService.php
database/migrations/*_create_media_asset_usages_table.php
database/factories/MediaAssetUsageFactory.php
app/Services/Media/MediaUploadValidator.php
app/Services/Media/ArchiveSecurityScanner.php
app/Actions/Media/UploadMediaAssetAction.php
app/Actions/Media/CalculateMediaChecksumAction.php
app/Jobs/Media/ScanMediaAssetJob.php
app/Jobs/Media/ProcessMediaConversionsJob.php
app/Http/Requests/Admin/MediaAssetUploadRequest.php
app/Http/Requests/Admin/MediaAssetIndexRequest.php
app/Http/Requests/Admin/MediaAssetMetadataUpdateRequest.php
app/Http/Requests/Admin/MediaAssetUsageRequest.php
app/Http/Requests/Admin/MediaAssetReorderRequest.php
app/Http/Resources/MediaAssetResource.php
app/Http/Resources/MediaAssetUsageResource.php
app/Http/Controllers/Admin/MediaAssetController.php
app/Services/Media/MediaAssetLinkableResolver.php
app/Actions/Media/UpdateMediaAssetMetadataAction.php
app/Enums/MediaScanStatus.php
app/Enums/MediaConversionStatus.php
app/Exceptions/MediaSecurityException.php
config/media-assets.php

resources/js/services/mediaAsset.js
resources/js/stores/mediaAsset.js
resources/js/pages/apps/media/file/index.vue
resources/js/views/apps/media/MediaAssetTable.vue
resources/js/views/apps/media/MediaAssetUploadDialog.vue
resources/js/views/apps/media/MediaAssetDetails.vue
resources/js/views/apps/media/field/MediaAssetField.vue
resources/js/views/apps/media/field/MediaLibraryDialog.vue
resources/js/views/apps/media/field/MediaAssetGrid.vue
resources/js/views/apps/media/field/MediaUploadDropZone.vue
resources/js/views/apps/media/field/mediaAssetFields.js
resources/js/views/apps/media/field/useMediaCapabilities.js
resources/js/plugins/fake-api/handlers/apps/media/db.js
resources/js/plugins/fake-api/handlers/apps/media/index.js
vitest.config.js
tests/frontend/setup.js
tests/frontend/mediaAssetService.test.js
tests/frontend/mediaAssetStore.test.js
tests/frontend/mediaLibraryDialog.test.js
tests/frontend/resourceForm.test.js
tests/frontend/fakeMediaApi.test.js
```

Vitest chạy trong `happy-dom` qua `npm run test:run`; các test service/store
mock boundary HTTP, còn picker/resource form dùng Vue Test Utils với Pinia thật
và stub presentation. Fake API test bind trực tiếp resolver MSW để kiểm tra
envelope/pagination và field-kind validation cùng contract Laravel.

Các file tích hợp domain của Task 8:

```text
app/Actions/Resources/CreateResourceVersionAction.php
app/Actions/Resources/UpdateResourceVersionAction.php
app/Actions/Resources/MarkResourceVersionReadyAction.php
app/Http/Controllers/Admin/ResourceVersionController.php
app/Http/Requests/Admin/ResourceVersionCreateRequest.php
app/Http/Requests/Admin/ResourceVersionUpdateRequest.php
app/Http/Resources/ResourceVersionResource.php
app/Models/Post.php
app/Actions/Posts/CreatePostAction.php
app/Actions/Posts/UpdatePostAction.php
app/Http/Controllers/Admin/PostController.php
app/Http/Requests/Admin/PostCreateRequest.php
app/Http/Requests/Admin/PostUpdateRequest.php
app/Http/Resources/PostResource.php
database/migrations/*_create_posts_table.php
tests/Feature/MediaTask8IntegrationTest.php

resources/js/services/resourceVersion.js
resources/js/stores/resourceVersion.js
resources/js/views/apps/ecommerce/resource-version/ResourceVersionForm.vue
resources/js/pages/apps/ecommerce/resource-version/
resources/js/services/post.js
resources/js/stores/post.js
resources/js/views/apps/blog/post/PostForm.vue
resources/js/pages/apps/blog/post/
resources/js/plugins/fake-api/handlers/apps/resourceVersions/
resources/js/plugins/fake-api/handlers/apps/posts/
```

`MediaAsset` sở hữu collection Spatie `library`; bảng `media_assets` chỉ lưu
metadata nghiệp vụ, còn bảng `media` lưu file vật lý. Disk `media_public` và
`media_private` được cấu hình trong `config/filesystems.php` và chọn qua
`config/media-library.php` theo visibility. Model `Resource`,
`ResourceVersion` và `Post` dùng trait `HasMediaAssets`; mutation usage đi qua
`MediaAssetUsageService` để giữ field/kind/cardinality/permission trong một
transaction. Upload Task 4 dùng `MediaUploadValidator` và
`ArchiveSecurityScanner` trước khi action copy file vào Spatie collection;
checksum và scan/conversion status được lưu trên custom properties của bảng
`media`.

Task 5 bổ sung Media API tại `/api/admin/media-assets`: list/detail/upload,
metadata update, attach/detach/reorder, delete, retry và download. Route dùng
permission admin riêng (`media.view`, `media.upload`, `media.attach`,
`media.delete`, `media.retry`); list filter và pagination chạy ở backend. Private
asset chỉ trả temporary URL có hạn hoặc stream qua controller sau policy check;
archive phải có `scan_status=clean` mới được download.

Task 6 bổ sung màn hình `Media > File` tại `apps-media-file`. Page giữ filter,
pagination, sort và query URL; `MediaAssetTable` chỉ render server-side table,
`MediaAssetUploadDialog` chỉ phát payload upload, còn `MediaAssetDetails` chỉ
trình bày metadata an toàn. Fake API nằm trong MSW handler cùng response contract
để có thể chuyển sang Laravel thật tại service boundary.

Task 7 bổ sung picker dùng chung dưới `resources/js/views/apps/media/field/`.
`MediaAssetField` là wrapper cho form nghiệp vụ; `MediaLibraryDialog` nhận
`kind`, `field`, `multiple`, `visibility` và emit asset đã chọn. Grid/upload
drop-zone chỉ xử lý presentation và phát event; capability frontend chỉ khóa UI,
authorization thật vẫn do backend policy/permission quyết định.

Task 8 nối `MediaAssetField` vào các domain qua payload `media`:

```text
Resource
  media.cover_id       → resource.cover (image, single, public)
  media.preview_ids    → resource.preview (image, multiple, public)
ResourceVersion
  media.package_id     → resource_version.package (archive, private, single)
  media.documentation_ids → resource_version.documentation (document, multiple)
Post
  media.thumbnail_id   → post.thumbnail (image, single)
  media.content_image_ids → post.content_images (image, multiple)
```

Create/update actions gọi `MediaAssetUsageService::syncFields()` trong cùng
transaction với model. Replace sẽ xóa usage cũ theo field và tạo lại theo thứ
tự payload; delete model gọi `detachAll()` trước soft delete. Resource Version
chỉ chuyển `ready` khi có đúng một package archive private với
`scan_status=clean`.

Trong list API, `field` là context của picker và được backend ánh xạ về `kind`
được phép; nó không giới hạn kết quả vào các asset đã có usage ở field đó. Nhờ
vậy asset mới hoặc chưa attach vẫn xuất hiện để người dùng lựa chọn.

### 4.5. Router và page

- File dưới `resources/js/pages` tạo route tự động qua
  `unplugin-vue-router`.
- Tên route được sinh từ đường dẫn, ví dụ:
  - `pages/apps/ecommerce/resource/list/index.vue` →
    `apps-ecommerce-resource-list`.
  - `pages/apps/ecommerce/resource/add/index.vue` →
    `apps-ecommerce-resource-add`.

- Media Library admin dùng cùng file-based router và được hiển thị trong cả
  vertical/horizontal navigation:

  ```text
  navigation
  └── Media
      └── File → pages/apps/media/file/index.vue → apps-media-file
  ```

  Page `Media > File` là composition surface; bảng, upload dialog và detail
  dialog nằm trong `resources/js/views/apps/media/`, còn HTTP đi qua
  `mediaAssetService` và `useMediaAssetStore`.
- Route bổ sung thủ công nằm ở
  `resources/js/plugins/1.router/additional-routes.js`.
- Guard nằm ở `resources/js/plugins/1.router/guards.js`.
- Trang cần metadata layout/auth bằng `definePage({ meta: { ... } })` khi cần.

### 4.6. Navigation

Menu phải được cập nhật ở cả hai file nếu admin hỗ trợ hai layout:

```text
resources/js/navigation/vertical/apps-and-pages.js
resources/js/navigation/horizontal/apps.js
```

Dùng route name thay vì hard-code URL khi navigation trỏ tới page nội bộ.

### 4.7. Fake API và API thật

Khi `VITE_ENABLE_MSW=true`, MSW được đăng ký từ
`resources/js/plugins/fake-api/index.js`. Mỗi feature có thể đặt handler và
dataset riêng:

```text
resources/js/plugins/fake-api/handlers/apps/<feature>/index.js
resources/js/plugins/fake-api/handlers/apps/<feature>/db.js
```

Fake response phải giữ contract giống Laravel thật. Ví dụ Resource dùng
`data.items`, `data.itemsLength` và `meta.pagination`. Khi API Laravel sẵn sàng,
thay service/endpoint ở boundary; không đổi table chỉ vì chuyển fake → thật.

## 5. Ví dụ feature Resource hiện tại

```text
resources/js/pages/apps/ecommerce/resource/
├── list/index.vue              page filter/query + request + table state
└── add/index.vue               form UI tạo resource (mutation hiện mô phỏng)

resources/js/views/apps/ecommerce/resource/
└── ResourceTable.vue            VDataTableServer và row actions

resources/js/plugins/fake-api/handlers/apps/resources/
├── db.js                        12 fake resources
└── index.js                     GET /api/admin/resources
```

Đây là UI/fake-data boundary. Form Add chưa phải `ResourceForm.vue` độc lập và
chưa nối POST/PUT thật; việc đó thuộc bước tiếp theo của `PLAN.md`.

## 6. Quy trình mở rộng feature mới

### Thêm một admin page

1. Tạo file dưới `resources/js/pages` đúng cấu trúc route.
2. Thêm `definePage` metadata nếu cần auth/layout khác mặc định.
3. Tách table/form vào `resources/js/views` khi page bắt đầu có logic lớn.
4. Thêm menu vertical và horizontal bằng route name.
5. Thêm loading, empty, error và retry state.
6. Chạy ESLint và `npm run build`.

### Thêm endpoint Laravel

1. Thêm route trong `routes/api.php` hoặc `routes/web.php` đúng boundary.
2. Thêm middleware/auth/permission ở route group.
3. Tạo FormRequest nếu endpoint nhận input.
4. Tạo hoặc dùng Action/Service/Repository/Criteria phù hợp.
5. Trả `BaseResponse`/`JsonResource`, không trả JSON ad-hoc.
6. Thêm feature/unit test và cập nhật tài liệu nếu boundary thay đổi.

### Thêm domain store

1. Tạo `resources/js/stores/<domain>.js` với `defineStore`.
2. Tạo `resources/js/services/<domain>.js` cho request và unwrap mapping.
3. Store quản lý state server, loading, error và action; component quản lý UI
   state cục bộ.
4. Dùng `storeToRefs` khi destructure state/getter.
5. Không lưu password, token raw hoặc dữ liệu nhạy cảm vào source/log.

### Thêm fake API

1. Tạo `db.js` và `index.js` dưới handler folder của feature.
2. Giữ response giống endpoint Laravel dự kiến.
3. Đăng ký handler trong `resources/js/plugins/fake-api/index.js`.
4. Kiểm tra query search/filter/pagination/sort bằng UI hoặc test phù hợp.

## 7. Những điều không nên làm

- Không gọi database từ Vue hoặc Blade public.
- Không đặt business authorization chỉ ở CASL/UI; quyền thật nằm ở Laravel
  policy/permission middleware.
- Không gọi API rải rác trong template.
- Không đặt domain Resource mới trong thư mục demo chỉ vì demo có component gần
  giống.
- Không sửa migration đã chạy ở môi trường dùng chung; tạo migration mới.
- Không thêm TypeScript cho module mới khi chưa cập nhật quyết định kiến trúc.
- Không xóa comment convention của file đang chỉnh sửa.
- Không dùng `git reset --hard` hoặc thao tác phá dữ liệu để “dọn” worktree.

## 8. Lệnh khám phá nhanh cho phiên làm việc mới

```powershell
# Xem tiến độ và architecture
Get-Content docs/PLAN.md
Get-Content docs/PROJECT_STRUCTURE.md

# Tìm route/controller/resource hiện có
rg -n "Route::|class .*Controller|defineStore|useApi|definePage" routes app resources/js

# Liệt kê file theo domain
rg --files app resources/js database tests | rg "Resource|resource|stores|services"

# Kiểm tra chất lượng frontend
npx eslint resources/js -c .eslintrc.cjs
npm run build

# Kiểm tra backend
php artisan route:list
php artisan test
```

## 9. Quy tắc cập nhật tài liệu

- `docs/PLAN.md`: cập nhật trạng thái, checklist và mốc tiến độ.
- `docs/PROJECT_STRUCTURE.md`: cập nhật khi thêm boundary, thư mục kiến trúc,
  convention hoặc luồng dữ liệu mới.
- `docs/ENVIRONMENT.md`: cập nhật khi thêm biến môi trường, service local hoặc
  dependency hạ tầng.
- Khi ba file trên mâu thuẫn, ưu tiên code đang chạy, sau đó cập nhật tài liệu
  trong cùng task và ghi rõ phần còn pending trong `PLAN.md`.
