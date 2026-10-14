# Resource Template Manifest v1

**Chốt:** 14/10/2026 — TPL-00. JSON Schema dùng draft-07. Các quyết định dưới đây
là contract cho TPL-01–TPL-08; parser/extractor/preview chưa được triển khai.

Tài liệu này là contract đầu vào cho package Resource Template trong RT-01.
Manifest canonical duy nhất có tên `template.json` và nằm ở root của package.
`manifest.json` không phải tên manifest được hỗ trợ.

## Cấu trúc canonical

```text
template-package/
  template.json
  index.html
  pages/
    login.html
  assets/
    css/
    js/
    images/
  screenshots/
    home.png
```

Package chỉ là website HTML/CSS/JS tĩnh. Hệ thống giữ cây thư mục để relative
link tiếp tục hoạt động, nhưng không chạy npm, PHP, Blade, shell command hoặc
build script lấy từ package.

## Schema

Schema JSON canonical nằm tại
[`resource-template-manifest-v1.json`](schemas/resource-template-manifest-v1.json).

Manifest tối thiểu:

```json
{
  "schema_version": 1,
  "entry_screen": "home",
  "screens": [
    {
      "slug": "home",
      "title": "Trang chủ",
      "path": "index.html",
      "screenshot": "screenshots/home.png"
    }
  ]
}
```

Các metadata tùy chọn được cho phép ở root:

| Field | Kiểu | Giới hạn | Ý nghĩa |
| --- | --- | --- | --- |
| `name` | string | 120 ký tự | Tên kỹ thuật package |
| `title` | string | 160 ký tự | Tên hiển thị |
| `version` | string | 64 ký tự | Version của package do tác giả cung cấp |
| `description` | string | 2.000 ký tự | Mô tả package |

Metadata chỉ hiển thị hoặc lưu lại theo contract; không được dùng để chọn
storage path, command, quyền hoặc trạng thái publish.

Mỗi screen bắt buộc có `slug`, `title` và `path`. `slug` dùng kebab-case ASCII,
phải unique và không được chứa dữ liệu người dùng chưa chuẩn hóa. `path` là
relative path dùng dấu `/`, không được chứa absolute path, `..`, null byte,
backslash hoặc path ngoài package. Screen path phải là `.html` hoặc `.htm`.

`screenshot` là tùy chọn và chỉ nhận `.png`, `.jpg`, `.jpeg`, `.webp` hoặc
`.gif`. Screenshot thiếu hoặc placeholder là warning; không tự biến thành
screen preview hợp lệ.

Giới hạn contract v1: JSON UTF-8 tối đa 256 KiB, tối đa 200 screen, slug tối đa
120 ký tự, title screen tối đa 160 ký tự và path tối đa 512 ký tự. Metadata đã
truyền phải là chuỗi không rỗng, không chỉ có whitespace; không nhận null để thay
cho field omitted. Extension HTML/image được kiểm không phân biệt hoa/thường.

Path manifest không nhận segment rỗng, `.`/`..`, dotfile, dấu colon, ký tự control,
ký tự Windows không hợp lệ, percent encoding, query hoặc fragment. Giữ case/path
gốc khi serve nhưng collision screen path được kiểm bằng case-folding để cùng
package hoạt động nhất quán trên Linux/Windows. Không rewrite path của tác giả.

PHP validator: `App\Services\Resources\TemplateManifestValidator`.
`validateJson($json, $availablePaths)` giữ đúng object/list và báo JSON sai bằng
result. `validate($manifest, $availablePaths)` dành cho array nội bộ đã parse.
`null` file list chỉ kiểm schema; `[]` là package rỗng và phải chặn path HTML
thiếu. Chỉ sử dụng `result.manifest` khi `result.valid === true`.

JSON Schema kiểm type/field/format/length. Kiểm uniqueness slug/path, entry nằm
trong screen list và file tồn tại là semantic validation ở PHP. Screenshot MIME,
relative link và ZIP security được kiểm trong parser/processor, không được suy
ra từ JSON Schema hoặc scan_status clean.

## Discovery khi không có manifest

ZIP Stitch raw được giữ làm fixture discovery riêng. Parser áp dụng thứ tự:

1. Đọc `template.json` nếu tồn tại.
2. Nếu không có manifest, phát hiện `index.html` trong source root làm entry
   candidate.
3. Với ZIP Stitch, phát hiện các thư mục chứa `code.html`; `screen.png` cạnh
   thư mục được xem là screenshot candidate.
4. Title fallback theo thứ tự: metadata manifest, HTML `<title>`, tên thư mục,
   rồi slug chuẩn hóa.
5. Nếu có nhiều entry candidate hoặc không thể xác định entry, admin phải chọn;
   parser không tự chọn file đầu tiên.

### Source root và thứ tự ổn định

- Nếu `template.json` ở root archive, đó là source root.
- Nếu toàn bộ site nằm trong một wrapper folder duy nhất, bỏ wrapper khi xác
  định root; giữ mọi đường dẫn bên trong. Không strip tùy ý từng folder screen.
- Nhiều manifest/source root có thể cùng chứa site là blocking `source_root_ambiguous`;
  phải chuẩn bị lại package hoặc chọn root rõ ràng qua capability sẽ làm ở TPL-06/07.
- Discovery không có manifest: sắp path tương đối bằng thứ tự byte ASCII tăng
  dần. Với manifest: giữ thứ tự mảng screens của tác giả.
- Slug fallback dùng tên folder/file không có extension, transliterate và
  kebab-case; không hardcode xóa suffix thương hiệu. Collision slug/path là
  blocking để tác giả/admin giải quyết, không tự gán suffix có thể đổi theo OS.
