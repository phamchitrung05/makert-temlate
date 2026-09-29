# Handoff phiên làm việc — Media Library

**Ngày cập nhật:** 2026-09-29 (sau khi hoàn thiện Media Asset UI)
**Trạng thái:** Task 10 đã hoàn tất; không còn checklist acceptance/security
nào đang chờ xử lý trong [`PLAN_MEDIA_LIBRARY.md`](./PLAN_MEDIA_LIBRARY.md).

Quy ước viewport, panel và PerfectScrollbar của trang demo được ghi tại
[`MEDIA_DEMO_VIEWPORT_GUIDELINES.md`](./MEDIA_DEMO_VIEWPORT_GUIDELINES.md); đọc
tài liệu này trước khi tiếp tục chỉnh giao diện `/apps/media/media-asset`.

## Cập nhật sáng 2026-09-29

- [x] Hoàn tất điều hướng thư mục Media Asset bằng route động; query giữ search,
  sort, pagination và view mode, còn file đang chọn vẫn giữ ở state cục bộ.
- [x] Thêm thumbnail icon theo định dạng cho PDF, ZIP, SVG, DOCX, XLSX, PPTX,
  MP3, TXT và CSV; ảnh JPG/PNG tiếp tục dùng thumbnail ảnh thật.
- [x] Dùng `contain` cho icon định dạng để không bị cắt trong card và panel chi
  tiết; dùng `cover` cho thumbnail ảnh.
- [x] Chuẩn hóa icon toolbar tìm kiếm, grid/list về bộ Tabler:
  `tabler-search`, `tabler-layout-grid`, `tabler-list`.
- [x] Đổi tên page và navigation từ Demo thành Media Asset; route mới là
  `/apps/media/media-asset`, còn URL Demo cũ vẫn redirect để không hỏng bookmark.
- [x] Kiểm chứng bằng ESLint, Vitest (14 tests), production build và
  `git diff --check`.

## Bổ sung Media Asset UI — đã hoàn thành

- Route/navigation `Media > Media Asset` tại `/apps/media/media-asset` đã sẵn sàng để duyệt
  giao diện trước khi áp dụng vào màn hình chính.
- Sidebar thư mục của Media Asset dùng route động `/apps/media/media-asset/folder/:folder`;
  search, sort, pagination và view mode được giữ trong query, còn file đang chọn
  và panel thông tin vẫn giữ ở state cục bộ.
- Demo có ba panel viewport, border liền mạch, elevation chung trên
  `.media-manager__columns`, header/filter cố định và vùng file scroll riêng.
- Sidebar và danh sách file dùng `PerfectScrollbar` overlay giống Email, có
  `suppressScrollX` và cập nhật rail sau mỗi lần filter/pagination/view mode đổi.
- Mobile chuyển về page scroll tự nhiên để kéo tới item cuối; demo có thêm 50
  file local để kiểm tra scroll và pagination.
- Chi tiết quy ước nằm trong
  [`MEDIA_DEMO_VIEWPORT_GUIDELINES.md`](./MEDIA_DEMO_VIEWPORT_GUIDELINES.md).

## Ghi chú bàn giao sau Task 9 — không triển khai trùng

- Task 8 đã hoàn tất và đã được đánh dấu `DONE` trong
  `docs/PLAN_MEDIA_LIBRARY.md`.
- Task 9 và Task 10 đã hoàn tất và đã được đánh dấu `DONE`.
- Task 10 đã bổ sung acceptance/security test cho upload image/archive,
  executable/MIME giả/path traversal/symlink/archive limit, permission/private
  download, queue failure và retry idempotency.
- Các thay đổi Task 1–10 đã được giữ nguyên trong worktree; không reset hoặc
  xoá thay đổi trước đó.

## Trạng thái đã hoàn thành

Task 1 đến Task 7 đã `DONE`:

