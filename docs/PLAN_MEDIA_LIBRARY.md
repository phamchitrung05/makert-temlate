# Kế hoạch triển khai Media Library trung tâm

**Phiên bản:** 1.0
**Ngày tạo:** 2026-09-27
**Trạng thái:** `IN PROGRESS`
**Tài liệu roadmap tổng:** [PLAN.md](./PLAN.md)
**Tài liệu cấu trúc:** [PROJECT_STRUCTURE.md](./PROJECT_STRUCTURE.md)

## 1. Mục tiêu

Xây dựng một Media Library dùng chung cho ảnh, tài liệu, archive và video. Admin
có thể upload, xem, lọc và chọn lại asset khi đang tạo Post, Resource hoặc
Resource Version; các domain không phải tự xây một cơ chế upload/chọn file riêng.

Media Library phải giữ đúng hai boundary:

```text
MediaAsset (nghiệp vụ sở hữu file)
└── Spatie Media (file vật lý trong bảng media)
        │
        └── media_asset_usages (asset được dùng ở model/field nào)
```

`media_asset_usages` là bảng liên kết nghiệp vụ mở rộng, không thay thế bảng
`media` của Spatie và không phải pivot nội bộ của Spatie Media Library.

## 2. Nguyên tắc đã chốt

- Spatie Media Library tiếp tục quản lý file vật lý, disk, conversion và bảng
  `media`.
- `MediaAsset` là owner nghiệp vụ trung tâm, implement `HasMedia` và dùng
  `InteractsWithMedia` với collection `library`.
- Không thêm `media_id` trực tiếp vào `media_assets`; Spatie liên kết bằng
  `media.model_type = App\\Models\\MediaAsset` và `media.model_id`.
- Model nghiệp vụ (Post, Resource, ResourceVersion...) liên kết tới
  `MediaAsset` qua `media_asset_usages`.
- `field` biểu diễn ngữ cảnh sử dụng, ví dụ `post.thumbnail`,
  `resource.cover`, `resource_version.package`.
- Bộ lọc `kind`/`field` phải được kiểm tra ở backend; frontend chỉ cải thiện trải
  nghiệm picker, không phải ranh giới bảo mật.
- Ảnh có thể dùng public disk và conversion; package/archive luôn private, có
  checksum và scan trước khi sẵn sàng.
- API trả response theo `BaseResponse`; service frontend unwrap response ở
  boundary; Pinia không chứa logic HTTP rải rác trong component.
- Code PHP/Vue mới phải tuân thủ comment convention trong `PLAN.md`.

## 3. Phân loại asset và field

| `kind` | Ví dụ | Disk mặc định | Quy trình đặc biệt |
|---|---|---|---|
| `image` | thumbnail, cover, gallery | public | conversion `thumb`, `web` |
| `document` | PDF hướng dẫn, documentation | private hoặc public theo policy | MIME/size validation |
| `archive` | ZIP source/package | private | checksum, archive scan, retry |
| `video` | preview video | private hoặc public theo policy | size/codec validation, xử lý async |

Field picker dự kiến:

| Field | `kind` | Multiple |
|---|---|---:|
| `post.thumbnail` | `image` | Không |
| `post.content_images` | `image` | Có |
| `resource.cover` | `image` | Không |
| `resource.preview` | `image` | Có |
| `resource_version.package` | `archive` | Không |
| `resource_version.documentation` | `document` | Có |

## 4. Schema và contract

### 4.1. `media_assets`

Các trường nghiệp vụ tối thiểu:

```text
id
kind                 image|document|archive|video
title
alt_text             nullable, dùng cho asset ảnh
visibility           public|private
created_by            nullable FK tới users
deleted_at
created_at
updated_at
```

Metadata xử lý file được lưu trên custom properties của Spatie Media:

```text
checksum_sha256
scan_status           pending|clean|rejected|error
conversion_status     pending|processing|ready|failed
upload_metadata       original_name, mime_type, size, uploaded_by
```

