# Handoff phiên làm việc — Media Library

**Ngày cập nhật:** 2026-09-28  
**Mục tiêu phiên tiếp theo:** tiếp tục triển khai Media Library từ Task 6 trong
[`PLAN_MEDIA_LIBRARY.md`](./PLAN_MEDIA_LIBRARY.md).

## Trạng thái đã hoàn thành

Task 1 đến Task 5 đã `DONE`:

1. Domain contract và enum.
2. `MediaAsset` domain, Spatie Media Library, disk public/private.
3. `media_asset_usages`, attach/detach/reorder/replace service.
4. Upload validation, checksum, archive scan, conversion queue và retry.
5. Media API, authorization, filter/pagination và private download.

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
69 tests passed, 356 assertions

Pint riêng các file Task 5: PASS
php artisan route:list --path=api/admin/media-assets: 10 routes
git diff --check: không có lỗi nội dung
```

`vendor/bin/pint --test` toàn project còn một số lỗi định dạng cũ ngoài phạm vi
Task 5; không format hàng loạt các file đó nếu chưa có yêu cầu riêng.

## Việc cần làm tiếp theo — Task 6

Đọc kỹ `docs/PLAN_MEDIA_LIBRARY.md`, đặc biệt Task 6 và response contract trong
`docs/PROJECT_STRUCTURE.md`, sau đó triển khai:

- `resources/js/services/mediaAsset.js`:
  - list/filter/pagination/sort;
  - detail;
  - upload với progress;
  - update metadata;
  - attach/detach/reorder/delete/retry/download;
  - unwrap envelope `BaseResponse` tại service boundary.
- `resources/js/stores/mediaAsset.js`:
  - list/items/itemsLength;
  - selected asset/detail;
  - filters và pagination;
  - loading, upload progress, error;
  - retry và mutation actions.
- Fake API dataset/handler giữ đúng response contract Laravel thật nếu cần cho UI.
- Không đưa HTTP call trực tiếp vào component.
- Dùng Composition API/Pinia pattern hiện tại của project.

Sau Task 6 mới chuyển sang Task 7 — Media Picker UI. Không tích hợp Resource/Post
picker trước khi service/store contract ổn định.

## Quy trình bắt đầu phiên mới

```powershell
Get-Content docs/PLAN_MEDIA_LIBRARY.md
Get-Content docs/SESSION_HANDOFF_MEDIA_LIBRARY.md
git status --short
rg -n "defineStore|resources/js/services|useApi" resources/js
```

Không reset hoặc xóa các thay đổi hiện có trong worktree. Các thay đổi backend
Task 1–5 là nền tảng đã hoàn tất và phải được giữ nguyên.
