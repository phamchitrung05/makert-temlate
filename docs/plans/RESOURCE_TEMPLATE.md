# Resource Template — kế hoạch triển khai và task chi tiết

**Cập nhật:** 14/10/2026

**Backlog tổng:** RT-01 trong [PLAN.md](PLAN.md)

**Trạng thái triển khai:** IN PROGRESS — TPL-00 đến TPL-04 DONE; TPL-05–TPL-12 chưa bắt đầu. Chưa có ready/promote hoặc live preview.

**Mã task trong tài liệu:** TPL-00–TPL-12.

## 1. Mục tiêu và phạm vi

Xây dựng khả năng quản lý tài nguyên bắt đầu từ **template HTML nhiều trang**: upload ZIP, lưu trữ, quản lý version, live preview, catalog, tải xuống và chia sẻ. Những phần chung tiếp tục dùng Resource, ResourceVersion và MediaAsset/Spatie của project.

Theo yêu cầu trước đó, chủ dự án sẽ **gom các giao diện Stitch và nối link trước khi upload**. Hệ thống tiếp nhận bộ HTML đã chuẩn bị; không tự đoán nút nào cần dẫn đến trang nào. “Chung một folder” nghĩa là một thư mục gốc của template, vẫn có thể chứa các thư mục con như assets, pages và screenshots.

Phân kỳ:

| Đợt | Kết quả cần dùng được |
| --- | --- |
| A — Admin Template MVP | Tạo template/version, upload ZIP, xử lý package, danh sách màn hình, live HTML preview, phát hành/đổi version mặc định/rollback |
| B — Public resource | Catalog và trang chi tiết theo W-01, live preview cho người xem đủ quyền |
| C — Download và chia sẻ | Tải đúng version qua D-01; link chia sẻ có phạm vi, hết hạn và thu hồi |
| D — Resource khác | Image, sound, video được triển khai theo contract riêng khi có yêu cầu |

Catalog là danh mục tài nguyên cho người dùng tìm kiếm, lọc và mở trang chi tiết. Nó đọc dữ liệu Resource; không phải một kho file hoặc một bảng tài nguyên mới.

## 2. Đối chiếu với project hiện tại

### 2.1 Những gì đã có và cần tái sử dụng

| Thành phần | Bằng chứng trong repo | Cách dùng trong kế hoạch |
| --- | --- | --- |
| Resource/catalog entity | [resources migration](../../database/migrations/2026_09_25_090204_create_resources_table.php), [Resource model](../../app/Models/Resource.php), [ResourceType](../../app/Enums/ResourceType.php) | Dùng type template, title, description, author, taxonomy, slug, SEO, status và visibility hiện tại |
| Version | [resource_versions migration](../../database/migrations/2026_09_25_090211_create_resource_versions_table.php), [ResourceVersion](../../app/Models/ResourceVersion.php) | Giữ version, changelog, requirements, status, is_default, released_at; unique resource_id + version |
| Upload và lưu file | [UploadMediaAssetAction](../../app/Actions/Media/UploadMediaAssetAction.php), [MediaAsset](../../app/Models/MediaAsset.php) | Spatie lưu file ở collection library; MediaAsset giữ metadata nghiệp vụ; ZIP luôn private |
| Validation và scan | [MediaUploadValidator](../../app/Services/Media/MediaUploadValidator.php), [ArchiveSecurityScanner](../../app/Services/Media/ArchiveSecurityScanner.php), [ScanMediaAssetJob](../../app/Jobs/Media/ScanMediaAssetJob.php) | Reuse kiểm loại/dung lượng, checksum, kiểm archive, scan_status, queue và retry |
| Media usage | [MediaAssetField](../../app/Enums/MediaAssetField.php), [MediaAssetUsageService](../../app/Services/MediaAssetUsageService.php) | Dùng resource.cover, resource.preview, resource_version.package và resource_version.documentation |
| Rule ready | [MarkResourceVersionReadyAction](../../app/Actions/Resources/MarkResourceVersionReadyAction.php) | Đã yêu cầu đúng một package archive, private, scan clean; bổ sung điều kiện template xử lý xong |
| Version mutation | [CreateResourceVersionAction](../../app/Actions/Resources/CreateResourceVersionAction.php), [UpdateResourceVersionAction](../../app/Actions/Resources/UpdateResourceVersionAction.php) | Mở rộng action đang dùng; không tạo CRUD version thứ hai |
| Admin API | [routes/api.php](../../routes/api.php), [ResourceVersionController](../../app/Http/Controllers/Admin/ResourceVersionController.php), [MediaAssetController](../../app/Http/Controllers/Admin/MediaAssetController.php) | Giữ API resource/version/media; thêm template processing và preview contract |
| Admin UI | [ResourceVersionForm.vue](../../resources/js/views/apps/ecommerce/resource-version/ResourceVersionForm.vue), [resourceVersion service](../../resources/js/services/resourceVersion.js) | Reuse form, MediaAssetField, service/store; nối panel Template |
| Media config | [media-assets.php](../../config/media-assets.php), [media-library.php](../../config/media-library.php) | Dùng giới hạn ZIP, disk private, image conversions và queue hiện có |
| Download history | [downloads migration](../../database/migrations/2026_09_25_090212_create_downloads_table.php) | Dùng resource_id, resource_version_id, media_id, customer_id, status và downloaded_at; không tạo lịch sử tải song song |
| Public website | [routes/web.php](../../routes/web.php), [PUBLIC_BACKEND.md](PUBLIC_BACKEND.md) | Public catalog/preview/download chưa có route hoàn chỉnh; phối hợp W-01/D-01, không ghi DONE từ schema có sẵn |

### 2.2 Khoảng trống thực tế cần xử lý

1. Chưa có metadata xác định ZIP template chứa những màn hình nào, trang bắt đầu và các path cần serve.
2. Chưa có template extractor/parser/job hoặc live HTML preview route.
3. Rule hiện tại chặn payload media khi update version ready, nhưng phải bổ sung guard ở **đường attach/detach/replace chung của Media Library** để bảo vệ version qua mọi entry point.
4. Create/Update request còn nhận is_default. Chưa thấy cơ chế promote dùng lock Resource bảo đảm chỉ một default đủ điều kiện. Cần chuyển việc đổi default sang action riêng.
5. Delete version hiện gọi detachAll rồi soft-delete. Cần quy tắc giữ package/version đã phát hành, chặn xóa default và bảo vệ lịch sử/download/share.
6. scan_status clean hiện là kết quả kiểm cấu trúc archive theo policy; không có nghĩa mọi JavaScript trong ZIP đáng tin hoặc đã qua antivirus.
7. MediaAssetKind hiện có image/document/archive/video; chưa có audio. ResourceType cũng chưa có các type image/sound/video riêng cho catalog.
8. Public slug dùng hệ thống SlugService/slug records theo W-01; không giả định resources có một cột slug để dùng binding trực tiếp.

