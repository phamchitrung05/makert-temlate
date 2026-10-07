# Quy ước phát triển dự án

**Cập nhật:** 07/10/2026. Quy ước comment/architecture/dialog, trang Add/Edit và Media viewport được tập trung tại đây.

Đọc cùng [PLAN](../plans/PLAN.md), [cấu trúc dự án](PROJECT_STRUCTURE.md) và [inventory](PROJECT_INVENTORY.md). Giữ thay đổi hiện có trong workspace; backend quyết định dữ liệu/quyền, frontend dùng JavaScript và Vue Composition API.

## 1.1. Chuẩn comment bắt buộc cho developer và AI

Đây là quy định bắt buộc của repository. Mọi developer, code reviewer và AI agent phải đọc và tuân thủ trước khi tạo hoặc chỉnh sửa code.

### Phạm vi áp dụng

- Mỗi class phải có comment mô tả ở ngay phía trên khai báo class.
- Mỗi method/function nằm trong class phải có comment mô tả ở ngay phía trên method.
- Comment phải mô tả đúng behavior hiện tại của code, không mô tả ý định chưa triển khai.
- Khi thay đổi input, output, side effect, exception hoặc transaction boundary, phải cập nhật comment trong cùng pull request.
- Không được xóa comment chỉ để làm diff ngắn hơn.
- Comment của method phải ghi rõ nếu method yêu cầu transaction, lock, authenticated user, policy hoặc quyền cụ thể.
- Comment không thay thế type declaration, Form Request, Policy, test hoặc validation.

### Template comment chuẩn

Class và method phải dùng block comment theo cấu trúc sau. Có thể thay nội dung bên trong cho đúng class/method, nhưng không được bỏ các nhóm thông tin chính.

```php
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tăng state_revision của bàn để đánh dấu projection vừa thay đổi
 * =====================================================================
 *
 * Action phụ trợ được gọi ở cuối mọi transaction nghiệp vụ POS (thêm món, thanh
 * toán, mở bàn, phiếu bếp...) để mỗi lần thay đổi dữ liệu bàn phát ra một
 * revision tăng dần. Event PosStateChanged phát sau commit chỉ cần mang revision
 * này, giúp client biết chính xác trạng thái nào đã được lưu thành công.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(DiningTable $lockedTable): tăng state_revision lên 1 và trả giá trị mới
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : DiningTable đã giữ khóa ghi (lockForUpdate) truyền vào handle() trong transaction của caller
 * - OUTPUT: handle() trả int state_revision mới (refresh từ DB); side effect: UPDATE cột state_revision
 *           của dining_tables; throw ModelNotFoundException không xảy ra ở đây (bàn phải tồn tại sẵn)
 * =====================================================================
 */
final class IncrementDiningTableRevisionAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tăng state_revision của bàn đã được khóa ghi
     * =====================================================================
     *
     * INPUT:
     * - $lockedTable: DiningTable đã được lockForUpdate() trong transaction của caller
     *
     * OUTPUT:
     * - int: state_revision mới sau khi refresh từ database
     *
     * SIDE EFFECT:
     * - UPDATE state_revision của dining_tables
     *
     * EXCEPTION/TRANSACTION:
     * - Không tự mở transaction; caller chịu trách nhiệm transaction và lock
     * =====================================================================
     */
    public function handle(DiningTable $lockedTable): int
    {
        // implementation
    }
}
```

### Quy tắc viết comment

- `CHỨC NĂNG FILE` mô tả trách nhiệm chính của file/class trong một câu.
- Phần mô tả class phải giải thích context nghiệp vụ, nơi class được gọi và lý do tồn tại.
- `CÁC HÀM/METHOD TRONG FILE` phải liệt kê toàn bộ public method, protected method và private method quan trọng.
- Comment method phải ghi `INPUT`, `OUTPUT`, `SIDE EFFECT` và `EXCEPTION/TRANSACTION` khi có liên quan.
- Với query/list method, ghi rõ filter, sort, pagination và quan hệ được eager load.
- Với action/mutation, ghi rõ transaction boundary, lock, event/job được phát và dữ liệu bị thay đổi.
- Với upload/download, ghi rõ disk, collection, authorization, temporary URL và rate limit.
- Với OAuth/authentication, ghi rõ guard, provider, session và identity linking rule.
- Không đưa secret, access token, password hoặc dữ liệu cá nhân thật vào comment.
- Nếu method quá đơn giản nhưng vẫn là method của class, vẫn phải có comment ngắn theo cùng cấu trúc.

