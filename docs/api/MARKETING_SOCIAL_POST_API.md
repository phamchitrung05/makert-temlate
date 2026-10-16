# Marketing Social Post API — MVP implementation

**Trạng thái:** Implemented — single workspace, image-first. Video chưa thuộc
phạm vi. **Cập nhật:** 16/10/2026.

Tài liệu này chốt dữ liệu canonical cho Social Post trước khi tạo migration.
Một Social Post có một caption dùng chung cho mọi target; mỗi Page/account có
target và publication status riêng. API dùng admin bearer token và envelope hiện
có:

```json
{"success":true,"message":null,"data":{},"errors":[],"meta":[]}
```

## Quy tắc v1

- Single workspace: dữ liệu Social Post dùng chung cho admin hiện tại; chưa có
  `workspace_id` hoặc `tenant_id`.
- Social Post dùng bigint ID theo convention của Blog `Post` và MediaAsset usage;
  UUID chỉ dùng cho AI task/request bất đồng bộ.
- `caption` là field canonical duy nhất. Request không có `platform_caption`;
  backend không tạo caption riêng theo platform.
- Text/logo/ngày tháng chính xác phải đến từ product facts hoặc ảnh gốc; AI không
  được tự tạo fact còn thiếu.
- Chỉ `review_status=approved` mới đủ điều kiện publish.
- Thay đổi caption hoặc asset sau approve đưa bài về `review_status=draft`.
- V1 chỉ có asset role `image`; video/storyboard chưa có endpoint.

## Permission

| Permission | Phạm vi |
| --- | --- |
| `social_posts.view` | Xem danh sách/chi tiết/version/publication |
| `social_posts.create` | Tạo draft và context |
| `social_posts.update` | Sửa caption, facts mapping, target và asset |
| `social_posts.delete` | Xóa draft chưa publish |
| `social_posts.review` | Submit review, approve, reject, request revision |
| `social_posts.publish` | Đưa bài approved vào publisher/schedule |
| `social_accounts.view` | Xem Page/account đã kết nối, không lộ token |
| `social_accounts.manage` | Connect/reconnect/disconnect/health check Page |

Editor dùng create/update và regenerate; reviewer dùng review; publisher dùng
publish; admin quản lý kết nối Page. Super-admin hiện tại nhận toàn bộ catalog.

## Social Post endpoints

Prefix: `/api/admin/social-posts`.

| Method | Endpoint | Quyền / kết quả |
| --- | --- | --- |
| GET | `/` | `social_posts.view`; filter `review_status`, `campaign_id`, `target`, `page` |
| POST | `/` | `social_posts.create`; tạo draft canonical |
| GET | `/{socialPost}` | `social_posts.view`; trả target/publication/version |
| PUT | `/{socialPost}` | `social_posts.update`; sửa draft hoặc revision |
| DELETE | `/{socialPost}` | `social_posts.delete`; chỉ draft chưa publish |
| POST | `/{socialPost}/generate` | `social_posts.create/update`; trả `202` và task IDs |
| POST | `/{socialPost}/regenerate-caption` | `social_posts.update`; tạo version caption mới |
| POST | `/{socialPost}/regenerate-image` | `social_posts.update`; tạo image task mới |
| POST | `/{socialPost}/submit-review` | `social_posts.review`; chuyển sang pending review |
| POST | `/{socialPost}/approve` | `social_posts.review`; ghi actor/time/version |
| POST | `/{socialPost}/reject` | `social_posts.review`; bắt buộc reason |
| POST | `/{socialPost}/request-revision` | `social_posts.review`; bắt buộc reason |
| GET | `/{socialPost}/versions` | `social_posts.view`; snapshot caption/facts/prompt/asset |
| GET | `/{socialPost}/publications` | `social_posts.view`; trạng thái từng target |
| POST | `/{socialPost}/publish` | `social_posts.publish`; chỉ approved và asset ready |
| POST | `/../social-publications/{publication}/retry` | `social_posts.publish`; retry đúng publication/idempotency key |

### Tạo draft

```http
POST /api/admin/social-posts
Content-Type: application/json
```

```json
{
  "product_id": 1,
  "campaign_id": null,
  "caption": null,
  "writing_profile_id": 12,
  "target_account_ids": [1],
  "generate": true
}
```

`caption=null` cho phép tạo draft chờ AI. Khi có caption, đây là một string
dùng cho tất cả target; không nhận map theo platform. Target trùng account trong
một Social Post bị từ chối `422`.

### Kết quả generation

`POST /generate` trả `202`:

```json
{
  "success": true,
  "data": {
    "social_post_id": 101,
    "generation_status": "generating",
    "caption_task_id": "uuid",
    "image_task_id": "uuid"
  }
}
```

Caption task (`social_post.caption`) trả:

```json
{
  "caption": "...",
  "cta": "...",
  "hashtags": [],
  "used_product_fact_ids": []
}
```

Image prompt task (`social_post.product_image`) trả:

```json
{
  "image_prompt": "...",
  "negative_prompt": "...",
  "aspect_ratio": "4:5",
  "used_product_fact_ids": []
}
```

Caption ready nhưng ảnh lỗi giữ `generation_status=partial`; regenerate từng
phần không tạo Social Post mới.

## State contract

`generation_status`: `pending`, `generating`, `ready`, `partial`, `failed`.

`review_status`: `draft`, `pending_review`, `revision_requested`, `approved`,
`rejected`.

`publication_status`: `pending`, `scheduled`, `publishing`, `published`, `failed`.

Target/publication dùng `pending`, `queued`, `publishing`, `published`, `failed`,
`retryable`. Idempotency key là `social_post_id + social_account_id`.

## Social account endpoints

Prefix: `/api/admin/social-accounts`.

| Method | Endpoint | Quyền / kết quả |
| --- | --- | --- |
| GET | `/` | `social_accounts.view`; token không xuất hiện response |
| POST | `/facebook/connect` | `social_accounts.manage`; bắt đầu Page OAuth |
| GET | `/facebook/callback` | callback server-side, tách customer login |
| POST | `/{socialAccount}/reconnect` | `social_accounts.manage`; cập nhật token mã hóa |
| POST | `/{socialAccount}/disconnect` | `social_accounts.manage`; vô hiệu hóa account |

OAuth Page cần Meta App credentials trong môi trường backend. Token được mã hóa,
có expiry/scopes và không ghi log hoặc trả về browser.

## Context/product/campaign endpoints

Prefix: `/api/admin/marketing`.

`GET/POST/PUT/DELETE /products` quản lý product và verified product facts;
`GET/POST/PUT/DELETE /campaigns` quản lý campaign/product selection. Các endpoint
này dùng permission Social Post tương ứng và phục vụ context builder.

## Queue lifecycle

`POST /generate` chỉ ghi `AiImport`/`AiTaskRun` và trả `202`; worker caption và
image prompt chạy độc lập. Image prompt ready sẽ tạo thêm image binary task; asset
chỉ attach sau khi bytes ảnh được validate. Task cũ không được ghi đè generation
mới. Caption/image lỗi riêng giữ draft ở `partial`, còn publish chỉ chạy sau
`approved` và `generation_status=ready`.

## Error codes MP-00

`platform_caption_not_supported`, `social_post_not_approved`,
`social_post_generation_pending`, `social_post_asset_not_ready`,
`social_target_duplicate`, `social_account_token_expired`,
`social_publication_duplicate`, `social_provider_unavailable`.
