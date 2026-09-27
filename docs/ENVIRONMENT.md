# Environment guide

Tài liệu này mô tả các biến cần dùng cho local, test và production. Không commit
file `.env`; chỉ commit `.env.example` với giá trị mẫu không nhạy cảm.

## Local

1. Tạo file môi trường:

   ```sh
   cp .env.example .env
   php artisan key:generate
   ```

   PowerShell:

   ```powershell
   Copy-Item .env.example .env
   php artisan key:generate
   ```

2. Dùng database local theo `DB_CONNECTION` trong `.env.example` (SQLite mặc
   định; môi trường hiện tại đang dùng MySQL local `makert-template`):

   ```sh
   # SQLite
   touch database/database.sqlite
   php artisan migrate --seed

   # MySQL: tạo database/user tương ứng trước, sau đó chạy cùng lệnh migrate
   php artisan migrate --seed
   ```

3. Bật mock API để các route demo Vue có dữ liệu:

   ```dotenv
   VITE_ENABLE_MSW=true
   VITE_API_BASE_URL=
   ```

   Sau khi đổi biến `VITE_*`, khởi động lại Vite vì Vite nạp các biến này khi
   khởi động dev server.

## Biến chính

| Biến | Local | Production |
| --- | --- | --- |
| `APP_KEY` | Tạo bằng `php artisan key:generate` | Secret duy nhất của môi trường, không commit |
| `APP_URL` | URL Laravel local | HTTPS domain thật |
| `DB_CONNECTION` | `sqlite` hoặc driver local | Driver/database managed |
| `VITE_API_BASE_URL` | Trống để gọi cùng origin `/api` | URL API thật nếu frontend tách origin |
| `VITE_ENABLE_MSW` | `true` khi cần route demo | `false` |
| `VITE_MAPBOX_ACCESS_TOKEN` | Chỉ cần cho màn hình dùng Mapbox | Token giới hạn domain |

## Sanctum admin

Admin đăng nhập qua `POST /api/admin/login` và nhận Bearer token. Vue lưu token
trong `sessionStorage`; không cấu hình admin session hoặc đặt token vào comment,
README hay cookie tự tạo. Endpoint kiểm tra phiên là `GET /api/admin/me`.

## Health check

`GET /api/health` là liveness endpoint công khai, không cần Bearer token:

```sh
curl -i http://localhost:8000/api/health
```

Endpoint chỉ xác nhận API process đang phản hồi; readiness của database, queue và
external provider cần được giám sát bằng check riêng khi triển khai production.

## Production checklist

- Đặt `APP_ENV=production`, `APP_DEBUG=false` và `VITE_ENABLE_MSW=false`.
- Chạy `php artisan config:cache` sau khi nạp secret của môi trường.
- Dùng HTTPS, rotate token/secret theo chính sách vận hành.
- Chạy `npm run build` và phục vụ asset qua Laravel/Vite manifest.
- Xác nhận `/api/health` trả `200` và không expose thông tin nhạy cảm.
