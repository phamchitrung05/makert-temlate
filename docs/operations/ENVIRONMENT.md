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

2. Dùng database local theo `DB_CONNECTION` trong môi trường đang chạy (SQLite
   mặc định; đợt kiểm ngày 07/10/2026 đang dùng SQLite local):

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

## AI Content Agent

AI chỉ được gọi từ backend; không đưa API key hoặc endpoint secret vào bundle
Vue. Các file cấu hình được gom trong `config/ai/` và có trách nhiệm riêng:

| File | Nội dung |
| --- | --- |
| `config/ai/agent.php` | Target, quyền, các nhóm đầu ra được phép chọn (`targets.*.outputs`), nhãn nhóm (`output_definitions`), prompt và schema kết quả. Không lưu provider hoặc credential. |
| `config/ai/providers.php` | Driver/preset/adapter, khả năng model, giới hạn đồng bộ, provider mặc định và `connections` lấy từ môi trường. |
| `config/ai/import.php` | Bật/tắt pipeline, timeout đọc nguồn/job, giới hạn URL/HTML/ảnh, quota, retention và idempotency. Không lưu credential/model/provider. |
| `config/ai/content.php` | Pipeline ba bước, prompt/version, schema và quality gate kỹ thuật của luồng tạo bài. |
| `config/ai/quality.php` | Prompt/schema version và vòng đời queue của evaluator chấm chất lượng. |
| `config/ai/scoring.php` | Rubric, thang điểm, ngưỡng đạt và 5 tiêu chí chấm điểm; đây là nguồn cấu hình duy nhất cho điểm chất lượng. |
| `config/ai/task-runs.php` | Registry adapter cho tracker các tác vụ AI dùng chung. |

Các tên biến môi trường đang dùng được giữ nguyên; chỉ nơi đọc cấu hình được
chuyển về đúng file. Các biến giới hạn URL/job và quota có giá trị mặc định trong
`.env.example`:

| Nhóm | Biến |
| --- | --- |
| Pipeline | `AI_IMPORT_ENABLED` |
| Provider mặc định | `AI_IMPORT_PROVIDER` → `ai.providers.default_provider` |
| HTTP JSON | `AI_IMPORT_ENDPOINT`, `AI_IMPORT_KEY`, `AI_IMPORT_MODEL` → `ai.providers.connections.http-json` |
| OpenAI | `AI_OPENAI_KEY`, `AI_OPENAI_ENDPOINT`, `AI_OPENAI_MODEL`, `AI_OPENAI_TEMPERATURE` → `ai.providers.connections.openai` |
| Gemini | `AI_GEMINI_KEY`, `AI_GEMINI_ENDPOINT`, `AI_GEMINI_MODEL`, `AI_GEMINI_TEMPERATURE` → `ai.providers.connections.gemini` |
| Timeout/retry | `AI_PROVIDER_REQUEST_TIMEOUT`, `AI_IMPORT_TIMEOUT`, `AI_IMPORT_CONNECT_TIMEOUT`, `AI_IMPORT_JOB_TIMEOUT`, `AI_IMPORT_MAX_REDIRECTS` |
| Payload/file | `AI_IMPORT_MAX_HTML_BYTES`, `AI_IMPORT_MAX_IMAGE_BYTES`, `AI_IMPORT_USER_AGENT` |
| Prompt/lifecycle | `AI_IMPORT_PROMPT_VERSION`, `AI_IMPORT_RETENTION_DAYS`, `AI_IMPORT_IDEMPOTENCY_WINDOW_MINUTES` |
| Quality scoring | `AI_QUALITY_RUBRIC_VERSION`, `AI_QUALITY_PROMPT_VERSION`, `AI_QUALITY_SCHEMA_VERSION`, `AI_QUALITY_THRESHOLD`, `AI_QUALITY_RETENTION_DAYS`, `AI_QUALITY_REQUEST_TIMEOUT`, `AI_QUALITY_MAX_ATTEMPTS` |
| Quota | `AI_IMPORT_QUOTA_PER_HOUR` |

Provider/API key/model chỉnh trong AI Settings được lưu ở database. Bản ghi
`ai_providers` được ưu tiên so với `connections` cùng key; provider trong database
bị tắt không tự quay về connection môi trường. API capability chỉ trả metadata
đã lọc, không trả credential hoặc endpoint. Driver/preset dùng một định nghĩa
adapter chung cho cả connection database và connection môi trường.

