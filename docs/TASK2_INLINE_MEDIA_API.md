# Task 2 — Contract ảnh inline cho giao diện kết nối API

Backend và editor chung đã nối MediaLibrary. `PostEditor.vue` dùng `usePostInlineMedia`: nút `projectimage` mở chọn/upload ảnh tại bookmark con trỏ, `projectimageedit` sửa alt/caption; paste/drop qua `images_upload_handler` dùng cùng API asset. Khi TinyMCE không chỉnh sửa được, editor HTML dự phòng vẫn có nút chọn ảnh và giữ vị trí caret.

Chỉ chèn URL file public đã hoàn thành cùng MediaAsset ID. Editor dùng undo transaction cho chèn/sửa ảnh; event `mediaBusy` khóa Save/Apply của form khi upload hoặc còn URL tạm. Lỗi upload giữ nội dung đang nhập. Regenerate lưu/restores toàn bộ figure một ảnh cùng caption ở placeholder; figure nhiều ảnh cần người biên tập kiểm tra bố cục/chú thích.

Localhost đã kiểm chọn/upload, lưu/reload, regenerate và Apply qua editor dự phòng. Toolbar TinyMCE thật còn chờ Tiny Cloud cho phép origin localhost hoặc owner chọn license self-hosted hợp lệ; không tự đổi license/environment. Bằng chứng tại [QA localhost](qa/TASK2_LOCALHOST_2026-10-05.md).

## Chọn hoặc upload ảnh

| API | Dữ liệu | Quyền |
| --- | --- | --- |
| `GET /api/admin/media-assets` | Query `kind=image`, `field=post.content_images`, `visibility=public`, pagination | `media.view` |
| `GET /api/admin/media-assets/{id}` | Đọc metadata/file của asset | `media.view` và policy asset |
| `POST /api/admin/media-assets` | Multipart `file`, `kind=image`, `title`, `visibility=public`, `alt_text` tùy chọn | `media.upload` |

Response `data` có `id`, `kind`, `visibility`, `alt_text`, `file.url`. Chỉ chèn sau khi upload thành công và có URL public. ID là ID **MediaAsset**, không phải `file.id` (Spatie media row).

```html
<figure>
  <img src="URL_PUBLIC_TỪ_FILE.URL" data-media-asset-id="42" alt="Ảnh minh họa">
  <figcaption>Chú thích do người viết nhập</figcaption>
</figure>
```

Editor cần giữ `data-media-asset-id` trong HTML. Có thể dùng URL conversion `thumb`, `web`, `featured`, `og` nếu backend đã tạo conversion; không tự dựng URL conversion chưa có.

## Lưu Post

`POST /api/admin/posts` và `PUT /api/admin/posts/{id}` tiếp tục nhận `content` là HTML. Khi có `content`, backend parse ảnh, kiểm ID/URL/quyền và suy ra `media.content_image_ids` theo thứ tự xuất hiện. Không cần gửi gallery ảnh lần thứ hai; gallery do client gửi không ghi đè các ID suy từ content. Cùng asset xuất hiện nhiều vị trí chỉ tạo một usage.

Ảnh phải thuộc public image asset còn tồn tại, có file, đúng URL gốc hoặc conversion đã hoàn thành và actor có `media.attach`. URL `blob:`, base64, URL khác asset hoặc ảnh thiếu ID trả `422` với lỗi `content`; thiếu quyền attach trả `403`. Thuộc tính sự kiện trên `img` và nguồn ảnh thay thế bằng `srcset`/`picture` chưa được hỗ trợ và trả `422`, tránh một URL thay thế vượt kiểm tra `img.src`. Backend không tải URL client gửi để biến ảnh giả thành asset và không thay đổi định dạng HTML manual Post.

Khi xóa ảnh khỏi content rồi lưu, usage `post.content_images` bị tháo; file MediaAsset vẫn tồn tại. Thumbnail được xử lý độc lập. Partial update không gửi `content` giữ usage nội dung hiện có. Contract media-only cũ vẫn được giữ cho giao diện hiện tại; editor tích hợp mới nên dùng HTML làm nguồn xác định ảnh inline.

