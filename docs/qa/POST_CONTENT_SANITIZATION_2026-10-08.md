# Post HTML sanitization P-03 — 2026-10-08

## Phạm vi

- Create và update Post đều kiểm URL ảnh rồi sanitize HTML ở backend trước khi
  ghi database.
- Allowlist giữ markup semantic, bảng, code, link, ảnh, figure/caption và
  `data-media-asset-id` hợp lệ.
- Loại bỏ script, iframe, SVG/nhúng thực thi, event handler và attribute ngoài
  allowlist; CSS chỉ giữ `text-align` với giá trị cố định. Event handler trên
  `img` và URL `javascript:`, `data:`, `blob:`, `file:` và protocol-relative của
  ảnh bị từ chối bởi contract URL hiện có.
- Sanitizer dùng chung tại `app/Services/Content/ContentHtmlSanitizer.php`.
  `AiContentSanitizer` giữ lớp tương thích cho pipeline AI.

## Kiểm chứng

- `PostContentSanitizationTest`: **2 tests / 15 assertions** đạt.
- Regression Post: `PostGalleryTest`, `PostSeoSlugTest`, `PostWorkflowTest` và
  `ArticleGenerationPipelineTest`: **51 tests / 442 assertions** đạt.
- P-03 không render HTML mới ở frontend; dữ liệu được làm sạch server-side trước
  khi response Post được trả về.

## Ranh giới vận hành

`ContentImageUrlValidator` kiểm contract URL, cấu trúc `img` và từ chối event
handler trên ảnh; các attribute/event nguy hiểm ở node khác do sanitizer loại bỏ. Media Library usage vẫn được
quản lý riêng, không tự suy ra quan hệ từ ảnh URL trong content.
