# Marketing Social Post API — Facebook v1

**Trạng thái:** Implemented — single workspace, image-first. Video chưa thuộc
phạm vi. **Cập nhật:** 16/10/2026.

Tài liệu này mô tả contract Facebook v1 sau migration variant. `SocialPost` là
bundle cha; `SocialPostVariant` giữ content Facebook và gallery ảnh ordered.
`caption` scalar vẫn được trả để tương thích dữ liệu cũ nhưng không còn là
contract platform mới. API dùng admin bearer token và envelope hiện có:

```json
{"success":true,"message":null,"data":{},"errors":[],"meta":[]}
```

## Quy tắc v1

- Single workspace: dữ liệu Social Post dùng chung cho admin hiện tại; chưa có
  `workspace_id` hoặc `tenant_id`.
- Social Post dùng bigint ID theo convention của Blog `Post` và MediaAsset usage;
  UUID chỉ dùng cho AI task/request bất đồng bộ.
- Facebook content nằm trong `facebook_variant.content` với title/body/
  bullet_points/CTA/contact/hashtags/link_url. Không có `platform_caption`.
- Facebook gallery nằm trong `facebook_variant.images`, tối đa 4 ảnh và cần ít
  nhất 3 ảnh ready để submit review.
- Text/logo/ngày tháng chính xác phải đến từ product facts hoặc ảnh gốc; AI không
  được tự tạo fact còn thiếu.
- Chỉ `review_status=approved` mới đủ điều kiện publish.
- Thay đổi caption hoặc asset sau approve đưa bài về `review_status=draft`.
- V1 chỉ có asset role `image`; video/storyboard chưa có endpoint.

## Permission

Marketing có permission cấp module và permission cấp resource. `marketing.view`
cho phép vào phạm vi Marketing/read catalog; `marketing.manage` là quyền quản lý
toàn bộ module dành cho admin vận hành. Route vẫn chấp nhận permission resource
chi tiết để giữ role cũ và hỗ trợ cấp quyền tối thiểu.

| Permission | Phạm vi |
| --- | --- |
| `marketing.view` | Xem phạm vi Marketing và catalog Product/Campaign/Social |
| `marketing.manage` | Quản lý toàn bộ Marketing, dùng cho admin vận hành |
| `social_posts.view` | Xem danh sách/chi tiết/version/publication |
| `social_posts.create` | Tạo draft và context |
| `social_posts.update` | Sửa caption, facts mapping, target và asset |
| `social_posts.delete` | Xóa draft chưa publish |
| `social_posts.review` | Submit review, approve, reject, request revision |
| `social_posts.publish` | Đưa bài approved vào publisher/schedule |
| `social_accounts.view` | Xem Page/account đã kết nối, không lộ token |
| `social_accounts.manage` | Connect/reconnect/disconnect/health check Page |

Các route Marketing chấp nhận `marketing.view` thay cho `social_posts.view` khi
đọc và `marketing.manage` thay cho permission lifecycle tương ứng khi quản lý.
Permission resource vẫn được giữ để cấp quyền tối thiểu cho từng nhóm thao tác.

Editor dùng create/update và regenerate; reviewer dùng review; publisher dùng
publish; admin quản lý kết nối Page. Super-admin hiện tại nhận toàn bộ catalog.

Mỗi resource Marketing mới phải khai báo permission riêng trong
`config/permissions.php`, thêm vào role seed nếu cần, gắn permission middleware
ở route và cập nhật bảng permission/tài liệu API cùng lúc. Không dùng
`marketing.manage` để thay thế permission lifecycle chi tiết khi resource cần
phân quyền độc lập.

