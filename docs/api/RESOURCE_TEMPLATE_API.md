# Resource Template API (TPL-06/TPL-09/TPL-10)

Các endpoint admin ở đây dùng envelope `success/data/errors/meta` và nằm dưới
`/api/admin`. Request cần Sanctum admin token, ability `admin`, account active và
permission `resource_versions.manage`.

## Admin processing và lifecycle

| Method | Endpoint | Kết quả |
| --- | --- | --- |
| `GET` | `/api/admin/resource-versions/{id}/template` | Processing state, screen list, warnings và capability bounded |
| `POST` | `/api/admin/resource-versions/{id}/template/process` | `202`, tracker queued/processing; `force` là boolean tùy chọn |
| `PATCH` | `/api/admin/resource-versions/{id}/template` | Draft entry/title/order/link mapping; screen id phải thuộc version |
| `POST` | `/api/admin/resource-versions/{id}/template/preview` | Grant preview ngắn hạn `{url, expires_at}` |
| `POST` | `/api/admin/resource-versions/{id}/template/screens/{screen}/preview` | Gắn một image MediaAsset vào `template_screen.preview` |
| `POST` | `/api/admin/resource-versions/{id}/promote` | Promote version ready thành default |
| `POST` | `/api/admin/resource-versions/{id}/rollback` | Promote version ready non-default thành default |
| `POST` | `/api/admin/resource-versions/{id}/archive` | Archive version không phải default và không có retention cần giữ |

`GET .../template` không trả `preview_path`, absolute storage path, ZIP source,
raw manifest lớn hoặc exception stack. `capabilities.can_ready` chỉ là gợi ý UI;
action ready vẫn kiểm tra lại dưới lock.

`PATCH` nhận:

```json
{
  "entry_screen_slug": "home",
  "screens": [
    {"id": 10, "title": "Home", "sort_order": 0,
     "metadata": {"link_mapping": ["login", "settings"]}}
  ]
}
```

Danh sách screen phải chứa đúng toàn bộ screen của version một lần. Settings bị
khóa khi version ready/archive; không có `is_default` trong create/update. Promote
và rollback là mutation duy nhất đổi default.

## Preview origin

Grant trả URL `/template-preview/{token}` (hoặc origin riêng từ
`TEMPLATE_PREVIEW_ORIGIN`). Host chọn screen bằng query `?screen=slug`; file được
serve dưới `/template-preview/{token}/site/{relative-path}`. Token chỉ lưu hash,
kiểm expiry/revoke/capability/build checksum ở mỗi request. Static path phải nằm
trong `manifest_json.files`, dùng extension MIME allowlist và artifact private.
Preview trả `Cache-Control: private, no-store`, `nosniff`, `no-referrer`, `noindex`
và CSP `sandbox allow-scripts`; iframe không bật same-origin/forms/popups.

## Public catalog

- `GET /resources` chỉ query Resource `published + public + published_at <= now`,
  type `template`, có slug primary hiện tại.
- `GET /resources/{slug}` resolve slug cũ và trả redirect 301 về primary.
- `GET /resources/{slug}/preview` resolve default tại thời điểm mở trang.
- `GET /resources/{slug}/preview/{version}` ghim version ready cụ thể; không tự
  đổi sang latest.
- `GET /sitemap.xml` chỉ chứa URL primary của template public/indexable và có
  `lastmod` bounded; `robots.txt` thêm URL sitemap nếu Settings chưa có.

Draft/processing/failed/archive hoặc package scan không clean không xuất hiện trong
card/detail/preview.

## Download và share

Policy mặc định ở config là customer active đã verify email, tối đa 20 lượt/ngày,
grant download 5 phút; phiên bản và `resource_version_id` được ghim khi cấp URL.

- `GET /resources/{slug}/download/{version?}` yêu cầu `auth:customer` và
  `account.active:customer`, tạo `downloads` atomic rồi redirect grant.
- `GET /template-download/{token}` resolve bearer grant và stream ZIP private;
  chuyển `started -> completed` một lần, retry không tăng counter.
- Admin `POST /api/admin/resource-versions/{id}/template/shares` nhận
  `capability=preview|download`, `days=1..30`; raw token chỉ có trong URL response.
- Admin `DELETE /api/admin/template-shares/{uuid}` revoke ngay.
- `GET /template-shares/{token}` chỉ cấp capability đã ghi; preview share không
  tự có quyền download.

Share/download token không log raw token. Hết hạn, revoke, version archive, resource
ẩn hoặc checksum/build thay đổi đều fail tại request tiếp theo.