`scan_status` dùng enum `MediaScanStatus`; `conversion_status` dùng enum
`MediaConversionStatus`. Allowlist MIME/extension, blocklist và giới hạn
archive nằm trong `config/media-assets.php`, không hard-code trong controller.

### 4.2. `media_asset_usages`

Đây là polymorphic usage table mở rộng:

```text
id
media_asset_id        FK tới media_assets
linkable_type         morph map của model sử dụng
linkable_id
field                 thumbnail|cover|preview|package|...
sort_order            nullable, dùng cho gallery/content images
created_at
updated_at
```

Bắt buộc có index `(linkable_type, linkable_id, field)` và index cho
`media_asset_id`. Field đơn nên có unique rule tương ứng; field nhiều asset dùng
`sort_order` và không được tạo bản ghi trùng cùng asset/field nếu nghiệp vụ không
cho phép.

### 4.3. API và policy contract

Các endpoint admin dự kiến dùng chung một envelope `BaseResponse`:

| Method | Endpoint | Permission | Mục đích |
|---|---|---|---|
| `GET` | `/api/admin/media-assets` | `media.view` | List/filter asset theo kind, field, visibility, scan status |
| `POST` | `/api/admin/media-assets` | `media.upload` | Upload temporary và tạo asset |
| `PATCH` | `/api/admin/media-assets/{mediaAsset}` | `media.upload` | Cập nhật title/alt_text/visibility theo policy |
| `POST` | `/api/admin/media-assets/{mediaAsset}/usages` | `media.attach` | Attach asset vào model/field |
| `DELETE` | `/api/admin/media-assets/{mediaAsset}/usages/{usage}` | `media.attach` | Detach usage |
| `DELETE` | `/api/admin/media-assets/{mediaAsset}` | `media.delete` | Soft-delete asset chưa bị khóa bởi usage nghiệp vụ |
| `POST` | `/api/admin/media-assets/{mediaAsset}/retry` | `media.retry` | Retry scan/conversion thất bại |
| `GET` | `/api/admin/media-assets/{mediaAsset}/download` | `media.view` | Stream/temporary URL sau policy check |

Policy tối thiểu:

| Actor | Public asset | Private asset | Upload/attach/delete/retry |
|---|---|---|---|
| Guest/customer | Chỉ qua public catalog nếu domain cho phép | Không | Không |
| `support` | Xem admin asset | Không mặc định | Không |
| `editor` | Xem | Xem asset admin được cấp | Upload + attach |
| `admin` | Xem | Xem | Toàn bộ media permission |
| `super-admin` | Xem | Xem | Bypass theo Spatie role/policy |

Private asset không trả storage path hoặc URL lâu hạn; download phải qua policy
và temporary URL/stream có thời hạn ngắn.

### 4.4. Modal picker contract

```vue
<MediaLibraryDialog
  v-model:open="isOpen"
  kind="image"
  field="resource.cover"
  :multiple="false"
  @select="onSelect"
/>
```

API phải nhận và kiểm tra tối thiểu: `kind`, `field`, `multiple`, search, page,
per-page, sort và visibility. Khi attach, backend kiểm tra asset có đúng loại,
quyền và field hay không.

## 5. Danh sách task và dependency

### Task 1 — Chốt domain contract

**Status:** `DONE`
**Phụ thuộc:** Không có

- [x] Chốt enum `MediaAssetKind` và `MediaAssetVisibility` trong `app/Enums`.
- [x] Chốt danh sách field được phép và rule single/multiple bằng
  `MediaAssetField`.
- [x] Chốt morph map cho `resource`, `resource_version`, `media_asset` và
  `post`.
- [x] Chốt policy theo actor và permission `media.view/upload/attach/delete/retry`.
- [x] Ghi contract upload, list, attach, detach và temporary download URL.

