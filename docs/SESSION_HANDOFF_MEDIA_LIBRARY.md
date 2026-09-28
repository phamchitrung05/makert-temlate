# Handoff phiên làm việc — Media Library

**Ngày cập nhật:** 2026-09-28 (ghi nhận cuối buổi sáng)
**Mục tiêu phiên tiếp theo:** buổi chiều bắt đầu triển khai Task 9; sau đó mới
chuyển sang acceptance/security của Task 10 trong
[`PLAN_MEDIA_LIBRARY.md`](./PLAN_MEDIA_LIBRARY.md).

## Ghi chú bàn giao cuối buổi sáng — không triển khai trùng

- Task 8 đã hoàn tất và đã được đánh dấu `DONE` trong
  `docs/PLAN_MEDIA_LIBRARY.md`.
- Task 9 và Task 10 chưa làm trong phiên này, vẫn giữ trạng thái `TODO`; do đã
  muộn nên Task 9 được dời sang buổi chiều/phiên kế tiếp.
- Các thay đổi sáng nay đã được giữ nguyên trong worktree; không reset hoặc
  xoá thay đổi trước đó.
- Phiên chiều nên bắt đầu bằng việc thiết lập Vitest + Vue Test Utils (project
  hiện chưa có script test frontend), rồi viết test theo checklist Task 9.

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

## Việc cần làm tiếp theo — Task 9 (buổi chiều)

- Thiết lập Vitest + Vue Test Utils + happy-dom và script `test:run`.
- Bổ sung test service mapping, store loading/error/retry/upload progress,
  picker single/multiple và Resource form giữ state khi API lỗi.
- Kiểm tra fake API giữ cùng response contract với Laravel.
- Chỉ sau khi Task 9 pass mới chuyển sang security/acceptance Task 10.

## Quy trình bắt đầu phiên mới

```powershell
Get-Content docs/PLAN_MEDIA_LIBRARY.md
Get-Content docs/SESSION_HANDOFF_MEDIA_LIBRARY.md
git status --short
rg -n "defineStore|resources/js/services|useApi" resources/js
```

Không reset hoặc xóa các thay đổi hiện có trong worktree. Các thay đổi backend
Task 1–5, frontend Task 6–7 và phần tích hợp Task 8 là nền tảng đã hoàn tất và
phải được giữ nguyên.
