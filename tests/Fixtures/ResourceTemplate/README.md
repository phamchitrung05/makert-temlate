# Fixture TPL-00 đã đóng băng

- `normalized/package.zip`: snapshot package FlowTask đã gom/link, 18 screen,
  chỉ có `flowtask/template.json` làm manifest, paths ZIP dùng `/`.
- `raw-stitch/package.zip`: sao chép nguyên bytes từ
  `template/stitch_full_page_ui_design.original.zip`, 9 screen `code.html`,
  không có manifest; checksum được lưu riêng.
- `expected/normalized-manifest.json`: manifest canonical được chốt từ package.
- `expected/raw-discovery.json`: output discovery mong đợi cho TPL-03; entry
  null và cần admin chọn. Đây chưa phải manifest ready v1.
- `manifest-cases.json`: input hợp lệ, sai schema, unsafe path và sai quan hệ;
  dùng chung để kiểm PHP validator và JSON Schema.
- `invalid-screenshots/placeholder-*.png`: 5 synthetic fixture 28 byte có đuôi
  PNG nhưng nội dung text. Mẫu weather cũ trong plan không tồn tại local;
  không trình bày các file này như screenshot gốc đã được phục hồi.
- `expected/inventory.json`: size/SHA-256 của baseline; tests chỉ đọc và so sánh,
  không tự tạo lại expected để che thay đổi.

Fixture raw có một wrapper folder `stitch_full_page_ui_design/`. Sau khi bỏ
wrapper, screen paths vẫn giữ cây thư mục `calendar_flowtask/code.html`...
`DESIGN.md` là metadata phụ, không tính thành screen. Slug fallback giữ tên
folder, thay `_` bằng `-`; không hardcode xóa brand/suffix hay chọn home.

Test không đọc thư mục `template/` đang được người dùng chỉnh sửa. Hai snapshot
ở đây là nguồn baseline; các ZIP/folder local trong `template/` vẫn giữ nguyên.
Rebuild chỉ chạy chủ động khi đổi baseline và phải review expected/hash diff:

```powershell
php -r 'require "vendor/autoload.php"; (new Tests\Fixtures\ResourceTemplate\FixtureBuilder)->build(getcwd());'
```

Không thực thi JS/HTML, không tải external dependencies và không giải nén ZIP
ra thư mục ứng dụng trong bước tạo fixture. Processing/parser/preview thật
được triển khai ở các TPL tiếp theo.