**Kết quả:** contract được ghi trong test/DTO hoặc tài liệu domain; các task sau
không tự định nghĩa lại field hoặc permission.

### Task 2 — Tạo `MediaAsset` domain

**Status:** `DONE`
**Phụ thuộc:** Task 1

- [x] Tạo `app/Models/MediaAsset.php` với comment convention đầy đủ.
- [x] Implement `HasMedia`, `InteractsWithMedia`, collection `library` và disk
  mapping theo `kind`/`visibility`.
- [x] Tạo migration `media_assets` với soft delete, FK/index và enum validation
  phù hợp MySQL project.
- [x] Tạo factory/seeder tối thiểu cho image, document và archive.
- [x] Thêm policy và activity log cho create/update/delete/attach.
- [x] Viết model/feature test cho collection, visibility và soft delete.

**Kết quả:** asset có thể tồn tại độc lập và file vật lý vẫn do Spatie quản lý.

### Task 3 — Tạo usage relation

**Status:** `DONE`
**Phụ thuộc:** Task 1, Task 2

- [x] Tạo migration `media_asset_usages` với FK, morph index và unique/index rule.
- [x] Tạo `MediaAssetUsage` model, factory và relation hai chiều.
- [x] Thêm morph relation/trait dùng lại cho `Post`, `Resource`,
  `ResourceVersion` khi domain đó sẵn sàng.
- [x] Tạo service/action attach, detach, reorder và replace single-field asset.
- [x] Bọc mutation nhiều bảng trong transaction và ghi activity log.
- [x] Test không cho attach sai `kind`, sai field, asset đã bị xóa hoặc không có
  quyền truy cập.

**Kết quả:** một model có thể dùng lại asset; gallery có thứ tự ổn định.

### Task 4 — Upload validation và security pipeline

**Status:** `DONE`
**Phụ thuộc:** Task 2

- [x] Tạo FormRequest riêng cho upload và metadata update.
- [x] Validate MIME, extension, kích thước, tên file và `kind`/field tương ứng.
- [x] Chặn path traversal, symlink, executable và archive vượt giới hạn.
- [x] Upload vào temporary/private location trước khi attach chính thức.
- [x] Tính SHA-256; lưu upload metadata và scan status trên custom properties.
- [x] Với archive: preflight scan, chỉ chuyển `clean` khi scan sạch và có
  retry/backoff khi job kỹ thuật thất bại.
- [x] Với image: dispatch conversion queue, không chờ conversion nặng trong HTTP.

**Kết quả:** file không hợp lệ không thể trở thành asset usable; package không
bao giờ bị expose thành public URL trực tiếp.

### Task 5 — Media API và authorization

**Status:** `DONE`
**Phụ thuộc:** Task 2, Task 3, Task 4

- [x] Tạo controller/request/resource cho list, detail, upload, update metadata,
  attach, detach, reorder và delete/restore nếu cần.
- [x] Hỗ trợ filter server-side theo `kind`, `field`, `visibility`, search,
  owner, scan status và pagination/sort.
- [x] Dùng `BaseResponse` và `JsonResource`; không trả path nội bộ hoặc package
  URL lâu hạn.
- [x] Cấp temporary URL/stream private sau khi policy kiểm tra; archive chỉ
  được download khi `scan_status=clean`.
- [x] Thêm permission: `media.view`, `media.upload`, `media.attach`,
  `media.delete`, `media.retry`.
- [x] Test 401/403/404/422, pagination, filter và private download.

**Kết quả:** backend API là source of truth cho picker và upload workflow.

### Task 6 — Frontend service và Pinia store

**Status:** `DONE`
**Phụ thuộc:** Task 5

- [x] Tạo `resources/js/services/mediaAsset.js` để gọi API và unwrap envelope.
- [x] Tạo `resources/js/stores/mediaAsset.js` quản lý list, selected asset,
  filters, pagination, upload progress, loading, error và retry.
