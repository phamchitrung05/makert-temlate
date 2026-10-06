# Cấu trúc dự án Laravel và Vue 3

**Mục đích:** tài liệu tham chiếu kiến trúc và vị trí file cho developer, reviewer
và Codex khi bắt đầu một phiên làm việc mới.

**Cập nhật lần cuối:** 2026-10-06
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

### 4.3.1. Cấu trúc dialog bắt buộc

`AppDialogLayout` là mẫu bố cục. Tất cả dialog của ứng dụng phải dùng
`resources/js/components/dialogs/AppDialogLayout.vue` bên trong
`VDialog scrollable`, gồm ba vùng luôn hiện diện:

```text
VDialog scrollable               giới hạn chiều cao theo viewport, quản lý focus/overlay
└── AppDialogLayout
    ├── DialogCloseBtn          nút X nổi ngoài góc trên bên phải của card
    └── VCard                   flex column, min-height: 0, overflow: hidden
        ├── header              tiêu đề + mô tả tùy chọn; không cuộn
        ├── body (default slot) form, dữ liệu, lỗi/loading; chỉ vùng này được cuộn
        └── footer (#footer)    Hủy/Đóng + thao tác chính; không cuộn
```

- Header và footer không được đặt trong `VCardText`, `VForm`, `PerfectScrollbar`
  hoặc container cuộn của content. Header/footer dùng `flex: 0 0 auto`;
  body có `min-block-size: 0` và `overflow-y: auto`.
- Dùng `title`/`subtitle` cho header mặc định; `#header` chỉ dùng khi cần icon,
  thông tin hoặc control đặc thù. `AppDialogLayout` quản lý vị trí nút đóng,
  `close-label` và `close-disabled`; caller xử lý event `close`.
- Nút X dùng `DialogCloseBtn` theo mẫu Vuexy, là sibling của `VCard` để nổi
  ngoài góc card mà không bị `overflow: hidden` cắt. Không đặt X trong header
  hoặc body, không thêm nút X riêng ở feature. Attrs/class/style của layout
  được chuyển vào card; vị trí X theo CSS Vuexy dùng chung, kể cả RTL.
- Đặt nút Hủy/Đóng và Lưu/Xác nhận/Tạo/Áp dụng trong `#footer`. Nếu chỉ xem thông
  tin, có thể dùng footer Đóng mặc định. Footer được wrap trên màn hình nhỏ,
  không tạo cuộn ngang hoặc chiều cao dialog vượt màn hình.
  Control cục bộ như preview nguồn, tải lại, toolbar editor hoặc bước chạy AI
  có thể nằm cạnh field trong content; action kết thúc dialog phải ở footer.
- Khi chuyển nút submit ra khỏi form, giữ handler validation hiện có: dùng
  `type="submit" :form="formId"` với `VForm :id="formId"` (`useId()` tạo ID
  riêng cho mỗi instance), hoặc gọi handler có guard bằng nút `type="button"`.
  Kiểm tra cả nhấn nút và Enter; không mất validation hoặc gửi request hai lần.
- Form dài chỉ có một vùng cuộn chính. Picker nhiều panel như Media Library
  dùng `:body-scroll="false"`; scrollbar từng panel vẫn nằm trong body. Trên
  mobile hoặc viewport thấp, content cuộn trong body và header/footer luôn ở ngoài vùng cuộn.
- Loading/error không thay toàn bộ card bằng spinner. Giữ khung và các field,
  hiện đủ ngay khi mở và disable khi chưa sẵn sàng, kể cả khi GET lỗi. Vòng
  xoay ở `#overlay` phải nằm trên toolbar editor, dùng `pointer-events: none`
  để nút Hủy/X vẫn hoạt động khi tải; lỗi và tải lại nằm trong content.
  Khi lưu, khóa các đường đóng cần thiết bằng `persistent` và `close-disabled`.
- Không reset form/title/action trong lúc hiệu ứng đóng còn chạy. Caller giữ
  dữ liệu cho đến `VDialog @after-leave`; cảnh báo bản chưa lưu cũng dùng khung
  ba vùng này. Bỏ qua event `after-leave` cũ nếu dialog đã mở lại.
  Layout chung không tự sửa state hoặc gọi API.
