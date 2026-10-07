# Upload logo/favicon trong Settings

Đã triển khai ngày 2026-10-06. Giao diện: **Settings → Tổng quan → Thương hiệu website**.

## HTTP và quyền

Giữ endpoint `PATCH /api/admin/settings/site` và version nhóm `site` hiện có.
Upload từ browser dùng multipart **POST** cùng endpoint, có `_method=PATCH` để PHP
đọc file. Middleware Sanctum admin/account active và quyền `settings.manage`
bảo vệ thao tác ghi. Quyền `settings.view` chỉ đọc; không cần quyền Media upload.

| Field | Contract |
| --- | --- |
| `version` | Bắt buộc, version đã đọc từ Settings |
| `logo_file` | Tùy chọn, ảnh PNG/JPG/JPEG/WebP, tối đa 2048 KB, tối đa 4096 × 4096 px |
| `favicon_file` | Tùy chọn, PNG vuông, 16–512 px, tối đa 512 KB |
| `remove_logo`, `remove_favicon` | Boolean tùy chọn; gỡ để dùng branding mặc định |

Không gửi file và cờ gỡ true cho cùng loại ảnh. Các field thông tin site vẫn được
lưu cùng request/version. File SVG/ICO không nằm trong allowlist upload này.
Backend kiểm nội dung ảnh/MIME, dung lượng và dimensions; không tin extension
hoặc tên file do client cung cấp.

Gỡ ảnh không có upload tiếp tục dùng JSON PATCH:

```json
{ "version": 3, "remove_logo": true, "remove_favicon": true }
```

## Dữ liệu và response

- Bảng `settings`, group `site`, name `logo_path` và `favicon_path`: path nullable,
  dùng Spatie typed settings. Không thêm bảng branding riêng.
- Disk `public`: `branding/logo/<uuid>.<extension>` và
  `branding/favicon/<uuid>.png`. Cần link public/storage của project khi triển khai.
- DTO `sections.site.values`/response save thêm `logo_url`, `logo_configured`,
  `favicon_url`, `favicon_configured`, `favicon_type`; không trả path nội bộ.
- Không có logo custom: `logo_url=null`, theme dùng SVG mặc định và public dùng
  tên website. Favicon fallback là tài nguyên `favicon.ico` của project.

Version cũ trả 409 trước khi ghi file. Validation trả 422. Writer khóa nhóm,
lưu file/path/version/audit; rollback dọn file mới, commit mới dọn file cũ.
Audit ghi tên key, không ghi bytes/tên gốc upload. URL UUID mới tránh dùng lại
cache ảnh cũ; branding SPA chỉ thay sau response save thành công.

## UI và runtime

Chọn file tạo preview blob, không gọi API. Đổi tab giữ file/draft; Hoàn tác và
unmount thu hồi blob. Lưu lỗi hoặc conflict giữ ảnh đang chọn để sửa/tải lại.
Logo dùng trên header public, loader admin và mọi vị trí themeConfig logo.
Favicon dùng trên Blade public/admin và cập nhật ngay trong SPA sau save.

Migration: `database/settings/2026_10_06_180000_add_site_branding.php`; chỉ thêm hai
property null, không ghi đè cấu hình website. Rollback property giữ file vật lý.
Kiểm chứng và screenshot: [Branding QA](../qa/SITE_BRANDING_2026-10-06/README.md).