- [x] Giữ search/filter tạm thời ở page URL khi cần bookmark/refresh.
- [x] Không đưa HTTP call vào template/component con.
- [x] Thêm fake API dataset/handler với response giống Laravel thật.
- [x] Tạo màn hình admin `Media > File` theo file-based router và navigation
  dọc/ngang của project.
- [x] Bổ sung bảng server-side, filter, upload progress, detail, retry,
  download và delete state cho màn hình File.

**Kết quả:** UI Media > File có thể chuyển fake → thật tại service boundary mà
không đổi contract table/picker; giao diện picker nhúng vào Resource/Post vẫn là
phạm vi Task 7–8.

### Task 7 — Media Picker UI

**Status:** `DONE`
**Phụ thuộc:** Task 6

Tạo các component theo cấu trúc Vue hiện tại:

```text
resources/js/views/apps/media/
├── MediaLibraryDialog.vue
├── MediaAssetGrid.vue
├── MediaUploadDropZone.vue
└── MediaAssetDetails.vue
```

- [x] Dialog nhận `kind`, `field`, `multiple`, `visibility` và emit `select`.
- [x] Grid có loading, empty, error, retry, pagination và filter.
- [x] Upload hiển thị progress, scan/conversion status và lỗi theo asset.
- [x] Không hiển thị archive trong picker `kind=image`.
- [x] Chặn thao tác attach ở UI nếu user không có capability, nhưng vẫn dựa vào
  backend để authorize.
- [x] Chạy ESLint/build và kiểm tra keyboard/focus trong dialog.

**Kết quả:** Picker dùng chung nằm trong `resources/js/views/apps/media/field/`.
`MediaAssetField` là custom field wrapper cho form nghiệp vụ; `MediaLibraryDialog`
điều phối list/upload/selection, còn grid và drop-zone là component trình bày.
Task 8 gắn field này vào Resource, Post và Resource Version để gọi API attach.

### Bổ sung — Media Demo UI sandbox

**Status:** `DONE`
**Phụ thuộc:** Task 6, Task 7

Đã bổ sung route và navigation `Media > Demo` tại `/apps/media/demo` để duyệt
giao diện Media Library trước khi áp dụng vào màn hình chính:

- [x] Dùng layout `layout-content-height-fixed` và pattern viewport giống
  `email/index`.
- [x] Dựng ba panel thư mục, danh sách file/filter và thông tin file với
  border liền mạch, không double border ở ranh giới.
- [x] Giữ header/filter cố định; chỉ vùng item file scroll trên desktop.
- [x] Dùng `PerfectScrollbar` overlay giống Email, mảnh và chặn scroll ngang;
  gọi `ps.update()` sau khi filter/pagination/view mode thay đổi.
- [x] Responsive mobile cho phép page scroll tới item cuối cùng.
- [x] Bổ sung 50 file mẫu local để kiểm tra grid/list, pagination và scroll.
- [x] Chuẩn hóa Tabler icon, typography, elevation và alignment theo project.

Chi tiết pattern layout/scroll được ghi tại
[`MEDIA_DEMO_VIEWPORT_GUIDELINES.md`](./MEDIA_DEMO_VIEWPORT_GUIDELINES.md).

### Task 8 — Tích hợp Resource, Post và Resource Version

**Status:** `DONE`
**Phụ thuộc:** Task 3, Task 7 và domain tương ứng đã có

- [x] Resource cover dùng `resource.cover` và chỉ nhận image single.
- [x] Resource preview dùng `resource.preview` và nhận image multiple.
- [x] Resource Version package dùng `resource_version.package`, archive private,
  scan clean mới cho phép version ready.
- [x] Resource Version documentation dùng `resource_version.documentation` và
  filter document.
- [x] Post thumbnail/content images dùng field riêng, không dùng lại rule của
  Resource.
- [x] Khi update/replace/delete model, usage cũ được detach đúng transaction.

### Task 9 — Fake API và frontend tests