### 2.3 Mẫu Stitch đã kiểm tra

Baseline TPL-00 ngày 14/10/2026 dùng hai snapshot trong
[`tests/Fixtures/ResourceTemplate`](../../tests/Fixtures/ResourceTemplate/README.md):

| Fixture | Nội dung | Expected |
| --- | --- | --- |
| `normalized/package.zip` | FlowTask đã gom/link: 18 HTML, 18 screenshot, assets chung và `template.json`; không có `manifest.json` | `expected/normalized-manifest.json` |
| `raw-stitch/package.zip` | Stitch gốc: một source wrapper, 9 folder có `code.html`/`screen.png`, `DESIGN.md` không thành screen | `expected/raw-discovery.json` |

- Source normalized được lấy từ `template/stitch_full_page_ui_design/`;
  source raw được snapshot từ `.original.zip` trước khi file local thay đổi.
- Test dùng snapshot đóng băng và checksum, không đọc nguồn `template/` đang chỉnh sửa.
- Raw không có `<title>`/entry: fallback theo tên folder, thứ tự path tăng dần;
  admin chọn entry trước ready. Không hardcode mapping screen thành `home`.
- HTML dùng Tailwind CDN, Google Fonts/Material Symbols và ảnh ngoài;
  normalized local href/src đã kiểm tồn tại, chưa chứng nhận browser/offline.
- Mẫu weather `app-thoi-tiet-mobile-2.zip` trong plan ban đầu không còn local.
  Có 5 synthetic PNG placeholder 28 byte để giữ ca kiểm MIME/content;
  không coi chúng là screenshot gốc được phục hồi.

## 3. Contract thư mục trước upload

### 3.1 Dạng chuẩn ưu tiên

Ví dụ một folder do chủ dự án chuẩn bị:

```text
weather-template/
  template.json
  index.html
  login.html
  forecast.html
  settings.html
  assets/
    css/
    js/
    images/
  screenshots/
    home.png
    login.png
```

- Link giữa các trang dùng đường dẫn tương đối, ví dụ login.html hoặc pages/forecast.html; CSS/JS/ảnh cũng theo cấu trúc package.
- Dùng folder con được phép; không ép dồn tất cả asset vào một cấp vì dễ trùng tên hoặc hỏng đường dẫn.
- Bản đầu hỗ trợ site HTML/CSS/JS tĩnh. Chức năng đăng nhập/thời tiết/thanh toán trên mẫu là giao diện/demo; không tự có backend thật.
- ZIP là sản phẩm đã sẵn sàng hiển thị. Không tự chạy npm install, build script, PHP hoặc Blade từ package upload.
- Chỉ dẫn template.json là dữ liệu; schema whitelist không cho chỉ định shell command hoặc đọc file ngoài package.

Manifest đầu vào đề xuất:

```json
{
  "schema_version": 1,
  "name": "weather-template",
  "title": "Weather Template",
  "version": "1.0.0",
  "description": "Giao diện weather tĩnh nhiều màn hình.",
  "entry_screen": "home",
  "screens": [
    {
      "slug": "home",
      "title": "Trang chủ",
      "path": "index.html",
      "screenshot": "screenshots/home.png"
    },
    {
      "slug": "login",
      "title": "Đăng nhập",
      "path": "login.html",
      "screenshot": "screenshots/login.png"
    }
  ]
}
```

Path tương đối so với thư mục gốc manifest; thứ tự screen theo mảng. Backend kiểm path tồn tại, kiểu file, slug unique và entry_screen thuộc screens. Schema không nhận id MediaAsset, storage path hoặc trạng thái từ file upload.

`template.json` là tên canonical duy nhất; `manifest.json` không được đọc làm
manifest đầu vào. Bốn metadata `name/title/version/description` là tùy chọn,
không ghi đè dữ liệu Resource/ResourceVersion tự động. Giới hạn, source-root,
discovery, warning/blocking và process deadline đã chốt trong
[contract v1](../api/RESOURCE_TEMPLATE_MANIFEST_V1.md).

### 3.2 Khi chưa có manifest

- Folder có index.html: phát hiện HTML trong source root, đề xuất trang bắt đầu index.html, admin xác nhận danh sách trước ready.
- ZIP Stitch gốc có nhiều screen/code.html: parser phát hiện các screen và đề xuất slug/title; admin chọn entry screen. Không tự sửa nội dung để đoán navigation.
- Có nhiều source root hoặc nhiều ứng viên entry: cần lựa chọn rõ trong admin, không âm thầm chọn file đầu tiên.
- href="#" và #section giữ nguyên như fragment; có thể cảnh báo “chưa có link sang màn hình khác”, không đổi mọi fragment thành link.
- Link nội bộ hợp lệ tiếp tục hoạt động do giữ nguyên cây thư mục. Sidebar ở preview luôn cho phép mở từng màn hình.
- Bộ mẫu được gom/link trước upload là fixture nghiệm thu navigation; ZIP Stitch gốc là fixture discovery/missing screenshot.

## 4. Mô hình dữ liệu đề xuất

### 4.1 Phần giữ nguyên

```text
Resource
  ├─ taxonomy / slug / SEO / cover / gallery
  └─ ResourceVersion
       └─ media_asset_usages: resource_version.package
            └─ MediaAsset(kind=archive, visibility=private)
                 └─ Spatie media(collection=library): ZIP gốc
```

Không tạo library_resources, resource_files hoặc hệ thống upload song song.

### 4.2 Bảng mới: resource_version_templates

Một hàng cho mỗi ResourceVersion có type template:

| Cột/nhóm | Mục đích |
| --- | --- |
| id, resource_version_id unique FK | Quan hệ 1:1 với version |
| source_media_id FK tới media, package_sha256 | Ghi đúng file/checksum đã xử lý; đối chiếu lại với usage hiện tại trước commit/ready |
| processing_status | pending / processing / ready / failed; tách khỏi ResourceVersionStatus |
| processing_token nullable UUID | Phân biệt lần chạy; job cũ không ghi đè kết quả mới |
| source_root | Root tương đối được chọn trong ZIP |
| entry_screen_slug nullable | Trang đầu; phải thuộc screen của chính template version |
| manifest_json nullable | Snapshot parser: schema version, file allowlist/MIME, dependencies, warnings; giới hạn kích thước |
| preview_path nullable | Relative path nội bộ trỏ artifact đã hoàn tất, không trả nguyên giá trị ra client |
| processing_error nullable, processed_at nullable, timestamps | Trạng thái vận hành; lỗi public được rút gọn, chi tiết vào log có kiểm soát |

