# Task 2 — Contract ảnh inline cho giao diện kết nối API

Backend và editor chung đã nối MediaLibrary. `PostEditor.vue` dùng `usePostInlineMedia`: nút `projectimage` mở chọn/upload ảnh tại bookmark con trỏ, `projectimageedit` sửa alt/caption; paste/drop qua `images_upload_handler` dùng cùng API asset. Khi TinyMCE không chỉnh sửa được, editor HTML dự phòng vẫn có nút chọn ảnh và giữ vị trí caret.

**Contract cập nhật 2026-10-06, FIX 1 12.41:** Post chèn ảnh bằng link HTTP/HTTPS hoặc đường dẫn từ gốc website; content chỉ lưu HTML và không tạo quan hệ MediaAsset. Cùng một link có thể xuất hiện ở nhiều vị trí, mỗi vị trí có alt/caption riêng. Nút `projectimageurl` chèn URL trực tiếp; chọn/upload MediaLibrary cũng chỉ chèn URL vào Post. Candidate AI dùng `media-references` để giữ ID nội bộ phục vụ xác thực nguồn và regenerate.

Editor dùng undo transaction cho chèn/sửa ảnh; event `mediaBusy` khóa Save/Apply khi upload hoặc còn URL tạm. Lỗi upload giữ nội dung đang nhập. Regenerate lưu/restores toàn bộ figure một ảnh cùng caption ở placeholder; figure nhiều ảnh cần người biên tập kiểm tra bố cục/chú thích.

Localhost đã kiểm chọn/upload, lưu/reload, regenerate và Apply qua editor dự phòng. Toolbar TinyMCE thật còn chờ Tiny Cloud cho phép origin localhost hoặc owner chọn license self-hosted hợp lệ; không tự đổi license/environment. Bằng chứng tại [QA localhost](qa/TASK2_LOCALHOST_2026-10-05.md).

## Chọn hoặc upload ảnh

| API | Dữ liệu | Quyền |
| --- | --- | --- |
| `GET /api/admin/media-assets` | Query `kind=image`, `visibility=public`, pagination; Gallery thêm `field=post.gallery` | `media.view` |
| `GET /api/admin/media-assets/{id}` | Đọc metadata/file của asset | `media.view` và policy asset |
| `POST /api/admin/media-assets` | Multipart `file`, `kind=image`, `title`, `visibility=public`, `alt_text` tùy chọn | `media.upload` |

Response `data` có `id`, `kind`, `visibility`, `alt_text`, `file.url`. Chỉ chèn sau khi upload thành công và có URL public. ID là ID **MediaAsset**, không phải `file.id` (Spatie media row).

```html
<figure>
  <img src="URL_PUBLIC_TỪ_FILE.URL" data-media-asset-id="42" alt="Ảnh minh họa">
  <figcaption>Chú thích do người viết nhập</figcaption>
</figure>
```

Ví dụ có `data-media-asset-id` ở trên dành cho candidate AI. Post editor tạo HTML bằng URL thuần và không yêu cầu ID. Thuộc tính ID từ nội dung AI/legacy có thể còn trong HTML đã lưu nhưng không tạo quan hệ với Post.

## Lưu Post

`POST /api/admin/posts` và `PUT /api/admin/posts/{id}` nhận `content` là HTML, giữ nguyên link và mọi lần ảnh xuất hiện. `ContentImageUrlValidator` chỉ kiểm URL/thuộc tính ảnh, không tìm asset, kiểm quyền attach hoặc suy ra usage từ HTML. URL ngoài và link gốc website được chấp nhận.

URL `blob:`, base64, file hoặc script trả `422` với lỗi `content`. Thuộc tính sự kiện trên `img` và `srcset`/`picture` chưa được hỗ trợ. Backend không tải URL client gửi và không thay đổi định dạng HTML manual Post. Lưu content bằng link chỉ cần `posts.manage`; thao tác upload/chọn thư viện vẫn dùng quyền Media API hiện có.

**Gallery riêng:** request `media.gallery_image_ids` là danh sách public image ID có thứ tự; response `media.gallery_images` trả các asset đó. Chỉ danh sách này ghi usage `post.gallery`, có quyền `media.attach`, validation kind/visibility/ID/distinct. Sửa/xóa/di chuyển ảnh content không thay Gallery; clear/reorder Gallery không thay HTML. Thumbnail dùng `post.thumbnail` độc lập. Contract `media.content_image_ids` và attach `post.content_images` không còn được phép ghi.