`config/ai/providers.php` có `request_timeout` làm mặc định cho provider mới,
có thể override bằng `AI_PROVIDER_REQUEST_TIMEOUT`. Giá trị lưu riêng trong
`ai_providers.request_timeout` được ưu tiên (5–600 giây). Provider hiện tại
nhận 120 giây từ migration và có thể sửa bằng AI Settings. Thay đổi config trên
môi trường dùng config cache cần cập nhật cache và restart worker.
Database/Redis/Beanstalkd có `retry_after` tối thiểu 900 giây; job AI đọc thời gian
chờ trong snapshot, cộng 120 giây cho xử lý nguồn/lưu kết quả. Kết nối TCP vẫn
có giới hạn 5 giây; trường provider kiểm soát tổng thời gian chờ HTTP mỗi request.

Khi `AI_IMPORT_ENABLED=false`, pipeline import bị tắt thật (không dùng cast
boolean của chuỗi môi trường). Laravel AI SDK chưa được cài trong môi trường
PHP 8.2 hiện tại; chỉ bật sau khi runtime đáp ứng PHP 8.3 và đã kiểm thử provider.
API key được lưu mã hóa trong database khi chỉnh qua AI Settings hoặc lấy từ
`.env` phía Laravel khi dùng connection môi trường; không đưa vào `VITE_*`,
response capability hoặc bundle Vue. Provider `deterministic` trong `connections`
được dùng cho local/test khi chọn chế độ trích xuất không gọi AI bên ngoài.

## Kho bài AI gốc v1

Migration ngày 07/10/2026 tạo `ai_article_archives` và các cột lifecycle của run.
Đã chạy trên SQLite local (batch 15); triển khai lên host cần chạy migration bằng
luồng deploy hiện có, sau đó nạp lại queue worker qua process manager của host.
Kho chỉ lưu bản Post AI được duyệt/Apply thành công. Tạo bài giữ checkpoint tạm
trong danh sách Content AI; không tự backfill candidate cũ thành bản AI gốc.

Run/checkpoint chưa chọn vẫn mặc định 2 ngày; archive approved không bị cleanup run xóa và chưa có lịch
tự xóa riêng. Bảo đảm backup database gồm bảng archive. Không rollback migration
này trên dữ liệu cần giữ vì rollback xóa kho/checkpoint; ưu tiên migration sửa tiến.

Kiểm kê/phục hồi có giới hạn, không gọi provider:

```powershell
php artisan ai-articles:archive --run-id=<UUID> --dry-run
php artisan ai-articles:archive --run-id=<UUID>
php artisan ai-articles:archive --include-legacy --limit=100 --dry-run
```

Command chỉ chọn run đã approved và phục hồi archive từ checkpoint hợp lệ.
Legacy approved cần `--include-legacy`, thiếu bằng chứng giữ content null và nhãn
`legacy_unverified`. Duyệt candidate cũ cũng dùng nhãn này, không giả original.
`ai-import:cleanup` xóa candidate chưa duyệt/từ chối/lỗi cùng checkpoint tạm;
bản approved v1 thiếu/lỗi kho được giữ lại, trả exit code 1 để phục hồi trước khi
dọn tiếp. Worker đang bận được bỏ qua an toàn. Evaluator/lịch/báo cáo chưa triển khai.
[Bằng chứng và chi tiết vận hành](../qa/AI_ARTICLE_ARCHIVES_2026-10-07.md).

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

### Spatie Laravel Settings

Package `spatie/laravel-settings` 3.9.0 chạy trên PHP 8.2/Laravel 12 hiện tại.
Sau `composer install`, chạy `php artisan migrate` để chuyển schema settings và
thêm các property nhóm AI còn thiếu. Không publish migration tạo bảng mặc định
của package vì project đã có migration chuyển bảng hiện tại. Bảng
`legacy_settings` giữ giá trị/type/actor cũ để đối chiếu và rollback.
Settings cache tắt; service refresh trước đọc/lưu. Restart worker sau deploy để
worker nạp code/settings class mới. API key và thời gian chờ riêng của provider
vẫn thuộc `ai_providers`; global defaults/fallback/temperature/legacy timeout
thuộc `App\Settings\AiSettings`.

- Đặt `APP_ENV=production`, `APP_DEBUG=false` và `VITE_ENABLE_MSW=false`.
- Chạy `php artisan config:cache` sau khi nạp secret của môi trường.
- Dùng HTTPS, rotate token/secret theo chính sách vận hành.
- Chạy `npm run build` và phục vụ asset qua Laravel/Vite manifest.
- Xác nhận `/api/health` trả `200` và không expose thông tin nhạy cảm.