### Quy tắc cho AI agent

AI agent chỉ được tạo hoặc sửa class/method sau khi:

1. Đọc phần comment đầu file hiện tại.
2. Giữ nguyên cấu trúc comment và cập nhật nội dung nếu behavior thay đổi.
3. Thêm comment cho class/method mới trước khi viết implementation.
4. Kiểm tra `INPUT/OUTPUT`, side effect, exception và transaction có khớp code thực tế.
5. Báo trong review nếu file cũ chưa có comment chuẩn và task có chạm vào file đó.

Pull request thiếu comment bắt buộc hoặc comment không còn khớp behavior sẽ không đạt Definition of Done.

### Cấu trúc comment bắt buộc cho file Vue

Mọi file `.vue` mới hoặc được chỉnh sửa phải có block comment ở đầu file, đặt trước `<script setup>` hoặc `<template>`. Comment viết bằng tiếng Việt và giữ đủ cấu trúc sau:

```vue
<!--
  =====================================================================
  CHỨC NĂNG FILE: <trách nhiệm chính của component/page>
  =====================================================================

  <Mô tả context nghiệp vụ, nơi component được sử dụng và lý do tồn tại>

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - loadData(): <chức năng>
  - visibleItems: <computed data>
  - watcher route.params.id: <side effect khi route thay đổi>

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : props, route params, store hoặc API data
  - OUTPUT: UI render, emitted events, navigation và side effect
  =====================================================================
-->
<script setup>
```

Quy tắc áp dụng cho Vue:

- Liệt kê props/emits, hàm, computed và watcher quan trọng; nếu không có thì ghi rõ `Không có`.
- Hàm nghiệp vụ cục bộ phải có comment gần khai báo, ghi rõ `INPUT`, `OUTPUT`, `SIDE EFFECT` và `EXCEPTION` khi có liên quan.
- Không cần comment riêng cho import, ref hoặc phép gán hiển nhiên; comment phải giải thích behavior thay vì lặp lại cú pháp.
- Không ghi secret, access token, password hoặc dữ liệu cá nhân thật trong comment.
- File Vue cũ chỉ bắt buộc chuẩn hóa khi task có chỉnh sửa file đó; không mass-edit ngoài phạm vi task.

## 1.2. Chuẩn architecture bắt buộc

### Laravel

- Tuân theo Laravel architecture: Route → Middleware → Form Request → Controller → Action/Service → Repository/Model/Policy → Resource/Response.
- Controller chỉ điều phối HTTP; không chứa business rule dài, query phức tạp hoặc transaction không có tên.
- Validation cổng HTTP nằm trong Form Request, tách `XCreateRequest` và `XUpdateRequest` cho từng resource; rule nghiệp vụ nằm trong `App\Validators` và được repository gọi khi tạo hoặc cập nhật.
- `TaxonomyController` là base chung cho category, tag và technology. Lớp con khai báo `repositoryInterface()`, `createRequestClass()` và `updateRequestClass()`; `resolveRequest()` gọi `validateResolved()` trên FormRequest do container tạo.
- FormRequest bắt buộc phải chặn giá trị enum trước khi dữ liệu chạm vào model, vì `BaseRepository::create()` gọi `forceFill()` trước khi chạy validator của repository.
- Authorization nằm trong Policy, Gate hoặc permission middleware của Spatie. Permission middleware phải truyền guard `admin` vì `config/auth.php` không còn guard `web`; token Sanctum được resolve qua guard `admin` khai báo với driver `sanctum`.
- Business mutation nhiều bước nằm trong Action/Service và ghi rõ transaction boundary.
- Query dùng Eloquent scope/query object khi được tái sử dụng; list endpoint phải eager load quan hệ cần trả về.
- Model giữ relation, cast, scope và invariant cấp model; không biến model thành một service lớn.
- Job dùng cho conversion, scan, email, webhook retry và aggregate; job phải idempotent khi có thể.
- Không truy cập database từ Blade hoặc Vue.
- Migration phải có foreign key, index, rollback và không sửa migration đã chạy ở môi trường dùng chung.

### Chuẩn API response