Migration `2026_10_06_100000_separate_post_gallery_from_content_images` bỏ usage legacy của ảnh có trong HTML và chuyển các ảnh chọn riêng sang Gallery, giữ thứ tự/file/HTML. Schema usage hiện có được tái sử dụng. `post_type = gallery` và trình diễn ảnh sẽ triển khai sau.

`DELETE /api/admin/media-assets/{id}` kiểm domain usage và candidate/source snapshot AI còn retention như trước. Ảnh content của Post không có usage và không làm tăng bộ đếm quan hệ. Cleaner asset AI tự động đọc URL trong HTML Post trước khi dọn file tạm, không ghi quan hệ; việc này giữ ảnh đã Apply còn hiển thị khi run hết hạn. Gallery/candidate vẫn có row lock tại boundary ghi của chúng.

Các API attach/replace usage hiện có cũng đọc lại và khóa asset trong transaction trước khi ghi. Instance asset đọc từ request cũ không được dùng để bỏ qua trạng thái đã xóa; replace lỗi giữ nguyên toàn bộ usage cũ. Danh sách ảnh giữ thứ tự caller dù backend khóa hàng theo ID tăng dần.

## Lưu candidate và Apply

`PATCH /api/admin/ai-agent/candidates/{uuid}` nhận các field phẳng `title`, `content_html`, `expected_version` và các field biên tập tùy chọn. Nội dung lưu vào `result_json.draft`; candidate dùng validator ref MediaLibrary và sanitizer hiện có. Apply/approve gọi Post action để lưu HTML/link, chỉ ghi usage thumbnail khi được chọn và không điền Gallery từ ảnh content.

Backend pipeline giữ ảnh parent thông qua ref map khi regenerate; model không quyết định MediaAsset ID hoặc URL mới. Reload giữ HTML/ref; frontend dùng URL sẵn trong HTML để render. Ref trong candidate không phải Gallery và không tạo quan hệ ảnh content với Post khi Apply. Cleaner bảo vệ candidate/source snapshot còn retention và URL đang được Post dùng.

## Nguồn HTML và snapshot

API tạo session nhận HTML qua `html` hoặc `input.html` thay vì frontend làm phẳng HTML thành text; multipart có thể gửi `html_file`. Chỉ gửi một nguồn URL/text/HTML/file; encoding mặc định UTF-8, có thể khai báo `source_encoding` theo allowlist của API. Backend extract/sanitize, giữ block code/bảng/link/quote và lưu source snapshot trước các lượt AI. Ảnh từ nguồn được ghi metadata/ngữ cảnh, không tự nhập thành MediaAsset hay chèn một URL nguồn chưa có asset vào nội dung cuối. HTML nguồn là dữ liệu tham khảo; ảnh người dùng chèn khi sửa candidate vẫn phải tuân theo contract asset ở trên.

## Boundary và kiểm thử

- `ContentImageUrlValidator::validate($html)` được Post request/action gọi, chỉ kiểm link/markup; không tạo usage.
- `ContentMediaReferenceService::validate($html, $actor)` trả ref hợp lệ của candidate AI; không được Post action dùng để điền Gallery.
- `ContentMediaReferenceService::referencedIds($html)` chỉ đọc ref để cleanup bảo vệ file. Hàm này không xác thực quyền/URL và không dùng thay `validate()`.
- `ContentMediaReferenceService::isReferencedByRetainedAiRun($assetId, $exceptRunId = null)` kiểm candidate/source/input snapshot còn retention; optional excluded run dùng khi cleaner dọn chính run đó.
- `PostGalleryTest` kiểm URL ngoài/lặp, không có usage content, Gallery order/clear/partial/validation/quyền và migration legacy.
- `ContentMediaReferenceTest` kiểm ref candidate AI, URL nguy hiểm, HTML lặp không có usage, cleanup candidate/checkpoint và file AI đang được Post giữ bằng link. Tests dùng DB/storage cô lập.
- `MediaAssetUsageTest` bổ sung tình huống instance cũ sau soft-delete/hard-delete và chính sách khóa trong attach/replace. SQLite không phát SQL `FOR UPDATE`, nên test kiểm trạng thái query builder và transaction; database production thực thi khóa hàng.
