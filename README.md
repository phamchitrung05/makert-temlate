# Market Template

Nền tảng bán tài nguyên số gồm public site Laravel và admin dashboard Vue 3 +
Vuetify chạy dưới `/admin`. Laravel là source of truth cho authentication,
authorization, dữ liệu và download; admin frontend dùng Sanctum Bearer token.

## Yêu cầu

- PHP 8.2+
- Composer
- Node.js và npm 10+
- SQLite (mặc định cho môi trường local) hoặc database tương thích Laravel

## Cài đặt local

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm ci
```

Trên PowerShell, thay lệnh sao chép bằng:

```powershell
Copy-Item .env.example .env
```

Nếu dùng SQLite và file chưa tồn tại, tạo `database/database.sqlite` trước khi
chạy migration. Chi tiết biến môi trường nằm ở
[docs/ENVIRONMENT.md](docs/ENVIRONMENT.md).

## Chạy ứng dụng

Mở hai terminal:

```sh
php artisan serve
npm run dev
```

Admin mở tại [http://localhost:8000/admin](http://localhost:8000/admin) khi
Laravel dùng port mặc định. Vite phục vụ asset ở port 5173.

## Kiểm tra API

Liveness endpoint không cần đăng nhập:

```sh
curl http://localhost:8000/api/health
```

Response có dạng:

```json
{
  "success": true,
  "message": null,
  "data": {
    "status": "ok",
    "service": "api",
    "timestamp": "2026-09-25T00:00:00+00:00"
  },
  "errors": [],
  "meta": {}
}
```

Các API dùng envelope chung do `App\Http\Responses\BaseResponse` tạo:

- Thành công: `success`, `message`, `data`, `errors`, `meta`.
- Lỗi: cùng envelope, `success=false` và chi tiết field nằm trong `errors`.
- Không có nội dung: dùng HTTP 204, body rỗng.

## Mock API local

Các route demo của template dùng MSW. Bật trong `.env` local:

```dotenv
VITE_ENABLE_MSW=true
```

Không bật MSW trong production; khi đó frontend phải gọi endpoint Laravel thật
qua `VITE_API_BASE_URL`. Xóa cache/service worker cũ nếu trình duyệt vẫn giữ
handler từ một phiên development trước.

## Kiểm thử và build

```sh
php artisan test
npm run build
```

## Quy ước dự án

- Kiến trúc và vị trí mở rộng Laravel/Vue 3 được ghi tại
  [docs/PROJECT_STRUCTURE.md](docs/PROJECT_STRUCTURE.md); tiến độ thực hiện nằm
  tại [docs/PLAN.md](docs/PLAN.md).
- Vue dùng Composition API và `<script setup>`; file `.vue` mới hoặc được chỉnh
  sửa phải có block comment theo [docs/PLAN.md](docs/PLAN.md#cấu-trúc-comment-bắt-buộc-cho-file-vue).
- CASL hiện tạm hoãn trong Vue; quyền thực tế luôn được kiểm tra ở Laravel.
- Không ghi access token, password hoặc secret vào source, comment hay README.