- Dùng `App\Http\Responses\BaseResponse` làm điểm duy nhất tạo JSON response cho API.
- Success response luôn có `success`, `message`, `data`, `errors` và `meta`.
- Error response giữ cùng envelope; lỗi validation nằm trong `errors` theo từng field.
- Exception của request API/JSON được chuyển qua `BaseResponse::fromException()`; không trả stack trace hoặc message lỗi nội bộ 5xx.
- Response không có body dùng HTTP 204 qua `BaseResponse::noContent()`.
- Danh sách phục vụ `VDataTableServer` dùng `BaseResponse::dataTable()` với
  `data.items` là các dòng và `data.itemsLength` là tổng số dòng; giữ
  `meta.pagination` để client dùng current page, per-page, total và links khi
  cần.
- `BaseResponse::paginated()` vẫn giữ contract `data` là mảng danh sách để
  không phá vỡ các endpoint hiện tại; chỉ chuyển endpoint sang `dataTable()`
  khi frontend đã dùng mapping DataTableServer tương ứng.
- Frontend service unwrap `data` tại boundary; store/component không tự biết chi tiết envelope HTTP.

### Vue 3

- Tất cả module frontend mới dùng Vue 3 Composition API và `<script setup>`.
- Frontend dùng JavaScript theo quyết định của project; không thêm TypeScript vào module mới.
- Page là composition surface; tách form, table, filter, dialog và feature panel thành component nhỏ.
- State server dùng Pinia/composable; state dẫn xuất dùng `computed`; watcher chỉ dùng cho side effect.
- Props đi xuống, events đi lên; `v-model` chỉ dùng cho contract hai chiều rõ ràng.
- API client và mapping response nằm trong `services/` hoặc composable; không gọi API rải rác trong template.
- Backend Laravel là source of truth cho auth, permission, validation và entitlement.
- CASL tạm thời chưa được áp dụng trong Vue; source và dependency được giữ lại để triển khai authorization UI ở giai đoạn sau.
- Mọi task Vue phải có loading, empty, error và retry state phù hợp.

### Chuẩn cấu trúc dialog (bắt buộc)

- Lấy `AppDialogLayout` làm chuẩn: header và footer cố định,
  **chỉ content ở giữa được scroll**. Mọi dialog của ứng dụng, kể cả xác nhận,
  cảnh báo, xem chi tiết và dialog dùng chung, đều phải có đủ ba phần.
- Dùng `VDialog scrollable` + `components/dialogs/AppDialogLayout.vue`.
  Header dùng `title`/`subtitle` hoặc `#header`; nội dung ở default slot;
  Hủy/Đóng và thao tác chính ở `#footer` (dialog chỉ xem có footer Đóng mặc định).
- Nút X dùng `DialogCloseBtn`, nổi ngoài góc trên bên phải theo mẫu Vuexy.
  Layout đặt nút cạnh card để không bị cắt bởi `overflow: hidden`; không đặt
  X trong header/content hoặc tự thêm nút X ở từng feature.
- Không đặt action của dialog ở cuối form dài trong content; không cuộn toàn
  `VCard`/`VForm`; giữ validation/Enter và guard khi chuyển submit ra footer.
- Loading hiện đủ field ngay khi mở, khóa nhập/Lưu và có vòng xoay qua
  `#overlay`; lớp loading phải nằm trên toolbar editor. Lỗi tải giữ form khóa
  và có thao tác tải lại. Khi đóng chỉ dọn dữ liệu sau `after-leave`, bỏ qua
  event đóng cũ nếu đã mở lại. Đường đóng khi lưu và cảnh báo bản chưa lưu do feature xử lý.
- File mới đặt trong `views/<feature>/dialog/*Dialog.vue`; dialog/layout dùng
  chung nằm trong `components/dialogs/`. Demo Vuetify trong `views/demos/` là
  reference; khi tái sử dụng trong ứng dụng phải theo chuẩn này.