- HTML `<title>` rỗng được coi là không có title; fallback tên folder được đổi
  `_`/`-` thành khoảng trắng. Folder chỉ có `DESIGN.md` không thành screen.
- Nếu không có entry: discovery output có `entry_screen: null` và
  `requires_entry_selection: true`. Output này chưa phải manifest ready v1;
  chỉ được đánh dấu ready sau khi entry được chọn và validate lại.

`href="#"` và fragment được giữ nguyên và chỉ sinh warning. Link nội bộ trỏ
đến file bắt buộc phải tồn tại trong cùng package; link ngoài được ghi nhận là
external dependency.

Link trong HTML/CSS có `../` được resolve tương đối với file chứa link, chỉ hợp
lệ nếu vẫn nằm trong source root. Đây là rule của dependency resolver, khác với
manifest path không cho `..`. Asset required gồm script src, stylesheet href,
img src, CSS url/import và đích navigation HTML; missing local target chặn ready.
Fragment trong cùng file, mailto/tel và demo `href="#"` không được đoán thành
navigation mới. JS động, fetch, module/localStorage hoặc backend login thật chưa
được cam kết; diagnostics phải nói rõ khi browser không hỗ trợ.

## Blocking và warning

Blocking gồm ZIP traversal/absolute path/symlink/encrypted entry, vượt giới
hạn archive, manifest sai schema, thiếu HTML, duplicate path/slug, entry screen
không hợp lệ và link nội bộ bắt buộc bị thiếu.

Warning gồm thiếu hoặc placeholder screenshot, không có manifest nhưng có thể
discovery, fragment `#`, Google Fonts/CDN, ảnh ngoài và dependency không được
cam kết chạy offline.

Archive policy lấy runtime từ `config/media-assets.php`; contract không sao
chép lại thành hằng số mới. Preview admin được triển khai trước; public preview
chỉ mở sau W-01.

| Rule | Nguồn / giá trị mặc định hiện tại | Giai đoạn enforce |
| --- | --- | --- |
| ZIP upload | `ProjectSettingsService::mediaPolicy('archive')`, fallback `media-assets.kinds.archive.max_size_kb` = 512000 KiB | Pipeline upload hiện có |
| Entry count (gồm directory entries) | `media-assets.archive.max_entries` = 10000 | Scan hiện có, recheck TPL-03/04 |
| Uncompressed / actual bytes written | `media-assets.archive.max_uncompressed_bytes` = 1073741824 (1 GiB) | Scan header hiện có; actual write guard TPL-03/04 |
| Compression ratio mỗi entry | `media-assets.archive.max_compression_ratio` = 100 | Scan hiện có; chặn comp_size=0 với size>0 ở extractor TPL-03 |
| Encrypted / symlink | `allow_encrypted=false`, `allow_symlinks=false` | Scan hiện có, recheck TPL-03/04 |
| Process deadline | 120 giây/run; timeout worker 180 giây, phải nhỏ hơn queue lease | Chốt contract, config/guard/job ở TPL-04/12 |
| Retry | Tối đa 3 lần cho lỗi vận hành; lỗi input blocking không retry | TPL-04 |
| Warnings | Tối đa 200 chi tiết/run, mỗi message tối đa 500 ký tự; giữ tổng số + cờ truncated | TPL-03/04 |

Trước preview, package phải là archive private đã scan clean và vẫn khớp
checksum. Static-preview policy bổ sung sẽ chặn PHP/Blade/server-side,
executable, shell script, dotfile và collision path; ZIP scan hiện tại không
được coi là đã bảo đảm tất cả các rule static-preview này.

### Dependency ngoài

- Không tải CDN/font/ảnh về server và không thực thi JS trong processor.
- Ghi host và loại script/style/font/image, không lưu URL query có token vào
  diagnostics. ZIP không được tự cấp quyền host cho CSP.
- `cdn.tailwindcss.com`, `fonts.googleapis.com`, `fonts.gstatic.com` và
  `lh3.googleusercontent.com` là dependency của fixture; host allowlist của
  preview do ứng dụng cấu hình ở TPL-08.
- CSP hoặc network có thể chặn dependency và làm preview khác screenshot.
  Không cam kết chạy offline; hiển thị warning/fallback rõ ràng.
- Package/derived artifact vẫn private; public preview, share và download cần
  policy/route tương ứng của W-01/D-01, không được mở chỉ từ manifest.

## Fixture TPL-00

Hai fixture được giữ độc lập:

| Fixture | Đường dẫn | Vai trò |
| --- | --- | --- |
| Normalized package | `tests/Fixtures/ResourceTemplate/normalized/package.zip` | Snapshot 18 screen, HTML root, assets, screenshots và `flowtask/template.json` |
| Raw Stitch ZIP | `tests/Fixtures/ResourceTemplate/raw-stitch/package.zip` | Kiểm tra source-root/screen discovery từ `*_flowtask/code.html` |

`DESIGN.md` là tài liệu phụ và không được tính thành screen. Normalized package
chỉ dùng `template.json` làm manifest canonical; không giữ `manifest.json` song
song để tránh hai nguồn dữ liệu khác nhau.

Expected manifest/discovery và size/SHA-256 nằm trong
`tests/Fixtures/ResourceTemplate/expected/`. Fixture test không đọc thư mục
`template/` đang được chỉnh sửa. Năm PNG placeholder 28 byte là synthetic input
được ghi nhãn rõ vì mẫu weather cũ không còn local; không giả là ảnh gốc đã khôi phục.
