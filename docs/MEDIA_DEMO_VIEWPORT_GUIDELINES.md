# Media Asset — Viewport UI Guidelines

Tài liệu này ghi lại pattern giao diện viewport của trang demo Media Library để
các lần triển khai sau giữ đúng hành vi layout, panel và scrollbar của project.

## Phạm vi áp dụng

- Page: `resources/js/pages/apps/media/media-asset/index.vue`
- Routes: `/apps/media/media-asset` và `/apps/media/media-asset/folder/:folder`
- Folder slug hợp lệ: `images`, `videos`, `documents`, `trash`; thư mục mặc định
  dùng route gốc.
- Thư mục được lưu trong route để hỗ trợ refresh, bookmark và Back/Forward. File
  đang chọn vẫn là state cục bộ nên panel thông tin không trở thành route riêng.
- Layout page: dùng `layout-content-height-fixed`, tương tự
  `resources/js/pages/apps/email/index.vue`.

## Khung viewport

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

## Ba panel và đường giáp ranh

- Panel thư mục, panel file và panel thông tin đều dùng `h-100`.
- Ranh giới giữa hai panel chỉ được phép có một border:
  - Desktop: panel kế tiếp bỏ `border-inline-start`.
  - Mobile: panel kế tiếp bỏ `border-block-start`.
- Các góc ở đường giáp ranh không bo:
  - Panel giữa dùng `rounded-0`.
  - Panel trái dùng `rounded-e-0`.
  - Panel phải dùng `rounded-s-0`.
  - Mobile có thể bỏ toàn bộ border-radius để các panel xếp liền theo chiều dọc.

## Header, filter và vùng file

- Header thống kê phía trên filter phải có chiều cao ổn định trên desktop:
  `.media-manager__summary` dùng `block-size: 40px` và `flex: 0 0 40px`.
- Dòng hiển thị số lượng file không được wrap (`text-no-wrap`) để filter không
  bị đẩy lên/xuống khi đổi thư mục.
- `.media-manager__filters` là phần tử tĩnh với `position: static` và
  `flex: 0 0 auto`; không dùng `position: sticky`.
- Chỉ vùng `.media-manager__files` được scroll ở desktop. Header, summary,
  filter và pagination nằm ngoài vùng scroll.

## Scrollbar theo chuẩn Email

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

## Responsive mobile

Ở breakpoint dưới `960px`:

- Root chuyển sang `block-size: auto` và `min-block-size: 100%`.
- Hàng cột không còn grow cố định (`flex: 0 0 auto`).
- Vùng file/sidebar trở về chiều cao tự nhiên để scroll của page kéo được tới
  item cuối cùng.
- Không tạo scroll lồng cố định trên mobile nếu nó khiến nội dung cuối bị kẹt.

## Typography và icon

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

## Checklist trước khi thay đổi

- Không đưa filter vào vùng scroll file.
- Không thêm `position: sticky` cho filter nếu mục tiêu là filter đứng yên như
  summary.
- Không thêm shadow riêng cho header.
- Kiểm tra cả desktop và viewport nhỏ hơn `960px`.
- Chạy ESLint page và `npm run build` sau thay đổi layout.