Trong Ai Content, mỗi target resource có definition/adapter/schema/form riêng.
Form dùng chung chỉ quản lý nguồn và queue; Facebook content blocks, Product/
Campaign context, Writing Profile, reference assets và image gallery thuộc
definition `Social`, không thêm trực tiếp vào form Post chung.

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
| POST | `/{socialPost}/regenerate-caption` | Legacy compatibility; dùng `variants/facebook/generate-content` cho Facebook |
| POST | `/{socialPost}/regenerate-image` | Legacy compatibility; dùng `variants/facebook/generate-images` cho Facebook gallery |
| POST | `/{socialPost}/submit-review` | `social_posts.review`; chuyển sang pending review |
| POST | `/{socialPost}/approve` | `social_posts.review`; ghi actor/time/version |
| POST | `/{socialPost}/reject` | `social_posts.review`; bắt buộc reason |
| POST | `/{socialPost}/request-revision` | `social_posts.review`; bắt buộc reason |
| GET | `/{socialPost}/versions` | `social_posts.view`; snapshot caption/facts/prompt/asset |
| GET | `/{socialPost}/publications` | `social_posts.view`; trạng thái từng target |
| GET | `/{socialPost}/variants` | `social_posts.view`; content/gallery theo platform |
| PUT | `/{socialPost}/variants/facebook/content` | `social_posts.update`; sửa content blocks |
| POST | `/{socialPost}/variants/facebook/generate-content` | `social_posts.update`; queue content Facebook |
| POST | `/{socialPost}/variants/facebook/generate-images` | `social_posts.update`; queue gallery 3--4 ảnh |
| POST | `/{socialPost}/variants/facebook/images/replace` | `social_posts.update`; thay gallery theo asset IDs |
| POST | `/{socialPost}/variants/facebook/images/reorder` | `social_posts.update`; reorder usage IDs |
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
  "facebook_content": {
    "title": "Điểm nhấn hoàn hảo cho mọi chiếc bánh kem",
    "body": "Tag bánh kem giúp chiếc bánh thêm bắt mắt và chuyên nghiệp.",
    "bullet_points": ["Cắt bế chỉn chu", "In nét và màu tươi"],
    "cta": "Inbox để nhận mẫu thiết kế.",
    "contact": {"address": null, "phone": null, "website": null},
    "hashtags": ["#TagBanhKem"],
    "link_url": null
  },
  "facebook_image_ids": [31, 32, 33],
  "writing_profile_id": 12,
  "target_account_ids": [1],
  "generate": true
}
```

Có thể bỏ `facebook_content` để AI tạo content. `facebook_image_ids` là tùy chọn
khi chọn ảnh có sẵn; gallery thiếu 3 ảnh sẽ vẫn là draft và bị chặn ở review.
Target trùng account trong một Facebook variant bị từ chối `422`.

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

Facebook content task (`social_post.facebook_content`) trả:

```json
{
  "title": "...",
  "body": "...",
  "bullet_points": [],
  "cta": "...",
  "contact": {"address": null, "phone": null, "website": null},
  "hashtags": [],
  "link_url": null,
  "used_product_fact_ids": []
}
```

Facebook image task (`social_post.facebook_image`) trả:

```json
{
  "image_prompt": "...",
  "negative_prompt": "...",
  "aspect_ratio": "4:5",
  "slot_count": 3,
  "used_product_fact_ids": []
}
```

Content ready nhưng một hoặc nhiều slot ảnh lỗi giữ `generation_status=partial`;
regenerate content hoặc gallery không tạo Social Post mới. Mỗi binary image task
ghi `slot_no` và attach vào `social_post_variant.facebook.images` theo thứ tự.

## State contract

`generation_status`: `pending`, `generating`, `ready`, `partial`, `failed`.

`review_status`: `draft`, `pending_review`, `revision_requested`, `approved`,
`rejected`.

`publication_status`: `pending`, `scheduled`, `publishing`, `published`, `failed`.

Variant dùng `content_status`, `generation_status` và `review_status` riêng;
target/publication dùng `pending`, `queued`, `publishing`, `published`, `failed`,
`retryable`. Idempotency key là `social_post_id + social_account_id + generation_no`.

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

`GET/POST/PUT/DELETE /products` quản lý product, verified product facts và
`reference_asset_ids` (gallery ảnh sản phẩm, tối đa 20 ảnh); IDs này được kiểm
tra qua `MediaAssetUsageService` rồi allowlist vào Social AI context;
`GET/POST/PUT/DELETE /campaigns` quản lý campaign/product selection. Các endpoint
này dùng permission Social Post tương ứng và phục vụ context builder.

## Queue lifecycle

`POST /generate` chỉ ghi `AiImport`/`AiTaskRun` và trả `202`; worker content và
image prompt chạy độc lập. Image prompt ready fan-out 3--4 binary tasks; asset
chỉ attach sau khi bytes ảnh được validate. Task cũ không được ghi đè generation
mới. Content/image lỗi riêng giữ draft ở `partial`, còn publish chỉ chạy sau
`approved`, content ready và tối thiểu 3 ảnh gallery ready. Facebook publisher
upload ảnh unpublished rồi tạo feed post với `attached_media`; các Page target
được dispatch sau commit và retry cùng publication/idempotency key.

## Error codes MP-00

`platform_caption_not_supported`, `social_post_not_approved`,
`social_post_generation_pending`, `social_post_asset_not_ready`,
`social_target_duplicate`, `social_account_token_expired`,
`social_publication_duplicate`, `social_provider_unavailable`.
