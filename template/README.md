# Resource Template fixtures

- `stitch_full_page_ui_design/`: normalized package hiện tại. `template.json` là
  manifest canonical duy nhất; HTML screen ở root, assets nằm trong `assets/` và
  screenshot nằm trong `screenshots/`.
- `stitch_full_page_ui_design.zip`: archive normalized tương ứng với folder trên.
- Raw Stitch ZIP được giữ trong `tests/Fixtures/ResourceTemplate/raw-stitch/` để
  test discovery độc lập. Bản raw không có manifest và còn các thư mục
  `*_flowtask/code.html`.

Package có thể chứa Tailwind CDN, Google Fonts, Material Symbols và ảnh ngoài để
kiểm warning/diagnostics. Hệ thống không chạy HTML/JavaScript, build script,
npm, PHP hoặc network dependency trong bước xử lý package.
