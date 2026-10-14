# TPL-00 — Contract và fixture report

**Ngày:** 14/10/2026  
**Trạng thái:** DONE  
**Phạm vi:** Manifest v1, folder contract, discovery policy, warning/blocking
matrix và fixture boundary trước TPL-01.

## Quyết định đã chốt

1. `template.json` là manifest canonical duy nhất.
2. Metadata root tùy chọn `name`, `title`, `version`, `description` được cho phép
   với giới hạn trong schema v1.
3. Normalized package và raw Stitch ZIP là hai fixture độc lập.
4. Admin preview được làm trước; public preview phụ thuộc W-01.
5. Archive limits lấy từ `config/media-assets.php`; không tạo hằng số trùng.
6. External CDN, Google Fonts, Material Symbols và ảnh ngoài được giữ trong
   fixture để ghi warning; không fetch network hoặc chạy build script.

## Fixture

| Fixture | Vai trò | Kết quả kiểm tra |
| --- | --- | --- |
| `tests/Fixtures/ResourceTemplate/normalized/package.zip` | Normalized package | `template.json`, 18 HTML, 18 screenshots, assets CSS/JS/images |
| `tests/Fixtures/ResourceTemplate/raw-stitch/package.zip` | Raw Stitch discovery | Source root và các thư mục `*_flowtask/code.html`, không có manifest canonical |

Normalized package chỉ còn `template.json`; `manifest.json` duplicate đã được
loại bỏ để không có hai nguồn canonical.

Normalized snapshot dùng wrapper `flowtask/` và paths ZIP có slash, tương ứng
folder `template/stitch_full_page_ui_design/` tại thời điểm đóng băng. Raw ZIP
được giữ nguyên bytes từ `.original.zip` trước khi nguồn local thay đổi; có 9
screen, chưa chọn entry. Không hardcode biến projects thành home trong discovery.
Expected output nằm trong `tests/Fixtures/ResourceTemplate/expected/`, có
SHA-256/size. `.gitattributes` giữ JSON LF để checksum ổn định trên Windows/Linux.

Mẫu weather trong plan ban đầu không có local. Năm placeholder PNG 28 byte là
synthetic test input được ghi nhãn rõ; test kiểm chúng không đọc được bằng
`getimagesizefromstring`, không đưa vào danh sách screenshot thật.

## Contract kiểm chứng

- `schema_version` bắt buộc bằng `1`.
- `entry_screen` phải trỏ tới screen hợp lệ.
- Screen slug dùng kebab-case ASCII và không trùng.
- Screen path là relative `.html`/`.htm`, không có absolute path, `..`, null byte
  hoặc backslash.
- Screenshot tùy chọn chỉ nhận image extension đã allowlist.
- Metadata text được giới hạn độ dài, không được diễn giải thành command/path.
  Field command/storage path/publish/processing state không nằm trong allowlist.
- Fixture validator xác nhận toàn bộ HTML/screenshot trong normalized package
  tồn tại.
- Screenshot thiếu là warning; manifest/path/entry sai là blocking.
- Source-root/discovery order, entry selection, required local dependency,
  external dependency, capability và limits được chốt trong
  [contract v1](../../api/RESOURCE_TEMPLATE_MANIFEST_V1.md).
- Process deadline 120s và job timeout 180s mới là contract; guard/job sẽ enforce
  tại TPL-04/12. Archive scan không được trình bày như antivirus hoặc sandbox JS.

## Bằng chứng

- `TemplateManifestValidator` kiểm tra schema, metadata, slug, path, entry và
  file tồn tại.
- Unit test kiểm normalized manifest, metadata tùy chọn, lỗi blocking,
  warning screenshot, bounds/path corpus, raw ZIP boundary, checksums và local navigation.
- Tất cả screenshot normalized có PNG signature hợp lệ.
- Link local trong các HTML normalized trỏ tới file có trong package; external
  URL được giữ lại để TPL-03/TPL-08 ghi diagnostics và áp CSP.
- `ResourceTemplateManifestValidatorTest`: **47 test cases / 548 assertions**.
- `MediaUploadPipelineTest`: **10 tests / 38 assertions** vẫn đạt sau khi dùng
  ArchiveSecurityScanner cho fixture ZIP.
- JSON Schema draft-07: **35 ca đạt**, không có failure, kiểm bằng AJV có sẵn;
  [kết quả schema](schema-verification.json). Semantic rules vẫn do PHP kiểm.
- PHP syntax check và Pint scoped: **3 file đạt**. Các class/method mới đều có
  `CHỨC NĂNG`, `INPUT`, `OUTPUT`, `SIDE EFFECT`, `EXCEPTION/TRANSACTION` theo
  [quy ước docs](../../architecture/DEVELOPMENT_GUIDELINES.md).
- Test không thực thi HTML/JS, gọi CDN, chạy extractor hoặc preview browser.

## Chưa thuộc TPL-00

TPL-00 chưa tạo migration, model, extractor, queue job, admin API hoặc preview
server. Các phần đó bắt đầu từ TPL-01 đến TPL-08.

Checklist 9/9 của TPL-00 đã hoàn tất. RT-01 vẫn IN PROGRESS; TPL-01–TPL-12 vẫn
TODO. Chưa thay behavior API Resource/Media hoặc ghi migration vào database.