**Status:** `DONE`
**Phụ thuộc:** Task 6, Task 7, Task 8

**Kết quả phiên 2026-09-28:** đã thiết lập Vitest + Vue Test Utils + happy-dom,
script `test:run` và test contract Fake API; các acceptance/security test của
Task 10 đã hoàn tất trong cùng phiên.

- [x] Test service mapping, filter và pagination.
- [x] Test store loading/error/retry/upload progress.
- [x] Test picker single/multiple, filter theo kind/field và emit selection.
- [x] Test Resource form attach cover/preview không làm mất state khi API lỗi.
- [x] Test fake API giữ cùng response contract với Laravel.

### Task 10 — Backend acceptance và security tests

**Status:** `DONE`
**Phụ thuộc:** Task 4, Task 5, Task 8

**Kết quả phiên 2026-09-28:** đã bổ sung acceptance/security test cho upload,
private download, archive limit/symlink, queue failure và retry idempotency.
Toàn bộ backend/frontend quality gate đã pass.

- [x] Upload ảnh hợp lệ tạo MediaAsset và conversion job.
- [x] Upload archive hợp lệ giữ private disk và tạo checksum.
- [x] File executable, MIME giả, path traversal, symlink và archive quá giới hạn
  bị từ chối.
- [x] User không đủ permission không list private asset, attach hoặc download.
- [x] Attach sai kind/field trả 422; asset bị soft-delete không thể attach mới.
- [x] Queue failure lưu trạng thái lỗi và retry được; retry idempotent.
- [x] Chạy `php artisan test`, `npm run test:run`, `npm run build`, ESLint, Pint
  và `git diff --check`.

## 6. Thứ tự triển khai theo phiên Codex

1. Phiên 1: Task 1 và schema/model cơ bản của Task 2.
2. Phiên 2: hoàn tất Task 2, làm Task 3 và test relation.
3. Phiên 3: Task 4 (upload, checksum, scan, conversion queue).
4. Phiên 4: Task 5 (API, policy, permission và private URL).
5. Phiên 5: Task 6 và Task 7 (service/store/picker, fake API).
6. Phiên 6: Task 8 tích hợp Resource/Resource Version/Post.
7. Phiên 7: Task 9 và Task 10, sửa toàn bộ acceptance failure.

Không bắt đầu Task 7 chỉ để mô phỏng UI nếu Task 5 chưa chốt response contract;
UI có thể dựng skeleton, nhưng không đánh dấu tích hợp hoàn thành trước backend.

## 7. Definition of Done

- [ ] `MediaAsset` là owner nghiệp vụ duy nhất của file trong library.
- [ ] Spatie `media` vẫn là bảng quản lý file vật lý; không tạo `mediables`.
- [ ] `media_asset_usages` attach được model/field, có index và transaction.
- [x] Picker lọc đúng `kind`/field ở backend và hỗ trợ single/multiple.
- [x] Ảnh có conversion; archive/package private, có checksum và scan status.
- [x] Không có file nguy hiểm, path traversal hoặc archive vượt giới hạn lọt qua.
- [x] Queue failure có trạng thái lỗi và retry idempotent.
- [x] API, fake API, service, store và component dùng cùng response contract.
- [x] Resource, Post và Resource Version dùng chung picker nhưng giữ rule field
  riêng.
- [x] Test backend/frontend, build, lint và tài liệu đều đạt.

## 8. Tài liệu cần cập nhật khi hoàn thành

- [x] Cập nhật trạng thái từng task trong file này.
- [x] Cập nhật Đợt 3 và mốc gần nhất trong [PLAN.md](./PLAN.md).
- [x] Cập nhật bản đồ thư mục, API và flow trong
  [PROJECT_STRUCTURE.md](./PROJECT_STRUCTURE.md).
- [x] Nếu thêm disk/queue/env, cập nhật [ENVIRONMENT.md](./ENVIRONMENT.md).
