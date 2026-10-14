# TPL-02 — Package attach và invariant MediaAsset

**Ngày:** 14/10/2026  
**Trạng thái:** DONE  
**Phạm vi:** Package usage, scan gate dùng lại, ready/default guard, request
lifecycle và invalidation snapshot template.

## Thay đổi chính

| Thành phần | File | Chức năng |
| --- | --- | --- |
| Package scan guard | `app/Services/ResourceVersionPackageGuard.php` | Đọc lại đúng một usage, archive private và `scan_status=clean` trước ready/process/preview |
| Usage invariant | `app/Services/MediaAssetUsageService.php` | Khóa version trước package attach/replace/detach; chặn package của version ready; giữ one-to-one |
| Snapshot invalidation | `app/Models/ResourceVersionTemplate.php` | Xóa screens, manifest, checksum, processing token và preview path khi package draft đổi |
| Delete guard | `app/Actions/Resources/DeleteResourceVersionAction.php`, `ResourceVersionController.php` | Khóa version, không xóa default/ready hoặc version có download history; giữ usage và retention |
| Lifecycle input | `ResourceVersionCreateRequest`, `ResourceVersionUpdateRequest`, create/update actions | Client không set `status` hoặc `is_default`; ready qua action riêng |

UploadMediaAssetAction không có nhánh template riêng; archive vẫn đi qua pipeline
MediaAsset/Spatie hiện có và archive public bị MediaAssetUsageService từ chối.

## Kiểm chứng

- `ResourceTemplateInvariantTest`: **6 tests / 23 assertions**.
- `MediaTask8IntegrationTest`: **4 tests / 37 assertions** hồi quy scan gate,
  archive private/public và resource version API.
- `ResourceTemplateSchemaTest`: **5 tests / 35 assertions** hồi quy schema/
  relation/screenshot field.
- `MediaAssetUsageTest`: **6 tests / 34 assertions** hồi quy usage chung.
- `MediaApiTest`: **7 tests / 70 assertions** hồi quy attach/detach endpoint,
  retry và private download.
- `ResourceTemplateManifestValidatorTest`: **47 tests / 548 assertions** hồi quy
  contract/fixture TPL-00.
- Pint scoped và PHP syntax pass; `git diff --check` không có lỗi whitespace.

## Giới hạn đã xác định

TPL-02 chưa tạo extractor, process job hoặc preview endpoint. Guard package đã
tách riêng để TPL-03/TPL-04/TPL-08 gọi lại cùng một scan invariant.
