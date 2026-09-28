# Handoff phiên làm việc — Media Library

**Ngày cập nhật:** 2026-09-28 (sau khi hoàn thành Task 9)
**Mục tiêu phiên tiếp theo:** triển khai acceptance/security của Task 10 trong
[`PLAN_MEDIA_LIBRARY.md`](./PLAN_MEDIA_LIBRARY.md).

## Ghi chú bàn giao sau Task 9 — không triển khai trùng

- Task 8 đã hoàn tất và đã được đánh dấu `DONE` trong
  `docs/PLAN_MEDIA_LIBRARY.md`.
- Task 9 đã hoàn tất và đã được đánh dấu `DONE`; Task 10 vẫn giữ `TODO`.
- Người dùng đã tạm dừng trước Task 10 để chuyển sang làm việc tại nhà; không
  triển khai Task 10 trong phiên này.
- Các thay đổi Task 1–9 đã được giữ nguyên trong worktree; không reset hoặc xoá
  thay đổi trước đó.

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
73 tests passed, 395 assertions

Pint riêng controller/test Media API: PASS
SFC picker compile: 4 files passed
ESLint Media/Picker: PASS
npm run build: PASS
git diff --check: không có lỗi nội dung
```

Pint đã pass trên toàn bộ file PHP liên quan Task 8. Không format hàng loạt các
file PHP cũ ngoài phạm vi nếu chưa có yêu cầu riêng.

Frontend Task 6–7 đã chạy ESLint các file Media/Picker với 0 error, compile trực
tiếp 4 SFC picker và `npm run build` thành công. Build vẫn in cảnh báo asset PNG
cũ được resolve lúc runtime.

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

## Việc cần làm tiếp theo — Task 10

Phiên ở nhà tiếp tục trực tiếp từ checklist dưới đây; không làm lại Task 1–9:

- Chạy acceptance/security checklist cho upload image/archive.
- Kiểm tra executable, MIME giả, path traversal, symlink và archive quá giới hạn.
- Kiểm tra permission, private download, queue failure/retry và idempotency.
- Chỉ đánh dấu Task 10 `DONE` sau khi toàn bộ backend/frontend/build/lint pass.

## Quy trình bắt đầu phiên mới

```powershell
Get-Content docs/PLAN_MEDIA_LIBRARY.md
Get-Content docs/SESSION_HANDOFF_MEDIA_LIBRARY.md
git status --short
rg -n "defineStore|resources/js/services|useApi" resources/js
```

Không reset hoặc xóa các thay đổi hiện có trong worktree. Các thay đổi backend
Task 1–5, frontend Task 6–7, phần tích hợp Task 8 và test Task 9 là nền tảng đã
hoàn tất và phải được giữ nguyên.