1. Domain contract và enum.
2. `MediaAsset` domain, Spatie Media Library, disk public/private.
3. `media_asset_usages`, attach/detach/reorder/replace service.
4. Upload validation, checksum, archive scan, conversion queue và retry.
5. Media API, authorization, filter/pagination và private download.
6. Frontend service/store, fake API và màn hình admin `Media > File`.
7. Media Picker UI và custom field dùng chung.

Task 8 đã `DONE`: Resource, Resource Version và Post dùng chung picker nhưng
giữ field/kind/cardinality riêng; action đồng bộ usage trong transaction và
detach usage cũ khi replace/delete.

Task 5 đã có các endpoint dưới `/api/admin/media-assets`:

- `GET` list/filter/pagination.
- `POST` upload.
- `GET` detail.
- `PATCH` metadata/visibility.
- `POST` attach usage.
- `DELETE` detach usage.
- `POST` reorder usage.
- `DELETE` soft-delete asset.
- `POST` retry scan/conversion.
- `GET` download public/private.

Archive/package chỉ được download khi `scan_status=clean`; package luôn nằm ở
private disk. Private asset yêu cầu policy và permission phù hợp.

## Kiểm chứng gần nhất

```text
php artisan test
79 tests passed, 417 assertions

Pint các file controller/test liên quan Task 10: PASS
ESLint toàn bộ resources/js: PASS
npm run test:run: 5 test files, 14 tests passed
npm run build: PASS
git diff --check: PASS
```

Pint đã pass trên toàn bộ file PHP liên quan Task 10. Không format hàng loạt các
file PHP cũ ngoài phạm vi nếu chưa có yêu cầu riêng.

Frontend Task 6–9 đã chạy ESLint toàn bộ `resources/js`, Vitest và `npm run build`
thành công. Build vẫn in cảnh báo asset PNG cũ được resolve lúc runtime.

Môi trường đã được chuẩn bị bằng `.env` và `composer install`; package
`prettus/l5-repository` đã có trong `vendor`, nên full backend suite đã chạy
pass.

## Task 8 — Đã hoàn thành

Đã triển khai:

- Resource: `resource.cover` image single và `resource.preview` image multiple.
- Resource Version: package archive private, scan clean gate; documentation
  document multiple.
- Post: thumbnail image single và content images image multiple.
- CRUD API/action cho Resource Version và Post, fake API và navigation tương ứng.
- `MediaTask8IntegrationTest` bao phủ replace/delete/rollback, scan gate và
  field-kind validation.

Đã kiểm chứng: `php artisan test` (73 tests/395 assertions), Pint các file Task
8, ESLint frontend và `npm run build` đều đạt. Build còn cảnh báo PNG cũ được
resolve lúc runtime.

## Task 9 — Đã hoàn thành

Đã thêm Vitest 3, Vue Test Utils, happy-dom, `@pinia/testing` và script
`npm run test:run`. Bộ test frontend hiện có 14 test bao phủ service mapping,
store loading/error/retry/upload progress, picker single/multiple và field-kind,
Resource Form giữ media state khi API lỗi, cùng response envelope/pagination của
Fake API.

Kiểm chứng: `npm run test:run` — 5 test files, 14 tests passed.

## Việc tiếp theo

Task 10 đã hoàn tất. Khi mở rộng Media Library, giữ nguyên các contract đã được
kiểm chứng và chạy lại toàn bộ quality gate sau mỗi thay đổi liên quan upload,
download private hoặc queue retry.

## Quy trình bắt đầu phiên mới

```powershell
Get-Content docs/PLAN_MEDIA_LIBRARY.md
Get-Content docs/SESSION_HANDOFF_MEDIA_LIBRARY.md
git status --short
rg -n "defineStore|resources/js/services|useApi" resources/js
```

Không reset hoặc xóa các thay đổi hiện có trong worktree. Các thay đổi backend
Task 1–5, frontend Task 6–7, phần tích hợp Task 8, test Task 9 và acceptance/
security Task 10 là nền tảng đã hoàn tất và phải được giữ nguyên.
