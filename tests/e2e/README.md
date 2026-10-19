# E2E với backend thật

`npm run test:e2e` build Vue với `/api` và MSW tắt, migrate/seed SQLite trong thư mục tạm, rồi chạy PHP server riêng tại `127.0.0.1:8179`. Không tái sử dụng server đang chạy. Database, storage và cache cấu hình của project không được dùng cho fixture.

Yêu cầu PHP trên PATH (hoặc `PHP_BINARY`), dependencies Composer/npm và Chromium của Playwright:

```sh
npx playwright install chromium
npm run test:e2e
```

Windows có thể dùng Edge đã cài, không cần tải Chromium:

```powershell
$env:PLAYWRIGHT_CHANNEL = 'msedge'
npm run test:e2e
```

Test đăng nhập bằng API thật, thao tác filter/phân trang, mở dialog tạo draft và xác nhận dữ liệu sau reload. API admin không bị mock. Worker không chạy; HTTP provider bị chặn nên không tạo bài Facebook hoặc tính phí AI. Fixture tạm được dọn sau khi chạy. Trace và screenshot chỉ lưu khi lỗi trong `test-results/`.

Các kiểm tra còn lại: `npm run test:run` và PHPUnit. E2E hiện kiểm Chromium/Edge trên desktop; chưa bao phủ toàn bộ màn hình hay mọi trình duyệt.