- Dialog chứa `PostEditor` dùng Tiny Cloud với `ui_mode: 'split'` để menu của
  TinyMCE nằm trong vùng editor. Cửa sổ riêng như Source Code được TinyMCE
  gắn vào body; CSS dùng chung trong `PostEditor.vue` đặt `.tox.tox-tinymce-aux`
  ở `z-index: 2500`, phía trên stack dialog 2400 của ứng dụng. Không thêm CSS
  scoped ở caller để sửa portal ngoài component.
- Caller nhận `PostEditor @editor-dialog` và dùng
  `VDialog :retain-focus="!editorDialogOpen"`. Chỉ nhường focus khi cửa sổ
  TinyMCE mở; đóng/unmount editor phải trả trạng thái về false. MediaLibrary
  vẫn là dialog theo khung ba vùng ở trên, giữ focus và thứ tự overlay riêng.

Vị trí file: dialog đặc thù nằm trong `views/<feature>/dialog/`, tên kết thúc
bằng `Dialog.vue`; nội dung dùng chung có thể là `<Feature>DialogContent.vue`.
`components/dialogs/` dành cho layout và dialog tái sử dụng toàn ứng dụng.
Các dialog mẫu minh họa API Vuetify trong `views/demos/` giữ hành vi demo;
khi đưa mẫu vào luồng ứng dụng phải chuyển sang cấu trúc trên.

Mẫu tối thiểu cho dialog mới:

```vue
<VDialog v-model="open" max-width="680" scrollable :persistent="saving">
  <AppDialogLayout title="Chỉnh sửa nội dung" :close-disabled="saving" @close="requestClose">
    <VCardText>
      <VForm :id="formId" @submit.prevent="submit">
        <!-- Các field; formId = useId(), submit giữ validation/guard. -->
      </VForm>
    </VCardText>
    <template #footer>
      <VCardActions class="justify-end">
        <VBtn variant="tonal" color="secondary" :disabled="saving" @click="requestClose">Hủy</VBtn>
        <VBtn type="submit" :form="formId" variant="flat" :loading="saving" :disabled="!canSave">Lưu</VBtn>
      </VCardActions>
    </template>
  </AppDialogLayout>
</VDialog>
```

Acceptance trước khi hoàn tất dialog: cuộn content dài vẫn thấy header/footer,
nút đóng/Hủy/Lưu hoạt động đúng, loading giữ kích thước và dữ liệu, cảnh báo bản
chưa lưu/after-leave không bị phá, màn hình nhỏ không cuộn cả dialog hoặc mất nút.

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

Các file Media Library đã triển khai đến Task 10:

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
  media.gallery_image_ids → post.gallery (image, multiple, ordered, public)
  content HTML/img.src → URL thuần, không tạo media_asset_usages