FK cụ thể phải được đặt tên ngắn, có index và rollback phù hợp DB đang dùng. source_media_id không thay thế usage package; nó chỉ khóa kết quả xử lý vào đúng input.

### 4.3 Bảng mới: resource_version_template_screens

| Cột/nhóm | Mục đích |
| --- | --- |
| id, resource_version_template_id FK | Quan hệ 1:n |
| slug, title | Định danh/nội dung menu màn hình |
| source_path | HTML tương đối trong source root; giữ nguyên path file thật |
| sort_order | Thứ tự trong menu |
| metadata JSON nullable, timestamps | Screenshot source, cảnh báo/link diagnostics cần thiết; chỉ lưu dữ liệu bounded |

- Unique theo template_id + slug; index template_id + sort_order, dùng tên constraint ngắn.
- Model đề xuất ResourceVersionTemplateScreen dùng HasMediaAssets.
- Thêm morph alias template_screen và field **template_screen.preview**: một image trên mỗi screen. Cập nhật enum field, morph registry, validation/resolver, quyền linkable và picker frontend.
- Screenshot đi qua media_asset_usages như các model hiện có. Không thêm preview_media_asset_id song song làm nguồn dữ liệu thứ hai và khiến media usage/delete guard bỏ sót.
- Screen metadata do bảng screen quản lý; manifest parser là snapshot. Khi chỉnh settings draft, cập nhật/invalid hóa snapshot liên quan cùng transaction; khi ready, cấu trúc preview được đóng băng.

### 4.4 Share link ở đợt C

Dùng một bảng share_links trỏ ResourceVersion, với morph alias có allowlist nếu cần dùng chung cho resource khác: token_hash unique, capability, expires_at, revoked_at, created_by, timestamps.

Mặc định chia sẻ **version cố định**, capability preview. Download phải được cấp riêng. Link public đến trang chi tiết Resource có thể chia sẻ ngay ở đợt B mà không cần token/bảng này. Chưa xây chia sẻ lẻ từng screen hoặc link tự chạy theo “latest” trong MVP.

## 5. Upload template so với upload media hiện tại

| Bước | Media hiện tại | Resource Template bổ sung |
| --- | --- | --- |
| Tiếp nhận | UploadMediaAssetAction validate file, lưu tạm private | Dùng nguyên pipeline qua MediaAssetField/upload API |
| Kiểm ZIP | Preflight, checksum, Spatie media, ScanMediaAssetJob | Không tự bỏ qua hoặc thay scanner |
| Gắn nghiệp vụ | media_asset_usages theo field và model | Gắn ZIP vào resource_version.package |
| Xử lý | Ảnh có conversions; archive có scan status | Sau clean, job đọc ZIP, tạo manifest/screens/preview artifact |
| Xem | Ảnh/thumbnail hoặc download media | Host preview + iframe render HTML theo version |
| Phát hành | Media không đại diện một release template | Version ready, rồi promote default; Resource publish là bước riêng |

Luồng MVP từ form version:

1. Tạo/chọn Resource type template, nhập version và changelog.
2. Upload ZIP hoặc chọn ZIP đã có qua picker hiện tại; tạo ResourceVersion draft, is_default=false.
3. Theo dõi scan_status. Draft có thể lưu package đang pending, nhưng chưa được process/ready. Rejected không retry để “cho qua”; error kỹ thuật có thể retry.
4. Khi scan clean, bấm “Xử lý template”. API trả 202 với trạng thái/run token; job chạy sau commit.
5. Job extract an toàn vào staging private, phát hiện root/screens, import screenshot hợp lệ và tạo manifest.
6. Admin xem màn hình, warnings, chọn entry và thứ tự; mở live preview ngay khi processing ready dù version vẫn draft.
7. Chọn “Đánh dấu sẵn sàng”. Backend kiểm toàn bộ gate, không dựa vào canReady frontend.
8. Chọn “Đặt mặc định”. Version cũ vẫn giữ nguyên; default chỉ đổi ở transaction promote.
9. Khi đến đợt B, Resource publish/visibility quyết định có xuất hiện ở catalog hay không.

Hai bước kỹ thuật upload media → gắn version vẫn diễn ra trong flow form/picker. Không bắt buộc người dùng rời form sang Media Library. Sau MVP có thể gom bằng action orchestration cho một màn upload; phần scan/media bên dưới giữ nguyên.

## 6. Lưu trữ và xử lý qua queue

### 6.1 Phân tách source và preview artifact

- ZIP gốc thuộc Spatie trên media_private, dùng cho download và rebuild.
- HTML/CSS/JS giải nén là artifact phát sinh riêng theo version và lần xử lý.
- File bên trong ZIP không được import thành hàng nghìn MediaAsset; chỉ screenshot cần picker/conversion mới vào Media Library.
- Reuse private disk/storage abstraction, không yêu cầu chuyển sang S3 trong đợt A.

```text
media_private:
  [Spatie quản lý đường dẫn ZIP]
  template-previews/
    {versionId}/
      {processingToken}/
        manifest.json
        site/
          index.html
          login.html
          assets/...
  template-processing/
    {versionId}/{processingToken}/...  # staging
```

Với Stitch gốc, site giữ các screen-folder/code.html; với bộ đã gom, site giữ index.html/login.html. URL serve file phản chiếu đường dẫn bên trong site. Không đổi tất cả code.html sang một vị trí mới làm gãy ../assets hoặc link giữa các trang.

### 6.2 Quy tắc job