`DELETE /api/admin/media-assets/{id}` khóa asset và kiểm lại usage/ref trước soft-delete. File có domain usage giữ lỗi 422 hiện có; file được candidate/source snapshot AI còn retention giữ trả 409. Lưu Post/candidate kiểm ảnh dưới row lock trong transaction, để DELETE không chen giữa kiểm asset và lưu usage/ref. Request prevalidation vẫn chỉ đọc dữ liệu. Cleaner dùng cùng kiểm tra retained refs và khóa từng asset.

Các API attach/replace usage hiện có cũng đọc lại và khóa asset trong transaction trước khi ghi. Instance asset đọc từ request cũ không được dùng để bỏ qua trạng thái đã xóa; replace lỗi giữ nguyên toàn bộ usage cũ. Danh sách ảnh giữ thứ tự caller dù backend khóa hàng theo ID tăng dần.

## Lưu candidate và Apply

`PATCH /api/admin/ai-agent/candidates/{uuid}` nhận các field phẳng `title`, `content_html`, `expected_version` (hash `draft_version` từ response gần nhất) và các field biên tập tùy chọn theo request hiện có. Nội dung được lưu vào `result_json.draft`. Candidate controller dùng cùng validation ảnh và sanitizer; việc Apply qua `POST /api/admin/ai-agent/candidates/{uuid}/apply` gọi Post action để kiểm lại quyền/URL và đồng bộ usage.

Backend pipeline giữ ảnh parent thông qua ref map khi regenerate; model không quyết định MediaAsset ID hoặc URL mới. Reload giữ HTML/ref; frontend dùng URL sẵn trong HTML để render. Candidate không phải Post nên chưa tạo usage `post.content_images` trước Apply. Cleaner bảo vệ ảnh đang được run/candidate khác trong retention giữ, bao gồm source snapshots của run đang chạy/lỗi có thể retry.

## Nguồn HTML và snapshot

API tạo session nhận HTML qua `html` hoặc `input.html` thay vì frontend làm phẳng HTML thành text; multipart có thể gửi `html_file`. Chỉ gửi một nguồn URL/text/HTML/file; encoding mặc định UTF-8, có thể khai báo `source_encoding` theo allowlist của API. Backend extract/sanitize, giữ block code/bảng/link/quote và lưu source snapshot trước các lượt AI. Ảnh từ nguồn được ghi metadata/ngữ cảnh, không tự nhập thành MediaAsset hay chèn một URL nguồn chưa có asset vào nội dung cuối. HTML nguồn là dữ liệu tham khảo; ảnh người dùng chèn khi sửa candidate vẫn phải tuân theo contract asset ở trên.

## Boundary và kiểm thử

- `ContentMediaReferenceService::validate($html, $actor)` trả ID hợp lệ theo thứ tự; được Post request/action và candidate boundary gọi.
- `ContentMediaReferenceService::referencedIds($html)` chỉ đọc ref để cleanup bảo vệ file. Hàm này không xác thực quyền/URL và không dùng thay `validate()`.
- `ContentMediaReferenceService::isReferencedByRetainedAiRun($assetId, $exceptRunId = null)` kiểm candidate/source/input snapshot còn retention; optional excluded run dùng khi cleaner dọn chính run đó.
- `ContentMediaReferenceTest` kiểm URL giả/blob/base64, thiếu/sai/private/deleted/fileless asset, quyền attach, conversion chưa xong, thứ tự/dedup, lưu/reload/xóa usage và bảo vệ candidate/checkpoint. Tests dùng database cô lập, metadata file giả; không gọi AI và không cần GD.
- `MediaAssetUsageTest` bổ sung tình huống instance cũ sau soft-delete/hard-delete và chính sách khóa trong attach/replace. SQLite không phát SQL `FOR UPDATE`, nên test kiểm trạng thái query builder và transaction; database production thực thi khóa hàng.