```

Create/update actions gọi `MediaAssetUsageService::syncFields()` trong cùng
transaction với model. Replace sẽ xóa usage cũ theo field và tạo lại theo thứ
tự payload; delete model gọi `detachAll()` trước soft delete. Resource Version
chỉ chuyển `ready` khi có đúng một package archive private với
`scan_status=clean`.

Trong list API, `field` là context của picker và được backend ánh xạ về `kind`
được phép; nó không giới hạn kết quả vào các asset đã có usage ở field đó. Nhờ
vậy asset mới hoặc chưa attach vẫn xuất hiện để người dùng lựa chọn.

### 4.5. AI Content Agent dùng chung

AI Content Agent dùng chung cho Post/Resource/Sound theo boundary registry và
adapter; không tạo một pipeline riêng cho từng model:

```text
app/Services/Ai/Contracts/             provider/target contract
app/Services/Ai/Registries/             target/provider/prompt/schema registry
app/Services/Ai/Targets/PostAiAdapter.php
app/Services/Ai/Content/                source, sanitize, validate, import pipeline
app/Services/Ai/Content/Agents/         Analyze + Plan, Write, Edit cho Post content
app/Services/Ai/Content/Pipelines/      điều phối/checkpoint ba bước
app/Services/Ai/Content/Prompts/        prompt và schema theo bước
app/Services/Ai/Content/Quality/        evidence/references và quality gates
app/Services/Ai/Content/Quality/ArticleSourceLinkPolicy.php  manifest link nguồn dùng chung
app/Services/Ai/Data/                  request/response DTO độc lập target
app/Services/Ai/WritingProfiles/       profile/version/snapshot/phân tích bài mẫu
app/Services/Ai/Providers/Adapters/      structured provider adapters
app/Services/Ai/Providers/Transport/     shared HTTP boundary and connections
app/Services/Ai/Providers/Catalog/       provider/model catalog and resolver
app/Services/Ai/Providers/Diagnostics/   response diagnostics and redaction
app/Services/Ai/Images/                  image generation orchestration/adapters
app/Services/Ai/Runs/                    run lifecycle and asset cleanup
app/Services/Ai/Settings/                AI settings
app/Services/Ai/Provenance/              field-level provenance
app/Models/AiImport.php                 session/run/candidate lineage
app/Models/AiProvenance.php             provenance theo field đã apply
app/Http/Controllers/Admin/AiImportController.php
config/ai-agent.php                    target/output/prompt/schema
config/ai-providers.php                driver/preset/adapter và connection môi trường
config/ai-import.php                   giới hạn nguồn và lifecycle của pipeline
config/ai-content.php                  pipeline mode, prompt/version và budget/gates
database/migrations/*ai_import*         lifecycle và lineage fields
resources/js/services/aiAgent.js
resources/js/stores/aiAgent.js
resources/js/components/ai/              dialog/preview dùng chung
resources/js/views/ai/content/           tạo/review/regenerate/apply nội dung Post
```

Capability được đọc từ `GET /api/admin/ai-agent/capabilities/{target}`. Generic
session dùng `/api/admin/ai-agent/sessions/*`, còn các route dưới
`/api/admin/posts/ai/import` được giữ để tương thích. Candidate chưa tạo slug hoặc
Post thật, chỉ thao tác Apply mới gọi Post Action/SlugService. API key luôn ở
backend; deterministic chỉ chạy khi được chọn rõ ràng, provider cấu hình lỗi không tự fallback che lỗi.

Tạo toàn bộ bài Post chỉ dùng C: Analyze + Plan → Write → Edit. Luồng B và
switch `AI_CONTENT_PIPELINE` đã gỡ; snapshot/config cũ không đổi routing hoặc
giảm ngân sách ba request. Các trường riêng title/SEO/excerpt giữ request theo
hợp đồng trường, không phải nhánh B tạo bài. C lỗi không fallback sang B.

Prompt bài viết **2.2** đưa cùng `link_requirements` tới Analyze, Write và Edit.
`ArticleSourceLinkPolicy` phân biệt mục lục đầu bài với link
tham khảo, giữ URL nguyên vẹn; một link cũng xuất hiện trong prose vẫn bắt buộc.
Quality gate chặn thiếu link tham khảo hoặc href ngoài allowlist nguồn, không
suy đoán/sửa URL. Schema tách source blocks `S...`, facts `F...` và ảnh `I...`;
Writer/Editor chỉ dùng fact IDs từ Analyze đã validate. Schema/prompt/context
của snapshot **2.0/2.1** giữ nguyên để checkpoint tiếp tục khớp hash.

`ArticleNumberNormalizer` thuộc `Content/Quality`, được `ArticleQualityGate`
gọi khi đối chiếu token số. Chỉ nhận biểu diễn tương đương có ngữ cảnh rõ:
ngày hợp lệ, giờ 12/24, thế kỷ, số đếm theo đơn vị, tập thứ trong tuần và dấu
phân nhóm hàng nghìn của số đếm nguyên. Phiên bản, số thập phân, tiền và giá
trị khác không được gộp bằng quy tắc này. HTML/candidate và artifacts gốc
không bị sửa; regression gồm cả ca sai giá trị/ngữ cảnh phải bị chặn.

Đối chiếu pilot lỗi offline tại `scripts/ai-quality/audit.php`; chạy model/export
gói chấm tại `scripts/ai-quality/evaluate.php`. Nguồn/hash/calls ở
`docs/qa/task2-quality`; kết quả tự động chỉ là gate kỹ thuật, chấm văn phong và
facts bằng người đọc được chủ dự án chuyển sang đợt riêng ngày 2026-10-05.
Task 2 đã nghiệm thu kỹ thuật tại FIX 1 mục 12.35; chưa có điểm người đọc hoặc
kết luận chất lượng/rollout từ gate tự động.

Nhóm `app/Services/Ai/Content/Evaluation` có `ArticleQualityEvaluation` và
`EvaluationProviderRecorder` để chạy/ghi artifacts; `ArticleQualityReviewBundle`
render hai bộ chấm mù, `ArticleQualityHumanReview` kiểm CSV độc lập và
`ArticleQualityStudyReport` tổng hợp kỹ thuật/người đọc riêng. Runner không ghi
AiImport/Post/Settings. Đánh giá mới chỉ nhận C; report/CSV hỗ trợ một ứng viên
không có preference giữa hai bài hoặc tỷ lệ so sánh. B/C chỉ còn được đọc từ
study lịch sử với source/model/profile/brief/hash khớp; không đổi corpus,
artifacts hoặc nhãn khi người đọc đã bắt đầu chấm.

`scripts/ai-quality/freeze-v2.php`/`sources-v2.json` tạo corpus bổ sung mới;
`report.php` xuất HTML/CSV và report offline, dùng `review.js`/`review.css`.
Các assets này phục vụ QA, không thuộc giao diện AI Content production.
Ô chưa chấm là `null`; lỗi factual không hòa vào điểm văn phong, tên người đọc
phải khác nhau, CSV mang hash của bộ chấm. Ảnh nguồn chỉ có metadata/chú thích,
không tự tạo hoặc gán MediaAsset. Mọi kết luận rollout vẫn cần người đọc/owner.

Từ 2026-10-05, Post List/Add/Edit không có nút hoặc dialog tạo nội dung AI.
Luồng tạo/review/regenerate/Apply tập trung ở trang AI Content; PostForm phụ trách
nhập/sửa/lưu bài viết. Khung dialog bắt buộc vẫn là `AppDialogLayout` ở mục 4.3.1.

`ai-agent.targets.<target>.outputs` là allowlist nhóm đầu ra của từng tài nguyên:
`title`, `excerpt`, `content`, `seo`, `thumbnail` cho Post. Taxonomy do người dùng chọn thủ công; không thuộc generation schema/outputs.
Nhãn hiển thị và ánh xạ field canonical thuộc `ai-agent.output_definitions`.
Capability của target cung cấp danh sách này cho select nhiều lựa chọn dạng tag;
frontend gửi nhóm đã chọn qua `requested_outputs`. Nhóm đầu ra mô tả dữ liệu AI
được phép tạo, không phải toàn bộ cột của model domain hoặc các trường publish.

Provider được khai báo tập trung trong `ai-providers`: `presets` chứa các driver
AI Settings hỗ trợ, `internal` chứa adapter trích xuất/HTTP JSON và `connections`
đọc cấu hình môi trường. `ProviderRegistry` resolve adapter từ định nghĩa driver
chung, ưu tiên bản ghi database cùng key; không lấy credential từ `ai-agent` hoặc
`ai-import`. Catalog public không trả API key/endpoint. `ai-import` giữ timeout
nguồn/job, giới hạn payload, quota, retention và idempotency.

Chi tiết trạng thái và việc còn lại nằm duy nhất trong
`docs/PLAN_POST_MEDIA_AI_INTEGRATION.md`.

Trang `/admin/ai/content` có workspace giao diện riêng:

```text
resources/js/pages/ai/content/index.vue       header và điều phối hai cột
resources/js/composables/useAiContentWorkspace.js  form tạo mới và danh sách session thật
resources/js/composables/useAiContentCatalog.js    catalog AI Settings và lựa chọn model
resources/js/composables/useAiContentGeneration.js create/polling/error và cleanup lifecycle
resources/js/utils/aiContentInput.js              validation và URL/HTML/text/prompt -> API
resources/js/views/ai/content/AiContentList.vue     tìm kiếm/trạng thái/phân trang
resources/js/views/ai/content/AiContentSourceForm.vue  URL/HTML/text/prompt và tùy chọn
resources/js/views/ai/content/AiContentCreateForm.vue form mới, nút tạo và tiến trình
app/Http/Requests/Admin/AiSessionIndexRequest.php  validation phân trang summary
app/Http/Resources/AiSessionSummaryResource.php    DTO summary không có body/input/credential
```

Workspace có cột phải luôn tạo bài **Post** mới; danh sách không bind nội dung
vào form. Editor chi tiết đã có; workflow review tại FIX 1 mục 12.36 dùng
`useAiContentReview.js` và các component sau:

```text
resources/js/views/ai/content/dialog/AiContentReviewDialog.vue    nguồn/kết quả/metadata/lịch sử
resources/js/views/ai/content/dialog/AiContentReviewDecisionDialog.vue  xác nhận field/lý do, không API
resources/js/views/ai/content/AiContentComparison.vue             hai panel text trơ, không v-html
resources/js/views/ai/content/AiContentReviewHistory.vue          sự kiện Spatie và pagination
app/Http/Controllers/Admin/AiContentReviewController.php         GET review/history, POST approve/reject
app/Services/Ai/Content/AiContentReviewService.php                quyền/owner/version/transaction/audit
app/Http/Requests/Admin/AiCandidateApproveRequest.php             fields/version/ghi chú duyệt
app/Http/Requests/Admin/AiCandidateRejectRequest.php              version/lý do bắt buộc
```

Review state lưu JSON trong `ai_imports`, lịch sử dùng Spatie Activitylog theo
UUID run. Duyệt chỉ tạo Post draft qua actions/provenance hiện có; API Apply cũ
dùng cùng boundary, không cho duyệt trùng hoặc Apply bài đã từ chối. Quyền hiện
tại là `posts.manage` + owner, chưa duyệt chéo owner. Tests nằm tại
`AiContentReviewApiTest.php`, `aiContentReview.test.js`,
`aiContentReviewDialog.test.js` và `aiAgentService.test.js`.
Bảng/model `ai_content_drafts` và thay đổi retention tạm hoãn theo chủ dự án;
run vẫn có hạn, Activitylog/Post không bị cleanup run xóa.
FIX 1 mục 12.39: review dialog dùng hiệu ứng `VDialog` mặc định của project và
`AppDialogLayout` với header/footer cố định, body cuộn. CSS scoped trả lại fade
cho scrim của riêng dialog này, cùng easing/thời lượng mở 225 ms, đóng 125 ms
với khung Vuetify khi người dùng không yêu cầu giảm chuyển động.
GET bắt đầu ngay; `AiContentComparison.active` chỉ bật parse/render văn bản dài
sau `after-enter`, giữ nội dung qua hiệu ứng đóng và dọn ở `after-leave`.
Tiến trình tải nằm inline trong body, không thêm loading overlay phủ tối card.
Prop `loading` giữ hai tiêu đề và chưa kết luận thiếu nguồn khi GET chưa xong.
Nguồn là snapshot `source_meta_json.article_source`; bản AI là `result_json.draft`
hiện tại, gồm các chỉnh sửa đã lưu. Giải pháp tắt transition ở 12.38 đã được thay thế.
Provider/model đọc từ `GET /api/admin/settings/ai`, dùng provider active/có key,
model enabled/available; ưu tiên model text ban đầu và kiểm tra capability thật.
Nút tạo có lý do validation, chống gửi trùng và progress/error/polling cleanup.
URL dùng input URL; file HTML tối đa 5 MB được gửi nguyên bản bằng multipart,
khai báo encoding và extract/sanitize tại backend; nội dung nguồn/đề bài dùng
input text (200.000 ký tự).
Độ dài/ngôn ngữ/tiêu đề/SEO/rewrite được chuyển thành instructions. Thumbnail
có lựa chọn ảnh URL nguồn hoặc sinh AI với model ảnh riêng. `generate` hỗ trợ
mọi nguồn; child image chỉ chạy khi nhóm thumbnail được yêu cầu. Thumbnail-only
regenerate không gọi lại model nội dung.

Luồng thumbnail AI (FIX 1 mục 12.37):

```text
AiThumbnailOptions.vue → aiContentInput.js → AiImportController
ProcessAiImportJob → AiThumbnailService.schedule (parent ready + image queued atomic)
ProcessAiImageGenerationJob → MediaLibrary → AiThumbnailService.sync
draft.thumbnail.media_asset_id + thumbnail_generation → summary/detail/review
AiContentReviewService → Post draft + provenance từ image run
```

`AiThumbnailStatus.vue` trình bày preview/progress/lỗi và emit retry/check/cancel.
`useAiContentGeneration` tiếp tục đọc parent sau content ready khi ảnh đang chạy;
`useAiContentActions` theo dõi lại sau reload và thao tác child ảnh riêng, không
thêm image run vào danh sách bài. Sync khóa parent, giữ edit và bỏ ảnh trễ khi
đã duyệt/từ chối/hết hạn/sai owner/child cũ. Metadata public không query child
theo từng dòng. Tạo ảnh lỗi giữ bài ready; duyệt chờ ảnh terminal, có thể hủy
ảnh để duyệt bài không ảnh. Không có migration/đổi retention.
`POST /api/admin/ai-agent/sessions` lưu run và queue, `GET /sessions/{id}` polling;
`GET /sessions` phân trang summary root còn hạn của chính actor. Page đọc mọi trang,
merge lifecycle mới vào list và không tự apply/publish Post. Các session vẫn theo
retention của backend hiện tại (mặc định 2 ngày), chưa phải kho lưu bản nháp dài hạn.
Menu dọc/ngang đều đặt AI Settings cùng Ai Content trong nhóm Systerm AI.

Thời gian chờ AI được lưu ở `ai_providers.request_timeout` (5–600 giây) và chỉnh
qua `AiProviderConnectionDialog.vue`. `config/ai-providers.php` có `request_timeout`
đọc `AI_PROVIDER_REQUEST_TIMEOUT`, dùng làm mặc định trên form/API tạo provider mới;
provider đã lưu được ưu tiên. Migration thêm cột cho provider hiện tại với 120 giây.
`ModelResolver` chụp timeout riêng vào connection của text/image;
`AiProviderCatalogService` dùng cùng giá trị cho test/discovery.
`AiProviderClient` và adapter không tự retry ConnectionException.
`POST /sessions/{id}/retry` khóa row, kiểm tra provider/model còn dùng được, giữ
UUID/identity/input, cập nhật timeout và retention rồi dispatch sau commit.
`useAiContentGeneration.retryRun()` nối nút Thử lại tác vụ; Cập nhật trạng thái
chỉ đọc GET, không gửi lại request tạo nội dung. Job có timeout tối thiểu bằng HTTP
cộng 120 giây xử lý; database/Redis/Beanstalkd có retry_after tối thiểu 900 giây.
HTTP 429 và GET 5xx vẫn giữ chính sách retry có giới hạn hiện có.

### 4.6. Router và page

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

### 4.7. Navigation

Menu phải được cập nhật ở cả hai file nếu admin hỗ trợ hai layout:

```text
resources/js/navigation/vertical/apps-and-pages.js
resources/js/navigation/horizontal/apps.js
```

Dùng route name thay vì hard-code URL khi navigation trỏ tới page nội bộ.

### 4.8. Fake API và API thật

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

### Cập nhật Settings / AI Content — 2026-10-03

- `app/Settings/AiSettings.php`: settings nhóm `ai` có typed property của Spatie.
- `config/settings.php`: repository database, cache tắt; `AiSettingsService` refresh
  trước mỗi lần đọc/lưu để singleton của package không giữ tuning cũ trong worker.
- `database/migrations/2026_10_03_100000_migrate_settings_to_spatie.php` chuyển
  key/value sang group/name/payload/locked. `legacy_settings` giữ bản lưu cũ.
- `database/settings/*`: property migration; không ghi đè giá trị đã chuyển.
- `config/ai-agent.php` khai báo target label/icon/color/permission, đầu ra được
  chọn, nhãn/field của từng nhóm và hướng dẫn nội dung. `PromptRegistry` tạo prompt
  URL/text dùng schema content chung; provider/credential thuộc `ai-providers`.
  Thêm target văn bản mới bằng một khai báo trong config; apply vào model domain
  vẫn cần adapter/action riêng. Resource/Sound hiện chưa có domain apply/audio.
- `GET /api/admin/ai-agent/targets`: catalog public, chỉ trả target actor có quyền.
  Backend tạo run với `input_json.target_type`, mặc định Post cho record cũ.
- `AiImportController` kiểm tra owner/quyền theo target; Post CRUD và Post API cũ
  tiếp tục có middleware `posts.manage`. List trả cả create/regenerate còn hạn.
- `AiContentSanitizer`: allowlist HTML dùng chung cho pipeline và editor.
- `PATCH /api/admin/ai-agent/candidates/{id}`: title/content/excerpt/SEO có
  `expected_version`; khóa row, từ chối stale/applied/expired/running, ghi audit.
- `useAiContentActions` điều phối dialog edit/remove/regenerate và child polling.
  `AiContentEditorDialog` dùng `PostEditor`; `AiContentRunActionDialog` xác nhận
  xóa hoặc chọn nhóm field. Các action không thay nguồn của form tạo mới.
- `resources/js/pages/settings/index.vue`: route `settings` compose panel/API thật, được dùng bởi
  menu `SYSTERM SETTING` / `SETTING` ở cả navigation dọc và ngang.

### Settings chuẩn hóa — 2026-10-06

- `app/Settings/{Site,Media,Seo,Mail,Security,Language}Settings.php`: sáu nhóm typed lưu trong bảng settings; null fallback config, mail password mã hóa.
- `app/Services/Settings/`: ProjectSettingsService đọc/lưu/audit/version; ProjectMailService refresh transport; SettingsDiagnosticsService probe runtime; ProjectScheduleRegistry là nguồn lịch chung HTTP/console.
- `SettingsController` và FormRequests giữ boundary HTTP/permission/validation; AI writer vẫn là AiSettingsService.
- `services/settings.js`, `useSettings`, `useSettingsLocales` và `views/settings/Settings*.vue`: state theo nhóm, panel tập trung, locale menu runtime. Không thêm Tailwind hoặc TypeScript vào flow JavaScript/Vuetify.
- Upload/conversion, SEO/public/robots và login token/rate limiter dùng cùng nhóm Settings. Capability chưa có không được biến thành toggle lưu giả.
- Contract và kiểm chứng tại FIX 1 mục 9.9/12.40, docs/qa/SETTINGS_2026-10-06/README.md.

### Branding Settings — 2026-10-06

- `SiteBrandingService` lưu logo/favicon UUID trên disk `public`, trả URL an toàn và dọn file theo commit/rollback. Path thuộc `SiteSettings`; không thêm model hoặc usage media.
- `database/settings/2026_10_06_180000_add_site_branding.php` thêm `site.logo_path`/`site.favicon_path` nullable; đã migrate riêng ở local.
- `SettingsBrandingPanel` trình bày; `useSettingsBranding` giữ File/blob và dọn preview; `useSettings` giữ quyền/dirty/version; `settingsService` gửi multipart POST spoof PATCH cùng payload site.
- `useSiteBranding` nhận JSON Blade và cập nhật sau save; `AppBrandLogo` được themeConfig dùng chung, fallback slot của theme. View composer cấp branding khi render `admin`/`layouts.public`; không đọc DB lúc boot.
- Contract và QA: `docs/SETTINGS_BRANDING_API.md`, `docs/qa/SITE_BRANDING_2026-10-06/README.md`, FIX 1 mục 12.42.

- `docs/PLAN.md`: cập nhật trạng thái, checklist và mốc tiến độ.
- `docs/PROJECT_STRUCTURE.md`: cập nhật khi thêm boundary, thư mục kiến trúc,
  convention hoặc luồng dữ liệu mới.
- `docs/ENVIRONMENT.md`: cập nhật khi thêm biến môi trường, service local hoặc
  dependency hạ tầng.
- Khi ba file trên mâu thuẫn, ưu tiên code đang chạy, sau đó cập nhật tài liệu
  trong cùng task và ghi rõ phần còn pending trong `PLAN.md`.