- Chỉ xử lý package attached đúng version và scan clean; lưu media id/checksum/run token khi enqueue.
- Queue dispatch sau DB commit; dùng worker hiện có, chỉ thêm cấu hình timeout/tries/backoff cần thiết.
- Có lock theo version để ngăn process trùng; recheck input trước publish kết quả.
- Giới hạn entry, uncompressed bytes, compression ratio, thời gian và dung lượng thực ghi ra disk.
- Reject traversal/absolute path/symlink, path trùng sau normalize/case folding; áp dụng thêm static-preview allowlist.
- Không resolve URL ngoài để tải dependency về server tự động.
- Stage toàn bộ artifact trước; transaction DB chỉ cập nhật pointer/screens/status khi artifact đầy đủ.
- Filesystem không rollback cùng DB: nếu DB fail, artifact staging/orphan phải được dọn có giới hạn; nếu job cũ hoàn tất sau package đổi, bỏ kết quả cũ.
- Retry cùng checksum không tạo screenshot trùng; định danh import theo version + source path + checksum. Chỉ xóa asset orphan do chính run tạo, không xóa asset được dùng nơi khác.
- Mất file derived có thể rebuild từ ZIP; với version ready phải tái tạo cùng manifest/config đã đóng băng. Thay nội dung/entry/link cần version mới.
- scan_status chuyển khỏi clean phải chặn preview/download ở request, kể cả processing_status đang ready.

## 7. Live HTML preview chạy như thế nào?

### 7.1 Luồng một lần mở preview

1. Người xem mở trang host preview trên app: chọn version/screen và kích thước mobile/tablet/desktop.
2. Laravel xác thực quyền đọc ResourceVersion và kiểm manifest/package hiện tại.
3. Backend cấp URL preview giới hạn version/build và thời gian nếu là nội dung private.
4. iframe gọi HTML trên preview origin; controller/asset server chỉ serve file nằm trong allowlist.
5. Browser tải CSS/JS/ảnh từ cùng cây URL; link login.html dẫn tới HTML kế tiếp trong **cùng version**.
6. Sidebar đổi iframe URL sang source_path của screen; không cần đọc hoặc can thiệp DOM iframe.

**Laravel đọc và trả file HTML; browser render HTML.** Không include file upload vào Blade, không chạy PHP, không mount template upload thành route controller của project.

### 7.2 Ranh giới truy cập

Phương án đề xuất cho bản phát hành:

- Preview origin tách khỏi app/admin, không dùng chung session cookie và không expose route admin trên host preview. Ưu tiên một site riêng; nếu dùng subdomain, phải kiểm cookie Domain/SameSite và quyền cross-origin.
- Iframe bắt đầu với sandbox allow-scripts, không allow-same-origin, forms, popups, downloads hoặc top-navigation. JS là để render/interact demo; không cấp quyền đăng nhập thật. Theo [MDN iframe sandbox](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe#sandbox), việc ghép allow-scripts với allow-same-origin ở cùng origin có thể làm mất tác dụng sandbox.
- HTML response cũng có HTTP CSP sandbox để URL HTML mở trực tiếp vẫn bị hạn chế; không chỉ dựa vào thuộc tính iframe. CSP sandbox phải đặt bằng HTTP header, không dùng meta hoặc report-only, theo [MDN CSP sandbox](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/sandbox).
- Server kiểm quyền trên **mọi HTML và asset request**. Preview grant chỉ đọc version/build được cấp; hết hạn, revoke, Resource bị ẩn hoặc version bị archive phải mất quyền theo policy.
- Không đưa bearer token admin/customer vào iframe. API cấp preview grant dùng auth hiện có; iframe chỉ nhận capability đọc preview có hạn.
- Grant có thể nằm trong path prefix để URL tương đối tiếp tục kế thừa, ví dụ /p/{grant}/site/login.html. Không ghi grant vào log/referrer; áp dụng Referrer-Policy no-referrer trên response.
- Sandbox không thay thế cookie isolation, authorization hay chặn request vào app. Không bật CORS tùy tiện cho origin null, không coi CORS là cơ chế chặn mọi request.
- Content-Type từ allowlist, nosniff; file PHP/Blade/executable/dotfile không được serve. Chặn path traversal kể cả URL encoding và separator Windows.
- Private responses không dùng cache public; cache theo version/build và context quyền. Có kiểm tra lại quyền trước phục vụ file, không để link asset cũ bypass revoke.

### 7.3 Dependency ngoài và giới hạn tương thích

Đây là chính sách ứng dụng đề xuất, cần browser smoke test trên bộ mẫu:

- Ưu tiên CSS/JS/ảnh/font đã nằm trong package do chủ dự án chuẩn bị.
- Với bản Stitch còn dùng CDN, cấu hình allowlist host theo loại script/style/font/image trong preview config, không tin host whitelist do ZIP tự khai báo.
- CSP giới hạn nguồn; chặn connect/form/object/frame không cần thiết. Có thể cho inline script/style phục vụ Stitch trên origin preview đã cô lập; chỉ nới đúng directive qua kiểm thử, không tắt CSP toàn bộ.
- Không cam kết “CSP strict” đồng thời mọi CDN/inline script chạy nguyên trạng. Ghi rõ dependencies bị chặn và khả năng hiển thị thay đổi.
- ES modules, localStorage, fetch và font trong sandbox có thể cần xử lý riêng; chưa hỗ trợ tùy ý như hosting một ứng dụng. Nếu bundle không tương thích, hiển thị lý do và screenshot.
- Screenshot thiếu là warning, không chặn template HTML hợp lệ; CSS/asset nội bộ cần thiết bị thiếu hoặc entry sai là lỗi chặn ready.
- Preview API không chạy website bán hàng/đăng nhập/thời tiết thật chỉ vì giao diện có nút tương ứng.
- Người có quyền live preview sẽ nhận HTML/CSS/JS trong trình duyệt; không thể bảo đảm giấu source tuyệt đối. Download ZIP vẫn là capability riêng.


## 8. Lifecycle version và xử lý version cũ

### 8.1 Tạo version mới

1. Resource đang published/default không bị sửa tại chỗ.
2. Tạo ResourceVersion mới với status draft, is_default=false và version number chưa tồn tại trong Resource.
3. Attach ZIP vào resource_version.package qua usage service.
4. Scan/process version mới độc lập; version cũ vẫn là default cho catalog, preview và download.
5. Nếu version mới failed hoặc bị bỏ, chỉ version mới bị ảnh hưởng.

### 8.2 Ready và promote

MarkResourceVersionReadyAction phải kiểm tra trong transaction và lock version:

- ResourceVersion tồn tại, chưa deleted, type của Resource là template.
- Đúng một package usage.
- Asset không deleted, kind archive, visibility private.
- Media gốc có scan_status clean.
- ResourceVersionTemplate có processing_status ready, có screen, entry hợp lệ.
- Derived preview pointer còn tồn tại và manifest khớp source checksum/token.

Sau đó mới chuyển version sang ready. Ready chưa đồng nghĩa thành default.

PromoteResourceVersionAction cần lock Resource và transaction:

1. Kiểm version mới ready, cùng resource, không bị archived.
2. Kiểm lại scan/template gate ngay trước khi đổi default.
3. Bỏ is_default ở các version khác.
4. Set is_default=true cho version mới.
5. Ghi activity log và released_at nếu chưa có.
6. Xóa/invalidate cache public và preview index của Resource.

Không cho client set is_default qua Create/Update hoặc MediaAsset attach. Mọi thao tác đặt mặc định phải qua promote endpoint/action. Nếu DB dùng unique index partial chưa hỗ trợ ổn định, action lock Resource là invariant bắt buộc; bổ sung unique strategy phù hợp driver sau khi kiểm migration.

### 8.3 Version cũ

- Version cũ vẫn ready và non-default; có thể xem preview theo version cố định nếu policy cho phép.
- Rollback là promote version cũ đã ready, không copy package và không sửa version number.
- Archive chỉ là thao tác explicit, cần chặn archive default và version còn download/share active nếu policy chưa có.
- Không hard-delete ZIP/media của version đang có usage, download record hoặc derived preview cần retention.
- Khi version archived, public default query không trả version đó; admin audit/preview theo policy vẫn được.
- Nếu package v1 sạch nhưng derived preview bị mất, cho phép rebuild; nếu source ZIP bị mất/hỏng, đánh dấu unavailable và không cho ready/promote.
- Download luôn ghi resource_version_id; link “latest” chỉ resolve default lúc cấp URL, sau đó URL phải giữ version cố định.

## 9. API và route contract đề xuất

### 9.1 Admin API mở rộng

Giữ namespace /api/admin và permission resource_versions.manage:

| Method | Route | Mục đích |
| --- | --- | --- |
| GET | /resource-versions/{resourceVersion}/template | Metadata, status, manifest tóm tắt và screens |
| POST | /resource-versions/{resourceVersion}/template/process | Enqueue/retry; trả 202 và run/status |
| PATCH | /resource-versions/{resourceVersion}/template | Entry, sort, title, link mapping và settings draft |
| POST | /resource-versions/{resourceVersion}/template/screens/{screen}/preview | Attach/replace screenshot qua usage service |
| POST | /resource-versions/{resourceVersion}/promote | Đặt version thành default |
| POST | /resource-versions/{resourceVersion}/rollback | Alias có thể dùng promote version cũ; không tạo dữ liệu mới |
| GET | /resource-versions/{resourceVersion}/template/preview | Trả host/iframe context cho admin |

Thực hiện route thực tế bằng controller/action riêng nếu controller hiện tại quá lớn. Route model binding phải giới hạn version đúng Resource khi request truyền resource id.

Response template nên có:

- resource_version id/version/status/is_default;
- package id/checksum/scan_status, không trả disk path;
- processing status, error code công khai, processed_at;
- entry_screen;
- screens: id/slug/title/source_path/sort_order/screenshot URL/status/warnings;
- preview URL có signed/grant ngắn hạn khi cần;
- capabilities theo policy.

Không trả raw manifest quá lớn trong list. Detail có thể trả manifest summary, warnings bounded và link lấy manifest nếu admin permission.

### 9.2 Preview route

Admin host:

- GET /admin/resources/{resource}/versions/{version}/preview

Preview file server:

- GET /preview/{grant}/site/{path}

Public phase B:

- GET /resources/{resource:slug}/preview
- GET /resources/{resource:slug}/preview/{version}
- GET /resources/{resource:slug}/preview/{version}/{screen}

Các route trên chỉ là contract cần chốt; cập nhật theo naming trong W-01. Không tạo public route trước khi có policy Resource published/public và version default.

### 9.3 Download/share boundary

- MediaAssetController hiện tại là admin controller; không dùng route download của nó làm public API.
- Public download action resolve Resource → version cụ thể → package MediaAsset, kiểm tra published/public/ready/clean rồi tạo URL/stream.
- Share preview là quyền view theo version. Share download là capability khác và có thể cần auth/license.
- Token/share grant không được đưa vào canonical, sitemap, logs hoặc response error.
- Downloads lưu media_id của Spatie item, resource_version_id và status; không tạo bảng download template riêng.

## 10. Task breakdown triển khai

### TPL-00 — Chốt contract và fixture

**Mục tiêu:** khóa cấu trúc input, output và policy trước khi sửa schema.

- [x] Chốt manifest schema version 1 và JSON Schema/validator.
- [x] Chốt folder contract cho bộ đã gom: entry files, assets, screenshots và relative links.
- [x] Chốt discovery rule cho ZIP Stitch: code.html, screen.png, source root, slug/title heuristic.
- [x] Chốt entry screen bắt buộc ở ready; nếu không có thì admin phải chọn.
- [x] Chốt mức warning/blocking: placeholder screenshot là warning; thiếu HTML, duplicate path, invalid link nội bộ cần thiết là blocking.
- [x] Chốt capability: admin preview trước; public preview sau W-01.
- [x] Chốt định lượng archive dựa config media-assets.php và giới hạn thời gian process.
- [x] Đưa ZIP mẫu, ZIP đã gom/link và expected manifest vào fixture; giữ 5 placeholder PNG để test.
- [x] Viết contract note về external CDN/Google Fonts và không đảm bảo network dependency.

**Phụ thuộc:** không.

**Đầu ra:** manifest v1, fixture input/output, decision log.

**Kết quả 14/10/2026:** DONE phạm vi contract/fixture; validator PHP, JSON Schema,
hai ZIP snapshot và expected output đã có. Process deadline 120s, job timeout
180s được chốt để enforce ở TPL-04/12; chưa có job/parser hoặc preview.
[Contract](../api/RESOURCE_TEMPLATE_MANIFEST_V1.md) · [QA](../qa/resource-template/TPL-00.md).

### TPL-01 — Schema và model template

**Mục tiêu:** lưu processing state và screen metadata theo ResourceVersion.

- [x] Tạo migration resource_version_templates với FK unique đến resource_versions.
- [x] Tạo migration resource_version_template_screens với FK cascade, unique slug/path và sort index.
- [x] Thêm source_media_id, checksum/token và preview artifact pointer như contract ở mục 4.
- [x] Tạo enum processing status pending/processing/ready/failed.
- [x] Tạo model/resource relation, cast JSON và query scopes ready/processing.
- [x] Thêm ResourceVersion relations template/screen.
- [x] Thêm model HasMediaAssets cho screen để screenshot dùng usage service.
- [x] Đăng ký morph aliases và field template_screen.preview; cập nhật validation/picker contract.
- [x] Thêm factory/fixture tối thiểu, không thay migration đã chạy.
- [x] Quyết định FK screenshot: dùng usage service làm nguồn duy nhất, không thêm preview_media_asset_id.

**Kết quả 14/10/2026:** TPL-01 DONE phạm vi schema/model. AI chuẩn hóa
`template.json` trước import; database lưu snapshot manifest, processing state,
input checksum/token và screen rows, không tự đoán navigation. Screenshot
không có FK riêng; `template_screen.preview` đi qua MediaAssetUsageService.
[QA TPL-01](../qa/resource-template/TPL-01.md).

**Phụ thuộc:** TPL-00.

**Kiểm chứng:** migrate fresh, rollback, unique, cascade, morph map và factory.

### TPL-02 — Package attach và invariant MediaAsset

**Mục tiêu:** bảo đảm ZIP đi qua đúng pipeline hiện tại ở mọi entry point.

- [x] Không sửa UploadMediaAssetAction thành template-specific; package tiếp tục đi qua pipeline upload chung.
- [x] Verify package gắn ở resource_version.package, one-to-one; service khóa version trước mutation để chống race.
- [x] Chặn archive public; attach/replace/detach dùng MediaAssetUsageService.
- [x] Dùng ResourceVersionPackageGuard đọc lại archive/private/scan_status clean ở ready và tái sử dụng được cho process/preview.
- [x] MediaAssetUsageService chặn thay/tháo package khi ResourceVersion ready.
- [x] Guard destroy ResourceVersion: không xóa default hoặc ready; usage/download retention được giữ.
- [x] Request và action không nhận status/is_default từ client; ready chỉ qua lifecycle action.
- [x] Detach/replace package của draft invalidate processing metadata, screen snapshot và preview path.

**Phụ thuộc:** TPL-01 và action/media hiện tại.

**Kiểm chứng:** attach/replace/detach service, scan gate, destroy version, lifecycle payload và lock version cho package one-to-one. Chi tiết ở [QA TPL-02](../qa/resource-template/TPL-02.md).

### TPL-03 — Extractor, parser và manifest

**Mục tiêu:** biến ZIP sạch thành cấu trúc template version có thể preview.

- [x] Tạo TemplatePackageExtractor dùng staging private, ZipArchive và path normalize.
- [x] Recheck archive policy trước extract; không dùng shell unzip.
- [x] Phát hiện source root và tránh double nesting.
- [x] Parse template.json theo allowlist; fallback discovery khi thiếu manifest.
- [x] Nhận code.html ở screen folder và file HTML theo manifest.
- [x] Tạo slug ổn định, unique; title từ manifest/title HTML/tên folder theo thứ tự có tài liệu.
- [x] Thu thập source_path, relative assets, link diagnostic, external dependencies và screenshot candidate.
- [x] Validate entry, screen count, duplicate path và file type.
- [x] Reject PHP/server-side/executable/dotfiles theo policy static preview.
- [x] Ghi warning bounded, không lưu nội dung HTML khổng lồ vào JSON.
- [x] Áp parser vào raw Stitch 9 screen và normalized 18 screen đã đóng băng, so với expected TPL-00.

**Phụ thuộc:** TPL-00, TPL-01, TPL-02.

**Kiểm chứng:** unit parser, security path test, expected manifest diff.

**Kết quả 14/10/2026:** DONE. Extractor tạo run staging private bằng stream,
recheck archive scanner và actual-byte limit; parser hỗ trợ manifest canonical và
raw Stitch discovery. Diagnostics không giữ query/fragment của URL ngoài, local
dependency thiếu là blocking, screenshot placeholder là warning. [QA TPL-03](../qa/resource-template/TPL-03.md).

### TPL-04 — ProcessTemplateVersionJob và artifact lifecycle

**Mục tiêu:** xử lý bất đồng bộ, idempotent và có thể rebuild.

- [x] Tạo job/service nhận version id, media id, checksum và processing token.
- [x] Dispatch after commit, retry/backoff/timeout theo queue hiện tại.
- [x] Lock version hoặc unique run để chống hai job cùng ghi.
- [x] Extract vào template-processing staging; chỉ publish pointer sau khi hoàn tất.
- [x] Lưu site theo cây thư mục gốc để relative CSS/JS/ảnh/link hoạt động.
- [x] Copy/sanitize metadata; không copy package thành public disk.
- [x] Tạo screens và manifest trong transaction sau artifact ready.
- [x] Xóa run cũ/artifact orphan đúng owner; không xóa asset shared.
- [x] Nếu source checksum đổi, run cũ không được ghi đè run mới.
- [x] Khi job fail, set processing_error public-safe, log exception chi tiết, giữ package và default version.
- [x] Khi ready version artifact mất, enqueue lại theo checksum sẽ tạo run rebuild mới; endpoint unavailable/rebuild thuộc TPL-06.

**Phụ thuộc:** TPL-03.

**Kiểm chứng:** queue fake/test, retry idempotent, fail giữa filesystem/DB, rebuild.

**Kết quả 14/10/2026:** DONE. `ProcessTemplateVersionJob` nhận identity scalar,
dispatch sau commit, unique theo version/token, retry tối đa 3 lần với backoff
30/120/300 giây và timeout 180 giây. Service kiểm tra package private/clean,
checksum và token, giải nén vào staging private, giữ nguyên cây site rồi publish
pointer vào `media_private/template-previews/{version}/{token}` trước khi ghi
screens/manifest trong transaction. Failure có log chi tiết nhưng chỉ lưu
`processing_error` bounded/public-safe; worker cũ và checksum cũ không thể ghi đè
run mới. Tracker `resource_template_processing` dùng chung với popup Admin,
hiển thị queued/processing/ready/failed/cancelled và dọn staging khi hủy.
Deadline parser mặc định 120 giây được kiểm tra theo config. [QA TPL-04](../qa/resource-template/TPL-04.md).

### TPL-05 — Ready, promote, rollback và archive policy

**Mục tiêu:** tách “đã xử lý xong” khỏi “đang phát hành mặc định”.

- [ ] Mở rộng MarkResourceVersionReadyAction cho resource type template và processing gate.
- [ ] Tạo PromoteResourceVersionAction với lock Resource, transaction, permission và activity log.
- [ ] Chặn is_default từ create/update request hoặc bỏ field khỏi public input.
- [ ] Định nghĩa rollback = promote một version ready non-default.
- [ ] Chặn archive default; chốt rule với active share/download retention.
- [ ] Cập nhật ResourceVersionResource và scopes để trả template/processing state.
- [ ] Invalidate catalog/preview cache sau promote/archive/publish.
- [ ] Cập nhật activity events: template.processed, version.ready, version.promoted, version.rolled_back, version.archived.
- [ ] Kiểm concurrent promote và stale request.

**Phụ thuộc:** TPL-04.

**Kiểm chứng:** v1/v2 lifecycle, rollback, concurrent transaction và API error.

### TPL-06 — Admin API và policy

**Mục tiêu:** cung cấp contract ổn định cho Vue.

- [ ] Thêm FormRequest cho process, template settings, screen reorder/link mapping.
- [ ] Thêm controller/API theo mục 9; controller chỉ orchestration.
- [ ] Eager-load template/screens/media đúng field, tránh N+1.
- [ ] Trả 202 khi enqueue; trả trạng thái terminal trong detail/poll.
- [ ] Authorization bằng permission resource_versions.manage và policy linkable.
- [ ] Validate screen id thuộc đúng version; version id thuộc đúng resource nếu route nested.
- [ ] Không trả storage path/raw private disk/exception stack.
- [ ] Trả signed/grant preview URL bounded và capability.
- [ ] Thêm OpenAPI/API docs hoặc contract fixture trong docs/api khi API ổn định.

**Phụ thuộc:** TPL-05.

**Kiểm chứng:** Feature test 401/403/404/409/422/202/200 và permission matrix.

### TPL-07 — Admin Vue Template panel

**Mục tiêu:** admin có thể xử lý template từ form version mà không làm mất flow Media hiện tại.

Component map dự kiến:

| Component | Trách nhiệm |
| --- | --- |
| ResourceVersionForm.vue | Compose version fields, package/docs picker và template panel |
| TemplateProcessingPanel.vue | Status, process/retry, warnings, ready gate |
| TemplateScreenList.vue | Danh sách screen, sort, entry selection |
| TemplateScreenPreview.vue | Screenshot/live iframe theo screen |
| useResourceTemplate.js | Fetch/process/update state và polling |
| resourceTemplate service/store | HTTP contract và state riêng, không nhồi vào media store |

- [ ] Giữ Composition API, script setup, comment header theo convention.
- [ ] Hiển thị scan status và processing status riêng.
- [ ] Process button disable đúng state; polling dừng ở ready/failed/cancelled.
- [ ] Cho chọn entry, đổi thứ tự, sửa title/link mapping draft.
- [ ] Gắn screenshot qua picker field template_screen.preview khi contract sẵn sàng.
- [ ] Cho mở iframe admin theo grant; fallback screenshot/missing state rõ ràng.
- [ ] Ready button chỉ enable khi backend capability/gate trả true; backend vẫn là authority.
- [ ] Promote/rollback có dialog nêu version cũ/mới và refresh list sau thành công.
- [ ] Không cho UI đổi is_default bằng checkbox cũ.
- [ ] Thêm frontend tests cho polling, stale response và failed process.

**Phụ thuộc:** TPL-06; áp dụng vue-best-practices khi code Vue.

**Kiểm chứng:** build, Vitest scoped, browser smoke.

### TPL-08 — Preview origin và static file server

**Mục tiêu:** live HTML preview an toàn, đúng version và đúng đường dẫn tương đối.

- [ ] Tạo TemplatePreviewService resolve version/build/screen/manifest.
- [ ] Tạo preview host/page và file controller riêng.
- [ ] Mỗi request kiểm grant/policy/version state/scan status/processing status.
- [ ] Normalize path, decode an toàn, reject traversal/symlink/outside manifest.
- [ ] Allowlist Content-Type và extension; nosniff, no-referrer, private cache.
- [ ] Set CSP sandbox header và response headers; host iframe set sandbox allow-scripts tối thiểu.
- [ ] Ưu tiên origin/subdomain không có admin session cookie.
- [ ] Không bật allow-same-origin/forms/popups/downloads/top-navigation nếu chưa có test/policy.
- [ ] Cấu hình CDN allowlist theo environment; ghi diagnostics external dependency.
- [ ] Serve source tree giữ relative paths; không đổi links làm hỏng site.
- [ ] Bắt lỗi missing asset và iframe load error; cho screenshot fallback.
- [ ] Kiểm HTML mở trực tiếp, iframe, revocation và private cache.
- [ ] Ghi preview access audit bounded, không log token.

**Phụ thuộc:** TPL-04, TPL-06.

**Kiểm chứng:** test route/path/security + browser smoke normalized 18 screen/raw 9 screen + external dependency diagnostics.

### TPL-09 — Public catalog và public preview

**Mục tiêu:** nối template vào public backend sau khi admin MVP ổn định.

- [ ] Hoàn thành W-01 PB-01/PB-02 public Resource query.
- [ ] Catalog chỉ lấy Resource published, visibility public, slug primary; filter type template.
- [ ] Default preview chỉ lấy ResourceVersion ready + is_default + template ready + package clean.
- [ ] Tạo view model cho card/detail: cover, screenshots, screen count, default version, SEO.
- [ ] Thêm canonical/OG/JSON-LD/sitemap theo PUBLIC_BACKEND.md.
- [ ] Public preview giữ version cố định khi URL đã chọn; latest resolve default lúc mở page.
- [ ] Resource members/private dùng auth/policy; không suy luận quyền từ iframe.
- [ ] Cache invalidation khi promote, archive, visibility/status/slug/SEO đổi.
- [ ] Không cho draft/processing/failed version lộ qua catalog, meta, sitemap.

**Phụ thuộc:** TPL-08 và W-01.

**Kiểm chứng:** Feature test visibility/slug/SEO/cache + browser public smoke.

### TPL-10 — Download và share

**Mục tiêu:** reuse downloads hiện tại và chỉ cấp capability đã chốt.

- [ ] Xác nhận D-01 policy guest/customer, verify email, quota, license, retention.
- [ ] Tạo public DownloadTemplateAction/controller riêng.
- [ ] Resolve package media đúng resource_version_id; kiểm Resource published/public, version ready, clean, template ready.
- [ ] Cấp signed/temporary URL có hạn hoặc stream; không lộ disk path.
- [ ] Ghi downloads atomic, status/error và tránh double-count retry.
- [ ] Bổ sung rate limit, counter và audit bounded.
- [ ] Khi cần share, tạo share_links token_hash/capability/expires/revoked/created_by.
- [ ] Preview share không tự có download; download share kiểm policy riêng.
- [ ] Revoke/expire share làm preview/download fail ngay tại request.
- [ ] URL “latest” chỉ dùng để resolve; record/URL cấp ra luôn ghim version.

**Phụ thuộc:** TPL-09 và D-01.

**Kiểm chứng:** expired token, revoke, rate limit, wrong version, non-clean package, history/counter.

### TPL-11 — Test, QA và fixture report

- [ ] Unit: manifest schema, slug, source root, title fallback, link diagnostics, MIME screenshot.
- [ ] Feature: migrations, relations, usage field/kind/cardinality, process API, ready gate, promote/rollback.
- [ ] Security: traversal, absolute path, encoded path, symlink, encrypted/oversized ZIP, executable entry, CSP/path leak.
- [ ] Queue: scan → process → ready, retry, duplicate job, stale token, missing artifact rebuild.
- [ ] Frontend: service/store/panel/polling/screen sort/entry/promotion dialog.
- [ ] Browser: admin upload, raw 9 screen/normalized 18 screen preview, relative asset/link, external CDN warning, fallback screenshot.
- [ ] Public: catalog visibility, default version, pinned old version, noindex/private media.
- [ ] Concurrency: two process jobs, two promote requests, attach while process, detach/destroy guard.
- [ ] Lưu fixture kết quả tại docs/qa/resource-template/; cập nhật PLAN chỉ khi acceptance hoàn tất.
- [ ] Không đánh dấu Media và version DONE hiện tại thành chưa xong; RT-01 là phần mở rộng mới.

**Bộ acceptance tối thiểu:** backend scoped tests, frontend scoped tests, browser smoke fixture đã gom/link và browser smoke ZIP Stitch gốc.

### TPL-12 — Vận hành và tài liệu

- [ ] Bổ sung queue worker/job name, tries/timeout/backoff và retry procedure vào ENVIRONMENT.md.
- [ ] Thêm config template preview limits, allowed dependency hosts, artifact disk, grant lifetime và retention.
- [ ] Có command/endpoint diagnostic cho stuck processing, missing artifact, stale run.
- [ ] Theo dõi disk usage source ZIP/derived artifact, orphan cleanup, failed jobs và preview 4xx/5xx.
- [ ] Chốt backup/restore: ZIP gốc, DB manifest/screens/usage, derived preview rebuild.
- [ ] Cập nhật PROJECT_STRUCTURE.md/PROJECT_INVENTORY.md sau khi code ổn định.
- [ ] Tạo API contract/QA report và cập nhật PLAN.md trạng thái từng TPL.
- [ ] Runbook rollback version: promote old ready, invalidate cache, verify public preview/download.

## 11. Thứ tự triển khai

1. TPL-00 — contract/fixture.
2. TPL-01 — schema/model/usage field.
3. TPL-02 — attach/invariant và delete guard.
4. TPL-03 — extractor/parser.
5. TPL-04 — process job/artifact.
6. TPL-05 — ready/promote/rollback.
7. TPL-06 — admin API/policy.
8. TPL-07 — admin Vue.
9. TPL-08 — live preview server/security.
10. TPL-11 — test/QA theo từng task, chốt sau TPL-08.
11. TPL-12 — runbook/ops.
12. TPL-09 — public catalog/preview theo W-01.
13. TPL-10 — download/share theo D-01.

MVP có thể nghiệm thu sau TPL-08 + TPL-11 một phần: admin upload ZIP, xử lý template, xem live preview từng screen, đánh dấu ready, promote version mới và rollback version cũ. Chưa cần public catalog/download/share để chứng minh core lifecycle.

## 12. Acceptance criteria chung

- Resource type template dùng Resource/ResourceVersion hiện tại, không có bảng catalog/file trùng.
- ZIP qua MediaAsset validation, archive scan, checksum và private storage hiện có.
- Package được attach đúng field, không bị thay/detach khi version ready qua bất kỳ API attach path nào.
- Template parser tạo metadata và screen rows bounded, retry idempotent, rebuild được.
- HTML/CSS/JS được serve từ artifact private theo version/build; browser render trong iframe, không phải server thực thi.
- Preview path, grant, sandbox, CSP, cookie/origin và cache không làm lộ admin session/raw storage.
- Link tương đối của package đã gom vẫn chạy; link # chỉ là fragment/warning nếu chưa được cấu hình mapping.
- Screenshot hợp lệ dùng làm fallback; placeholder 28 byte không hiển thị như preview hợp lệ.
- New version không làm mất default cũ; ready immutable; promote/rollback transactional; default duy nhất.
- Public catalog/download/share chỉ được mở sau policy/route/SEO/security tương ứng và dùng version cố định.
- Có test backend/frontend/browser và report QA cho cả package Stitch gốc lẫn package đã gom/link.

## 13. Không làm trong task này

- Không tạo một bảng resource_files/library_resources mới.
- Không tự động chạy npm, PHP, Blade hoặc command trong ZIP upload.
- Không cố map mọi href="#" thành màn hình bằng heuristic không kiểm chứng.
- Không coi UI có nút login/thời tiết là backend feature thật.
- Không thêm audio/video processor chỉ để “để ngõ”; thêm khi có contract loại tài nguyên.
- Không làm payment, license, entitlement hoặc marketplace.
- Không làm share link latest không ghim version.
- Không sửa hàng loạt các migration/media actions đang chạy ngoài phạm vi RT-01.
- Không mở public route trước khi W-01/D-01 chốt security, visibility và download policy.

## 14. Định nghĩa hoàn thành của RT-01

RT-01 chỉ chuyển DONE khi:

1. TPL-00 đến TPL-08 đạt acceptance và có report QA.
2. Có thể upload hoặc chọn package ZIP, process thành công, nhìn thấy screen list và live preview.
3. Có thể tạo v2 trong khi v1 vẫn default; promote v2 rồi rollback v1.
4. Ready gate chặn package chưa scan, template chưa process, entry thiếu và artifact lỗi.
5. Media attach/detach/delete không bypass invariant version.
6. Tài liệu vận hành/API/structure được cập nhật.
7. Public catalog/download/share vẫn ghi TODO riêng nếu chưa qua TPL-09/TPL-10.

## 15. Tài liệu liên quan

- [Kế hoạch tổng](PLAN.md)
- [Public backend Blade](PUBLIC_BACKEND.md)
- [Mục lục docs](../README.md)
- [Cấu trúc dự án](../architecture/PROJECT_STRUCTURE.md)
- [Inventory dự án](../architecture/PROJECT_INVENTORY.md)
- [Hướng dẫn môi trường](../operations/ENVIRONMENT.md)
- [Media asset config](../../config/media-assets.php)
- [Template fixture và expected TPL-00](../../tests/Fixtures/ResourceTemplate/README.md)
