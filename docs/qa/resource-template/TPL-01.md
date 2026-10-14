# TPL-01 — Schema và model template

**Ngày:** 14/10/2026  
**Trạng thái:** DONE  
**Phạm vi:** Hai bảng template metadata/screen, processing enum, Eloquent
relations/casts/scopes, media screenshot contract và factories.

## Quyết định triển khai

- AI chuẩn hóa `template.json` trước khi package nhập hệ thống; TPL-01 không
  tự đoán navigation hoặc chạy parser.
- `resource_version_templates` là quan hệ 1:1 với `resource_versions`.
- `resource_version_template_screens` là quan hệ 1:n, giữ `slug`, `title`,
  `source_path`, `sort_order` và metadata bounded.
- `source_media_id` chỉ lưu dấu vết MediaAsset input; package canonical vẫn
  dùng usage `resource_version.package`.
- Screenshot không có `preview_media_asset_id` riêng. Screen dùng
  `HasMediaAssets` và field `template_screen.preview` qua
  `MediaAssetUsageService`.
- `processing_status` tách khỏi `ResourceVersionStatus`: `pending`,
  `processing`, `ready`, `failed`.

## Thay đổi chính

| Thành phần | File | Chức năng |
| --- | --- | --- |
| Migration metadata | `database/migrations/2026_10_14_051026_create_resource_version_templates_table.php` | checksum/token, manifest, source root, entry, preview path, error và processed time |
| Migration screens | `database/migrations/2026_10_14_051027_create_resource_version_template_screens_table.php` | screen rows, FK cascade, unique slug/path, sort index |
| Enum | `app/Enums/ResourceVersionTemplateProcessingStatus.php` | status processing và nhãn admin |
| Model | `app/Models/ResourceVersionTemplate.php` | casts, source media, screens, ready/processing scopes |
| Model | `app/Models/ResourceVersionTemplateScreen.php` | casts, HasMediaAssets, template relation, ordered scope |
| Version relation | `app/Models/ResourceVersion.php` | `template()` và `templateScreens()` |
| Media contract | `app/Enums/MediaAssetField.php` | `template_screen.preview`, image, single asset |
| Morph map | `app/Providers/AppServiceProvider.php` | alias `template_screen` |
| Factories | `database/factories/ResourceVersionTemplate*Factory.php` | pending/processing/ready/failed và screen fixture |

## Kiểm chứng

- `ResourceTemplateSchemaTest`: **5 tests / 35 assertions**.
- `MediaAssetContractTest`: **4 tests / 20 assertions**.
- `MediaAssetUsageTest`: **6 tests / 34 assertions** hồi quy đạt.
- `InitialSchemaTest`: **1 test / 29 assertions** migrate/reset đạt.
- PHP syntax check và Pint scoped đạt.
- Kiểm tra unique screen slug/path, FK cascade khi force-delete version,
  enum/JSON cast, scopes, `HasManyThrough`, morph alias và attach screenshot.

## Chưa thuộc TPL-01

Chưa có extractor/parser, process job, scan recheck, ready gate, promote/
rollback, API, preview server hoặc frontend panel. Các phần này thuộc TPL-02
đến TPL-08.