- Quy tắc layout, trường hợp picker nhiều panel, mẫu code và acceptance bắt
  buộc: [PROJECT_STRUCTURE.md — Cấu trúc dialog](PROJECT_STRUCTURE.md#431-cấu-trúc-dialog-bắt-buộc).

## 2. Chuẩn trang Add/Edit Admin

### Canonical admin Add/Edit page structure

When creating or restyling an admin Add/Edit page, use
`resources/js/pages/apps/ecommerce/product/add/index.vue` as the visual source of
truth. New forms should look like this page unless the user explicitly provides a
different design.

#### Page and component responsibilities

- Keep the route page thin: read route parameters, connect the Pinia store, call
  create/update actions, and navigate after success.
- Put form state and submit validation in a feature form component.
- Split substantial content, media, and settings sections into focused child
  components. Use props down and events or named `v-model` bindings up.
- Only include backend-supported fields in the submitted payload. An editable
  preview-only field must be clearly marked `Planned` until persistence exists.
- Every touched `.vue` file must retain the Vietnamese structured header comment
  that describes its purpose, important functions, and input/output contract.

#### Header

Use the same hierarchy and spacing as Product Add:

```vue
<div class="d-flex flex-wrap justify-start justify-sm-space-between gap-y-4 gap-x-6 mb-6">
  <div class="d-flex flex-column justify-center">
    <h4 class="text-h4 font-weight-medium">
      Page title
    </h4>
    <div class="text-body-1">
      Short page description
    </div>
  </div>

  <div class="d-flex gap-4 align-center flex-wrap">
    <!-- Discard, Save Draft, Publish -->
  </div>
</div>
```

#### Main layout

- Use `VRow` with a primary `VCol md="8"` and a settings `VCol md="4"`.
- Add `cols="12"` when an explicit mobile width improves readability.
- Use Vuexy/Vuetify spacing utilities; the standard gap between cards is `mb-6`.

#### Cards

Use the same default `VCard` appearance as Product Add. Do not add custom
`rounded="xl"`, `elevation="0"`, `border`, title icons, or forced card-title
colors unless the requested design specifically requires them.

Simple card:

```vue
<VCard
  title="Section title"
  class="mb-6"
>
  <VCardText>
    <!-- fields -->
  </VCardText>
</VCard>
```

Card with an action, badge, or secondary control in the header:

```vue
<VCard class="mb-6">
  <VCardItem>
    <template #title>
      Section title
    </template>
    <template #append>
      <!-- action, badge, or control -->
    </template>
  </VCardItem>

  <VCardText>
    <!-- fields -->
  </VCardText>
</VCard>
```

Use `AppCardActions` only when collapse, refresh, or remove behavior is a real
requirement. Do not use it as the default form card.

#### Typography and form controls

- Let the Vuexy theme provide card-title and label colors and font sizes. Avoid
  page-specific CSS that forces labels or titles to black; this must continue to
  work in dark mode.
- Prefer `AppTextField`, `AppTextarea`, `AppSelect`, `AppCombobox`, and
  `AppDateTimePicker` over their raw equivalents when the wrapper exists.
- Put labels on the App field through its `label` prop. Use `VLabel` only for a
  composed control that cannot receive a normal label.
- Use `ProductDescriptionEditor` with `class="border rounded"` for rich-text
  content when matching the Product Add experience.
- Use `MediaAssetField` for domain media selection. It is the real Media Library
  integration and should not be replaced by the demo `DropZone`.

#### Verification checklist

- Run focused ESLint for every touched Vue file.
- Run `npm run test:run`.
- Run `npm run build`.
- Confirm the page remains responsive at the `md` breakpoint and that labels,
  cards, and editor styling match Product Add in both supported themes.

## 3. Media Asset — viewport và scrollbar

Tài liệu này ghi lại pattern giao diện viewport của trang demo Media Library để
các lần triển khai sau giữ đúng hành vi layout, panel và scrollbar của project.

### Phạm vi áp dụng

- Page: `resources/js/pages/apps/media/media-asset/index.vue`
- Routes: `/apps/media/media-asset` và `/apps/media/media-asset/folder/:folder`
- Folder slug hợp lệ: `images`, `videos`, `documents`, `trash`; thư mục mặc định
  dùng route gốc.
- Thư mục được lưu trong route để hỗ trợ refresh, bookmark và Back/Forward. File
  đang chọn vẫn là state cục bộ nên panel thông tin không trở thành route riêng.
- Layout page: dùng `layout-content-height-fixed`, tương tự
  `resources/js/pages/apps/email/index.vue`.

### Khung viewport

- Root dùng `VContainer` với `id="view-moi"`, `fluid`,
  `media-manager d-flex flex-column` và `block-size: 100%`.
- Hàng ba cột dùng:

  ```vue
  <VRow
    class="media-manager__columns flex-grow-1"
    align="stretch"
    no-gutters
  >
  ```

- `no-gutters` để các panel chạm liền nhau, không tạo khoảng hở giữa cột.
- Chỉ `.media-manager__columns` dùng elevation chung; header bên trên không có
  bóng và không đặt elevation riêng cho từng panel con.

### Ba panel và đường giáp ranh

- Panel thư mục, panel file và panel thông tin đều dùng `h-100`.
- Ranh giới giữa hai panel chỉ được phép có một border:
  - Desktop: panel kế tiếp bỏ `border-inline-start`.
  - Mobile: panel kế tiếp bỏ `border-block-start`.
- Các góc ở đường giáp ranh không bo:
  - Panel giữa dùng `rounded-0`.
  - Panel trái dùng `rounded-e-0`.
  - Panel phải dùng `rounded-s-0`.
  - Mobile có thể bỏ toàn bộ border-radius để các panel xếp liền theo chiều dọc.

### Header, filter và vùng file

- Header thống kê phía trên filter phải có chiều cao ổn định trên desktop:
  `.media-manager__summary` dùng `block-size: 40px` và `flex: 0 0 40px`.
- Dòng hiển thị số lượng file không được wrap (`text-no-wrap`) để filter không
  bị đẩy lên/xuống khi đổi thư mục.
- `.media-manager__filters` là phần tử tĩnh với `position: static` và
  `flex: 0 0 auto`; không dùng `position: sticky`.
- Chỉ vùng `.media-manager__files` được scroll ở desktop. Header, summary,
  filter và pagination nằm ngoài vùng scroll.

### Scrollbar theo chuẩn Email

Dùng `PerfectScrollbar` giống `email/index`, không mô phỏng bằng scrollbar native
cho các vùng file/sidebar:

```vue
<PerfectScrollbar
  class="media-manager__files flex-grow-1"
  :options="{ wheelPropagation: false, suppressScrollX: true }"
>
  ...
</PerfectScrollbar>
```

Áp dụng tương tự cho `.media-manager__sidebar-list`.

- Style global của project tại `resources/styles/@core/base/libs/_perfect-scrollbar.scss`
  đã tạo thumb mảnh và overlay trên item.
- Luôn dùng `suppressScrollX: true` để không phát sinh scrollbar ngang khi
  scrollbar dọc xuất hiện.
- Không dùng `scrollbar-gutter: stable` cho vùng PerfectScrollbar vì có thể làm
  nội dung item bị co và tạo cảm giác xuất hiện scrollbar ngang.
- Sau khi filter, folder, pagination hoặc view mode thay đổi, gọi
  `ps.update()` sau `nextTick()` để scrollbar chỉ hiện khi nội dung thực sự
  overflow:

  ```js
  const filesScrollbar = useTemplateRef('filesScrollbar')

  const updateMediaScrollbars = async () => {
    await nextTick()
    filesScrollbar.value?.ps?.update()
  }
  ```

### Responsive mobile

Ở breakpoint dưới `960px`:

- Root chuyển sang `block-size: auto` và `min-block-size: 100%`.
- Hàng cột không còn grow cố định (`flex: 0 0 auto`).
- Vùng file/sidebar trở về chiều cao tự nhiên để scroll của page kéo được tới
  item cuối cùng.
- Không tạo scroll lồng cố định trên mobile nếu nó khiến nội dung cuối bị kẹt.

### Typography và icon

- Tiêu đề `Thư mục`, `Tất cả tệp`, `Thông tin tệp` dùng `text-subtitle-1`
  và `font-weight-bold`.
- Icon sidebar dùng Tabler: `tabler-folder`, `tabler-photo`, `tabler-video`,
  `tabler-file-text`, `tabler-trash`.
- Toolbar dùng `tabler-search`, `tabler-layout-grid` và `tabler-list`; không dùng
  `mdi-*` cho các nút tìm kiếm hoặc chuyển đổi grid/list.
- Thumbnail JPG/PNG dùng `cover`; thumbnail icon định dạng dùng `contain` để
  giữ trọn hình icon trong card và panel thông tin.
- Không dùng các alias MDI chưa được project đăng ký nếu Tabler tương đương đã
  có sẵn.

### Checklist trước khi thay đổi

- Không đưa filter vào vùng scroll file.
- Không thêm `position: sticky` cho filter nếu mục tiêu là filter đứng yên như
  summary.
- Không thêm shadow riêng cho header.
- Kiểm tra cả desktop và viewport nhỏ hơn `960px`.
- Chạy ESLint page và `npm run build` sau thay đổi layout.
