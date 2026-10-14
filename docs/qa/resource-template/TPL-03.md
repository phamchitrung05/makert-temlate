# TPL-03 — Extractor, parser và manifest

**Ngày:** 14/10/2026  
**Trạng thái:** DONE  
**Phạm vi:** scan lại ZIP, extract staging private, source-root, manifest v1,
raw Stitch discovery và static dependency diagnostics.

## Thay đổi chính

| Thành phần | File | Chức năng |
| --- | --- | --- |
| ZIP extractor/parser | `app/Services/Resources/TemplatePackageExtractor.php` | Dùng `ZipArchive`, stream vào run staging, actual-byte guard và cleanup khi lỗi |
| Path contract | `app/Services/Resources/TemplateManifestValidator.php` | Dùng chung normalize path, chặn Windows device name và collision case-folding |
| Parser test | `tests/Unit/TemplatePackageExtractorTest.php` | Normalized 18 screen, raw Stitch 9 screen, traversal, executable, duplicate path và dependency diagnostics |

Extractor recheck `ArchiveSecurityScanner` trước khi ghi disk, không gọi shell
unzip và không thực thi HTML/CSS/JS. Entry có traversal, absolute path,
backslash, dotfile, Windows reserved name, symlink, encrypted flag, executable
extension hoặc path trùng sau normalize bị block.

Package có `template.json` được validate bằng `TemplateManifestValidator`; screen
output giữ cả `path` canonical và `source_path` để job sau ghi vào
`resource_version_template_screens`. Package không có manifest được discovery
theo `code.html` của Stitch hoặc các file HTML, title fallback theo HTML title →
tên folder/file → slug và không tự thêm suffix khi slug collision.

Diagnostics ghi local dependency đã resolve, external host/type/url đã bỏ
query/fragment, placeholder/fragment warning và screenshot candidate. HTML/CSS
được đọc bounded 2 MiB; warning có giới hạn 200 chi tiết/500 ký tự và trả thêm
`warnings_total`/`warnings_truncated`. Missing local dependency là blocking;
dependency ngoài không được tải về server.

## Kiểm chứng

- `TemplatePackageExtractorTest`: **7 tests / 31 assertions**.
- `ResourceTemplateManifestValidatorTest`: **47 tests / 548 assertions**.
- Media upload/usage regression: **20 tests / 109 assertions**.
- Scoped TPL-00–TPL-03 suite: **65 tests / 637 assertions**.
- Hai fixture đã kiểm: normalized package 18 screen và raw Stitch 9 screen.
- `php -l` pass cho extractor, validator và test.
- TPL-03 chưa tạo queue job, artifact lifecycle hoặc preview endpoint; các phần đó
  thuộc TPL-04 và TPL-08.

