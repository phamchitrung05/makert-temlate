# Kế hoạch Marketing Social Post

**Cập nhật:** 20/10/2026
**Trạng thái tổng:** IN PROGRESS — platform variant, structured Facebook content
và ordered gallery đã có vertical slice; MP-03 đã nối AI Content vào queue Social
và kiểm thử local. Smoke test Facebook/provider ảnh thật vẫn thuộc MP-00/MP-08.
**Phạm vi đợt đầu:** Facebook-first. Một Social Post là bundle nội dung xã hội;
mỗi nền tảng có một cấu trúc riêng. Facebook hiện gồm content có cấu trúc và 1–10
ảnh theo số lượng được chọn. AI dùng nguồn/campaign, Writing Profile và image profile;
Social không phụ thuộc Product. Video được để dành cho phase TikTok sau này và không
thuộc schema, UI, queue hay acceptance của Facebook v1.

**Đối chiếu 20/10:** các mô tả Product/fact IDs và 3–4 ảnh trong phần thiết kế
lịch sử bên dưới đã được thay thế bởi phạm vi MP-03 hiện tại; không khôi phục
dependency Product khi tiếp tục các phase sau.

## 1. Mục tiêu và quyết định phạm vi

Marketing Social Post là domain riêng trong Admin, không dùng lại model `Post`
đang phục vụ Blog. `SocialPost` là bản ghi cha chứa campaign/product và
lifecycle chung. Mỗi nền tảng có một `SocialPostVariant` với content contract
riêng; nhiều tài khoản cùng nền tảng có thể dùng chung variant đó.

Đợt đầu triển khai:

- Tạo 2--3 Social Post bundle draft mỗi ngày theo campaign/product.
- Facebook variant có content gồm title/body/bullet points/CTA/contact/hashtags.
- AI tạo content Facebook theo Writing Profile, product facts và campaign context.
- AI tạo 3--4 ảnh sản phẩm theo prompt + product context, lưu qua MediaAsset và
  giữ thứ tự gallery.
- Người dùng chỉnh sửa, regenerate, submit review, approve hoặc reject.
- Chỉ variant đã approve mới được đưa vào publish.
- Tích hợp publisher Facebook trước; schema và contract giữ khả năng thêm nền tảng.
- Cho phép tạo thủ công từ tài nguyên `Social` trong Ai Content để editor có thể
  tạo content/image run ngoài scheduler.
- Instagram/TikTok và các cấu trúc content riêng của chúng để phase sau; video
  sẽ được thiết kế cùng TikTok thay vì thêm vào Facebook v1.

Không dùng field scalar `platform_caption`. Content platform-specific nằm trong
`SocialPostVariant.content_json`; trạng thái publish, external ID, lỗi và retry
vẫn tách theo từng target/account.

## 2. Đối chiếu với project hiện tại

| Thành phần | Trạng thái hiện tại | Cách dùng cho Social Post |
| --- | --- | --- |
| Laravel/Vue/Sanctum/permission | Đã có | Giữ nguyên foundation và API envelope |
| AI provider registry và queue | Đã có | Dùng lại `AiImport`, `AiTaskRun`, provider/model snapshot |
| Ai Content resource catalog | Đã có mục `social_post`/`Social`, adapter và prompt còn MVP caption | Nâng cấp resource để tạo thủ công structured Facebook content/image run |
| Writing Profile | Đã có | Dùng làm lớp giọng điệu, không biến thành prompt ảnh |
| AI image generation | Đã có một output/job | Mở rộng fan-out 3--4 image slots, retry từng slot và giữ thứ tự gallery |
| Media Library | Đã có usage polymorphic | Thêm field multiple cho 3--4 Facebook images theo thứ tự |
| Blog `Post` workflow | Đã có | Chỉ tham khảo state/action; không gắn Facebook vào `posts` |
| Facebook Customer OAuth | Đã có | Chỉ là login customer, không đủ để quản lý Page/publish |
| Product/Campaign backend | Đã có MVP | Bổ sung product reference assets và contract facts bắt buộc trước khi gọi AI |
| Social publisher/account/publication | Đã có MVP Facebook | Đối chiếu gallery media Facebook, retry sau commit và smoke test Page thật |
| Daily scheduler | Đã có command + daily registry | Chuyển từ tạo caption/ảnh đơn sang tạo bundle Facebook variant |
| Social Post hiện tại | Có parent, caption scalar, một image usage | Cần rebaseline sang variant platform + Facebook content/media bundle |

Các module hiện tại nằm tại [PROJECT_INVENTORY](../architecture/PROJECT_INVENTORY.md),
[config/ai/agent.php](../../config/ai/agent.php),
[AiImageGenerationService.php](../../app/Services/Ai/Images/AiImageGenerationService.php)
và [ProcessAiImageGenerationJob.php](../../app/Jobs/ProcessAiImageGenerationJob.php).

### 2.1. Khoảng trống đối chiếu với Facebook Post mẫu

Ảnh mẫu mô tả một Facebook feed post có Page header, content nhiều khối và
gallery media. Code hiện tại đã có lifecycle/queue/provider cơ bản nhưng chưa
đủ contract mới:

| Hạng mục | Code hiện tại | Phần cần bổ sung |
| --- | --- | --- |
| Content | `SocialPost.caption` là một chuỗi đã ghép caption/CTA/hashtag | `SocialPostVariant.content_json` theo schema Facebook: title, body, bullets, CTA, contact, hashtags, link |
| Writing Profile | Đã snapshot vào AI context | Bắt buộc gắn profile vào Facebook content run và cho phép editor chọn profile |
| Product grounding | Có product name/description/facts và usage media polymorphic | Reference asset IDs được allowlist và snapshot để AI ảnh dùng đúng sản phẩm |
| Images | `social_post.image` single | 3--4 usage ordered cho Facebook gallery, mỗi slot có status/retry riêng |
| Platform model | Target có `platform`, content vẫn nằm trên parent | Variant theo platform; target/account trỏ tới variant tương ứng |
| Preview | Card caption + một ảnh | Facebook preview gồm header, content và gallery 3--4 ảnh |
| Publish | Facebook photo publisher MVP | Publish structured content + gallery 3--4 ảnh và smoke test thật |

### 2.2. Chiến lược chuyển đổi từ MVP hiện tại

Không xóa dữ liệu Social Post hiện tại. Rebaseline theo thứ tự:

1. Tạo `social_post_variants` và tạo một Facebook variant cho mỗi Social Post
   hiện có.
2. Backfill `social_posts.caption` vào `content_json.body`; giữ nguyên caption
   cũ để rollback/đối chiếu trong migration đầu tiên.
3. Chuyển usage `social_post.image` hiện tại thành image slot đầu tiên của
   Facebook variant; không tự bịa thêm ảnh còn thiếu.
4. Thêm target/route/resource theo variant, sau đó chuyển UI/API create,
   regenerate, review và publish sang variant contract.
5. Chỉ sau khi backfill và acceptance test đạt mới deprecate scalar caption và
   usage field cũ; không sửa migration đã chạy.

`docs/api/MARKETING_SOCIAL_POST_API.md` hiện vẫn mô tả contract caption + một
ảnh của MVP cũ; phải cập nhật cùng MP-00 trước khi code variant mới.

## 3. Mô hình nghiệp vụ

```text
Campaign / Product
        |
        v
   SocialPost (common identity/lifecycle)
   - campaign/product
   - generation/review/publication aggregate
   - schedule/audit
        |
        +--> SocialPostVariant: Facebook
        |    - structured content
        |    - 3--4 ordered images
        |          |
        |          +--> SocialPostTarget: Facebook Page A
        |          +--> SocialPostTarget: Facebook Page B
        |
        +--> SocialPostVariant: Instagram (phase sau)
        +--> SocialPostVariant: TikTok (phase sau)
                         |
                         v
                  SocialPublication
```

`SocialPost` là bundle cha; `SocialPostVariant` mới là đơn vị có cấu trúc nội
dung và media của từng nền tảng. Nhiều Page Facebook có thể dùng chung một
Facebook variant nhưng vẫn có target/publication status riêng.
Các status trên `SocialPost` là aggregate để list/scheduler; variant là nguồn
quyết định cho content readiness, review và publish.

### 3.1. Trạng thái Social Post

Không trộn trạng thái tạo nội dung, duyệt nội dung và publish vào một enum. Bản
ghi `SocialPost` giữ ba nhóm trạng thái:

```text
generation_status:
pending -> generating -> ready
                    \-> partial -> generating|failed

review_status:
draft -> pending_review -> approved
                    \-> revision_requested -> draft
                    \-> rejected

publication_status:
pending -> scheduled -> publishing -> published
                                  \-> failed
```

`approved` là điều kiện bắt buộc cho publish và mọi thay đổi content/asset sau
approve phải đưa variant về `draft`. `publication_status = published` chỉ được
dùng khi các target đang bật đã publish thành công; lỗi một target được giữ ở
target/publication detail và không làm mất kết quả của Page khác.

Variant Facebook có thêm media contract:

```text
content_status:
pending -> generating -> ready | partial | failed
image_slots:
3..4 -> pending -> generating -> ready | failed | retryable
```

V1 yêu cầu tối thiểu 3 ảnh ready và tối đa 4 ảnh ready để submit review. Không có
video field hoặc video media mode trong Facebook v1; khi mở TikTok sẽ thiết kế
video contract riêng cho nền tảng đó.

### 3.2. Trạng thái target/publication

Mỗi target có trạng thái riêng:

```text
pending -> queued -> publishing -> published
                               \-> failed -> retryable
```

Một Page lỗi không làm mất kết quả của Page khác. `idempotency_key` phải duy
nhất theo `social_post_id + social_account_id`.

## 4. Dữ liệu và contract dự kiến

### 4.1. Bảng chính

- `products`: tên, mô tả ngắn, CTA mặc định, từ ngữ cấm và trạng thái.
- `product_facts`: fact có `key`, `value`, `source`, `verified`, actor/thời điểm
  xác minh và version; AI chỉ được tham chiếu fact đã được phép dùng.
- `campaigns`: tên, mục tiêu, thông điệp, thời gian, tần suất và product liên quan.
- `campaign_products`: pivot campaign/product.
- `social_accounts`: provider/platform, external Page ID, tên hiển thị, token
  mã hóa, expiry, scopes, status và metadata bounded. V1 chỉ bật Facebook Page;
  OAuth/publisher được triển khai ở MP-06 nhưng schema tạo từ MP-01.
- `social_posts`: identity chung theo campaign/product, aggregate
  `generation_status`, `review_status`, `publication_status`, created/updated
  actor, review metadata và `scheduled_at`.
- `social_post_variants`: Social Post, `platform`, `format`, structured
  `content_json`, content/generation/review status, prompt/profile/provider
  snapshot và version hiện hành. Facebook là variant đầu tiên.
- `social_post_targets`: variant, platform, social account, enabled flag và
  target status. Không tạo field scalar `platform_caption`; content nằm trong
  variant contract.
- `social_publications`: target, idempotency key, provider job/request ID,
  external post ID/URL, attempts, response metadata an toàn, lỗi và timestamps.
- `social_post_versions`: snapshot variant content, asset IDs, campaign/product
  context, prompt/profile/provider metadata, actor, reason và version hash.
- `daily_content_plans`: ngày, campaign, slot, số lượng dự kiến, run status và
  unique key để chống scheduler tạo trùng.

Media dùng lại `MediaAsset` và `media_asset_usages`. MP-01 rebaseline thêm một
variant owner cho usage và các field:

- `social_post_variant.facebook.images`: image, multiple, `sort_order` bắt buộc,
  tối đa 4 asset.

Không tạo bảng `social_post_assets` trùng với usage table. Migration mới phải
được timestamp, không sửa migration đã chạy; usage cũ `social_post.image` phải
có migration chuyển đổi hoặc deprecate rõ ràng.

### 4.2. AI Prompt Template

`AiWritingProfile` chỉ lưu văn phong. Prompt template cho Social Post là domain
riêng, có thể bắt đầu từ config rồi chuyển sang CRUD database:

```text
ai_prompt_templates
- name
- type: text | image
- target: social_post
- purpose: facebook_content | facebook_image
- template
- variables_json
- output_schema
- version
- status
- is_enabled
- created_by
```

Trong đợt Facebook-first bật `text/facebook_content` và
`image/facebook_image`. Video prompt/provider/storyboard/FFmpeg không thuộc
phase này; sẽ được thiết kế khi bắt đầu TikTok.

Ai Content phải hiển thị và bật tài nguyên `Social` trong danh sách resource có
thể tạo thủ công. Resource hiện đã có catalog entry nhưng cần nâng cấp từ prompt
caption MVP sang structured Facebook run. Resource này tạo `social_post` run với operation
`facebook_content` hoặc `facebook_image`, dùng cùng context builder, schema và
snapshot như scheduler; không tạo một prompt riêng không có version.

Prompt run phải snapshot `prompt_key`, `prompt_version`, `writing_profile` và
context facts vào `social_post_versions` hoặc metadata của AI run.

### 4.4. Kiến trúc field theo tài nguyên Ai Content

Form `Ai Content` chỉ giữ shell dùng chung cho nguồn, provider/model, ngôn ngữ,
queue và trạng thái. Field nghiệp vụ phải nằm trong definition riêng của từng
resource, có cùng một nguồn chuẩn cho backend schema và frontend form:

- `Post`: title/content/SEO/taxonomy và adapter Post hiện có.
- `Social`: Facebook content blocks, Product/Campaign context, Writing Profile,
  reference assets, image prompt và gallery image options.
- Resource mới: thêm definition/adapter/schema/form riêng, không thêm nhiều
  `v-if` đặc thù vào `AiContentSourceForm.vue`.

Mỗi definition mới bắt buộc đăng ký target, permission resource, input/output
fields, prompt/schema, adapter, component frontend, test và tài liệu API. Generic
Ai Content chỉ đọc definition để render; server vẫn là nơi validate allowlist và
permission.

### 4.3. Output AI

Facebook content không trả SEO/taxonomy của Blog. Output tối thiểu:

Facebook content task (`social_post.facebook_content`):

```json
{
  "title": "...",
  "body": "...",
  "bullet_points": [],
  "cta": "...",
  "contact": {
    "address": "...",
    "phone": "...",
    "website": null
  },
  "hashtags": [],
  "link_url": null,
  "used_product_fact_ids": []
}
```

Facebook image task (`social_post.facebook_image`):

```json
{
  "image_prompt": "...",
  "negative_prompt": "...",
  "aspect_ratio": "4:5",
  "slot_count": 3,
  "used_product_fact_ids": []
}
```

Mỗi slot ảnh nhận cùng product/context snapshot nhưng có `slot_no` và prompt
seed riêng để tạo 3--4 ảnh có cùng nhận diện mà không bị trùng hoàn toàn.

Image prompt chỉ mô tả bối cảnh, ánh sáng, góc máy, chất liệu và bố cục. Tên
sản phẩm, logo, ngày tháng và chữ in chính xác phải lấy từ product facts hoặc
reference asset; không coi text do image model vẽ là dữ liệu chính xác.

## 5. Navigation và frontend

Thêm nhóm `Marketing` trong cả hai layout:

```text
Marketing
└── Social Post
```

File cần cập nhật:

- `resources/js/navigation/vertical/apps-and-pages.js`
- `resources/js/navigation/horizontal/apps.js`

Route dự kiến: `/marketing/social-posts`, route name
`marketing-social-post-list`. Frontend dùng Composition API và `<script setup>`.

Màn hình Social Post cần có:

- danh sách và filter theo status/campaign/platform/target;
- Facebook feed preview: Page header + content blocks + 3--4 ảnh gallery;
- tạo thủ công hoặc tạo từ daily plan;
- chỉnh từng content block và chọn/reorder ảnh;
- regenerate content, từng ảnh hoặc cả gallery;
- version history;
- chọn nhiều Page đích;
- submit review/approve/reject/request revision;
- publication status theo từng target.

AI Task Queue Popup hiện có tiếp tục hiển thị tiến trình AI; Social Post không
tạo một queue UI riêng.

## 6. Backend/API dự kiến

### Social Post

- `GET /api/admin/social-posts`
- `POST /api/admin/social-posts`
- `GET /api/admin/social-posts/{socialPost}`
- `PUT /api/admin/social-posts/{socialPost}`
- `DELETE /api/admin/social-posts/{socialPost}`
- `POST /api/admin/social-posts/{socialPost}/generate`
- `POST /api/admin/social-posts/{socialPost}/regenerate-content`
- `POST /api/admin/social-posts/{socialPost}/regenerate-images`
- `POST /api/admin/social-posts/{socialPost}/submit-review`
- `POST /api/admin/social-posts/{socialPost}/approve`
- `POST /api/admin/social-posts/{socialPost}/reject`
- `POST /api/admin/social-posts/{socialPost}/request-revision`
- `GET /api/admin/social-posts/{socialPost}/versions`
- `GET /api/admin/social-posts/{socialPost}/publications`

### Facebook variant/media

- `GET /api/admin/social-posts/{socialPost}/variants`
- `GET /api/admin/social-posts/{socialPost}/variants/facebook`
- `PUT /api/admin/social-posts/{socialPost}/variants/facebook/content`
- `POST /api/admin/social-posts/{socialPost}/variants/facebook/generate-content`
- `POST /api/admin/social-posts/{socialPost}/variants/facebook/generate-images`
- `POST /api/admin/social-posts/{socialPost}/variants/facebook/images/replace`
- `POST /api/admin/social-posts/{socialPost}/variants/facebook/images/reorder`

Các endpoint variant phải trả content blocks, image slots theo `sort_order` và
trạng thái từng slot. Endpoint publish nhận variant với gallery images; không
tiếp tục coi một `caption` và một `image` là đủ contract Facebook.

### Accounts và publisher

- `GET /api/admin/social-accounts`
- `POST /api/admin/social-accounts/facebook/connect`
- `GET /api/admin/social-accounts/facebook/callback`
- `POST /api/admin/social-accounts/{socialAccount}/reconnect`
- `POST /api/admin/social-accounts/{socialAccount}/disconnect`
- `POST /api/admin/social-posts/{socialPost}/publish`
- `POST /api/admin/social-publications/{publication}/retry`

OAuth callback phải tách khỏi Facebook customer login. Token chỉ lưu server-side,
mã hóa và không trả về frontend.

## 7. Các phase triển khai

Ước lượng là ngày công tương đối sau khi giữ nguyên foundation hiện tại.

### MP-00 — Chốt contract và spike — IN PROGRESS — 1--2 ngày

Đối chiếu hiện trạng cho thấy project đang dùng permission không có teams
(`config/permission.php:138`) và chưa có workspace/tenant model. Vì vậy đề xuất
chốt v1 là **single workspace**; không thêm `workspace_id`/`tenant_id` vào các
bảng đầu tiên. Khi cần multi-tenant sẽ có migration và policy riêng.

Product facts tối thiểu trước khi gọi AI:

- tên sản phẩm và mô tả ngắn;
- ít nhất một ảnh/reference asset của sản phẩm;
- chất liệu, phong cách, USP hoặc thông tin xác thực liên quan;
- CTA và mọi chữ/ngày tháng phải giữ chính xác;
- giá, ưu đãi, đối tượng và claim chỉ đưa vào context khi có dữ liệu nguồn.

AI không được tự điền fact còn thiếu. Fact nên có `source`, `verified` và phiên
bản để truy ra lý do caption/ảnh đã dùng dữ liệu nào.

MP-00 cần chốt ba nhóm trạng thái riêng trong contract:

- generation: `pending`, `generating`, `ready`, `partial`, `failed`;
- review/content: `draft`, `pending_review`, `revision_requested`, `approved`, `rejected`;
- publication aggregate: `scheduled`, `publishing`, `published`, `failed`, trong đó
  chi tiết từng Page vẫn nằm ở `SocialPostTarget`/`SocialPublication`.

Permission v1 gồm quyền cấp module `marketing.view/manage`, resource
`social_posts.view/create/update/delete/review/publish`,
`social_accounts.view/manage` và quyền generate AI riêng hoặc gắn với
`social_posts.create/update`. Editor tạo/sửa/regenerate; reviewer approve/reject;
publisher publish; admin quản lý Page token. Các quyền này phải được thêm vào
catalog và role seed trước khi mở route.

Quy ước bắt buộc: mỗi resource Marketing mới phải có nhóm permission riêng trong
`config/permissions.php`, được gắn vào route middleware, role seed và tài liệu API.
`marketing.view/manage` chỉ là quyền phạm vi module; không dùng nó để bỏ qua
permission lifecycle chi tiết của resource nếu resource cần phân quyền độc lập.

Facebook spike phải chứng minh được: OAuth Page tách khỏi customer login hiện có,
kết nối một Page test, lấy/đổi token server-side, publish structured content cùng
3--4 ảnh, lưu external ID, xử lý token hết hạn/reconnect và không ghi token vào
log/response. Video không nằm trong spike Facebook v1; sẽ được chốt cùng TikTok.

Facebook v1 chốt content blocks, 3--4 ảnh ordered, format/kích thước/giới hạn
file, provider ảnh và quota. Text/logo/ngày tháng chính xác phải
lấy từ fact hoặc reference asset, không phụ thuộc chữ do image model vẽ.

- [x] Chốt single workspace v1 và ghi rõ điểm mở rộng multi-tenant.
- [x] Chốt product facts bắt buộc, nguồn fact và quy tắc không được bịa.
- [x] Chốt state machine generation/review/publication và transition guards.
- [x] Chốt permission matrix theo role, gồm `marketing.view/manage` và quyền resource.
- [ ] Chốt một Facebook Page test, Meta app/redirect URI, token lifecycle và smoke test. **BLOCKED:** chờ credentials môi trường Facebook.
- [x] Chốt Facebook content schema và preview contract.
- [x] Chốt 3--4 image slots, ordering, retry và asset role.
- [ ] Chốt Facebook gallery publish contract bằng Page test.

**Kết quả bắt buộc:** contract/API schema, state transition table, permission
matrix, `.env.example` keys cho Facebook/provider, một product fixture và biên
bản smoke test Page test được ghi trước khi tạo migration. Contract/API,
fixture và runbook QA đã có trong [Marketing Social Post API](../api/MARKETING_SOCIAL_POST_API.md)
và [QA MP-00](../qa/MARKETING_SOCIAL_POST_MP00.md); smoke test thật còn chờ
Meta App/Page token.

### MP-01 — Domain và database rebaseline — IN PROGRESS — 2--3 ngày

Rà soát schema trước khi viết migration:

- V1 không có `workspace_id`/`tenant_id`; toàn bộ dữ liệu thuộc single workspace.
- `product_facts` là bảng riêng để lưu source/verified/version, tránh nhét facts
  vào JSON không thể truy vết.
- `social_accounts` tạo cùng phase này để `social_post_targets` có foreign key;
  MP-06 chỉ bổ sung OAuth, encryption rotation và publisher.
- Dùng `media_asset_usages` cho variant; Facebook images là multiple ordered,
  không tạo usage table trùng lặp.
- `social_posts` dùng bigint auto-increment giống Blog `Post` và tương thích
  `media_asset_usages.linkable_id`; UUID chỉ dùng cho AI task/request nếu cần.
  `version_no` duy nhất theo variant; target/account không được trùng trong cùng
  một variant; `daily_content_plans` unique theo `planned_date + slot`.
- Nếu đổi Social Post sang UUID sau này phải thay đổi `linkable_id`, request và
  resolver MediaAsset toàn hệ thống; việc đó nằm ngoài MP-01.
- Product/campaign/post có soft delete; version/publication là immutable; xóa
  product/campaign không làm mất snapshot Social Post đã tạo.

- [x] Tạo migrations/models cho product, product_facts, campaign, social_accounts
  và parent Social Post.
- [x] Tạo `SocialPostVariant` và Facebook content/media contract với foreign key,
  unique/index và snapshot fields; tuyệt đối không dùng scalar `platform_caption`.
- [x] Tạo daily content plan với timezone, slot và unique constraint.
- [x] Thêm enum/validation contract cho generation, review, publication, target,
  platform và asset field; request validation sẽ nằm ở API phase.
- [x] Mở rộng `MediaAssetField`/morph map cho variant images multiple ordered;
  có migration chuyển usage cũ `social_post.image` vào slot đầu tiên.
- [x] Gắn `Product` với gallery reference images qua `product.reference_images`,
  trả IDs theo thứ tự và đưa allowlist vào Social AI context.
- [x] Thêm activity log cho domain mutation và không ghi token, prompt secret
  hoặc raw provider response.

**Kết quả baseline:** dữ liệu hiện tại không phụ thuộc Blog `Post`; schema cũ đã
được kiểm bằng `MarketingSocialPostSchemaTest`, `InitialSchemaTest` và
`MediaAssetContractTest`. Variant/media rebaseline phải có migration và test mới.

### MP-02 — AI Prompt Template theo platform — IN PROGRESS — 2--3 ngày

Rà soát trước triển khai:

- `PromptRegistry` hiện là config-backed, đã có allowlist target/operation/version;
  nên tái sử dụng cơ chế này và bổ sung metadata `type`, `purpose`, `variables`,
  `output_schema`, không biến `AiWritingProfile` thành prompt ảnh.
- `TargetRegistry` hiện được `AiImportController` dùng cho pipeline Blog/Resource
  và yêu cầu adapter hợp lệ. MP-02 chốt prompt/schema và thêm mục resource
  `Social` vào Ai Content; MP-03 đăng ký adapter/queue để resource này chạy được
  thủ công cùng contract với scheduler.
- `text/facebook_content` trả structured JSON gồm title/body/bullets/CTA/contact/
  hashtags/fact IDs. `image/facebook_image` trả structured JSON gồm prompt/
  negative prompt/slot count/fact IDs; provider ảnh tạo binary assets ở MP-03.
- V1 dùng prompt key `social_post.facebook_content` và
  `social_post.facebook_image`; mỗi key có schema/version riêng. Không đăng ký
  video prompt/provider trong Facebook phase.
- Context template chỉ nhận product facts đã verified/allowed, campaign context,
  writing profile snapshot, language/CTA và visual constraints. Fact/raw text được
  bọc như dữ liệu, không được coi là instruction.
- Dùng `AiTaskSchemaValidator` cho schema object đầy đủ; `AiOutputValidator` vẫn
  chỉ dành cho field groups của Blog Post.
- Full bounded snapshot của prompt/profile/facts/asset IDs nằm ở
  `social_post_versions`; `AiTaskRun.metadata_json` chỉ giữ key/version/hash,
  provider/model và IDs, không lưu token hoặc raw response.

- [x] Tách writing profile khỏi prompt template và xác định field dùng cho caption/ảnh.
- [x] Thêm prompt metadata `type=text|image`, `purpose=facebook_content|facebook_image`,
  version, allowlist target/operation, variables và template server-side.
- [x] Đăng ký prompt/schema Social Post qua `PromptRegistry`.
- [x] Chốt quy ước mỗi Ai Content resource có definition/adapter/schema/form và
  permission riêng; generic form chỉ làm shell chung.
- [x] Bật tài nguyên `Social` trong Ai Content cho manual `social_post` content
  candidate; chỉ cho phép operation đã allowlist. Việc nối candidate này vào
  structured `facebook_content`/`facebook_image` run và Facebook variant còn ở
  MP-03.
- [x] Thêm schema Facebook content và image gallery prompt đầy đủ
  `type/properties/required` cùng validator.
- [x] Chốt context allowlist, fact IDs và giới hạn độ dài/hashtag/negative prompt.
- [x] Snapshot prompt/profile/context vào SocialPostVersion và metadata AI bounded.
- [x] Thêm unit tests cho registry selection, type/purpose allowlist, schema valid/invalid
  và snapshot không chứa secret/platform_caption.

**Kết quả cần đạt:** Facebook content prompt và image gallery prompt có version,
allowlist và audit độc lập; tài nguyên `Social` xuất hiện trong Ai Content cho
manual run với cùng contract như scheduler.

### MP-03 — AI tạo Facebook content và media bundle — DONE LOCAL — 20/10/2026

Phạm vi hiện tại thay thế mô tả Product/3–4 ảnh cũ: Social độc lập Product từ
18/10; gallery dùng image_count 1–10. Nguồn là text/campaign, writing profile,
brief, ngôn ngữ, độ dài và image profile tùy chọn.

- [x] Context builder có allowlist, giới hạn độ dài và context hash. Không đọc
  Product/ProductFact; schema không chấp nhận fact_ids/platform_caption ngoài contract.
- [x] SocialPostAiAdapter được đăng ký bằng session_adapter; POST AI Content
  tạo Social draft/variant và gọi SocialPostGenerationService trong cùng transaction.
  Không gọi provider trong HTTP; lỗi model rollback cả draft và jobs.
- [x] Giữ quyền social_posts.create, quota theo actor và khóa chống gửi trùng;
  trả job IDs, tracker IDs và social_post_id để mở bài Social.
- [x] Nội dung và image prompt dùng cùng prompt/schema/queue với Social tạo trực
  tiếp và scheduler. Chỉ xếp những output được chọn, không tạo Blog Post/candidate giả.
- [x] Fan-out đúng image_count, attach MediaAsset theo slot; ảnh lỗi giữ partial
  và không làm mất nội dung/ảnh đã hoàn thành. Điều kiện duyệt dùng số ảnh đã yêu cầu.
- [x] Snapshot nguồn/brief/độ dài/profile/model/prompt/schema/context và phiên bản;
  metadata tracker chỉ giữ ID/hash bounded. Regenerate giữ nguồn và model đã chọn.
- [x] Tạo lại riêng content, gallery hoặc từng image slot; slot retry tăng attempt,
  giữ gallery cũ đến khi ảnh mới attach, không gọi lại các slot đã hoàn tất.
- [x] Bỏ qua terminal/duplicate jobs; kiểm lại generation và trạng thái sau provider.
  Kết quả đến sau cancel/stale không ghi đè; ảnh mới chưa attach được dọn,
  ảnh cũ còn trong version history được giữ.
- [x] UI chọn model ảnh, điều hướng về Social draft, polling đến terminal,
  retry từng ảnh và mở đúng Social Post từ popup queue.
- [x] Kiểm thử bằng provider/queue fake: API permission/rollback/dedupe,
  selected outputs, schema, fan-out, partial failure, retry/ordering,
  snapshot, cancel/stale và orphan cleanup. [QA MP-03](../qa/MARKETING_SOCIAL_POST_MP03.md).

**Giới hạn nghiệm thu:** DONE LOCAL không thay cho chạy worker đồng thời trên
host, provider ảnh thật hay publish Page thật; các bước đó giữ tại MP-00/MP-08/H-01.

### MP-04 — Facebook feed preview và editor — IN PROGRESS — 4--5 ngày

- [x] Thêm nhóm Marketing ở vertical/horizontal navigation.
- [x] Tạo route/page/list/detail cho Social Post.
- [x] Hiển thị đúng Facebook feed preview: Page header, content blocks và 3--4 ảnh
  ordered.
- [x] Thêm editor cho từng content block và reorder/replace image slots.
- [x] Hiển thị variant/platform status, asset slot status và version history.
- [x] Nối task queue popup hiện có.

**Kết quả cần đạt:** người dùng xem/chỉnh được Facebook Post giống layout feed
thực tế, không chỉ xem một caption và một ảnh.

### MP-05 — Review và approval theo variant — IN PROGRESS — 2--3 ngày

- [x] Thêm `social_posts.view/create/update/delete/review/publish`.
- [x] Implement submit, approve, reject, request revision.
- [x] Khóa submit/approve nếu content chưa ready hoặc chưa đủ 3 ảnh ready.
- [ ] Lưu actor/time/reason/version cho variant và media snapshot.
- [ ] Thêm feature tests cho 3--4 image slots, replace/reorder và draft gate.

**Kết quả cần đạt:** chỉ Facebook variant đã duyệt mới đủ điều kiện publish.

### MP-06 — Facebook account và publisher — IN PROGRESS — 3--5 ngày

- [x] Tạo Facebook Page account flow riêng với customer OAuth.
- [x] Mã hóa token, expiry, reconnect và health check.
- [x] Định nghĩa `SocialPublisherContract`.
- [x] Implement `FacebookPublisher` cho structured content + 3--4 ảnh gallery
  qua Graph API, không thêm `facebook/php-business-sdk`.
- [x] Lưu publication per target, external ID/URL và lỗi bounded.
- [x] Retry có idempotency, dispatch sau commit và không tạo bài trùng.

**Kết quả cần đạt:** một Facebook variant approved đăng được đúng content và
gallery 3--4 ảnh lên Page thật.

### MP-07 — Daily planner và vận hành bundle — IN PROGRESS — 2--3 ngày

- [x] Tạo command/schedule tạo 2--3 slot mỗi ngày.
- [x] Chọn campaign đang active và chống lặp slot bằng unique planned date/slot.
- [x] Dispatch Facebook content và image-slot jobs theo queue; tạo đủ 3--4 ảnh
  theo từng slot và không tự publish.
- [x] Cho phép tạo cùng contract qua tài nguyên `Social` trong Ai Content để
  editor tạo thủ công ngoài daily plan.
- [x] Giữ quota AI hiện có và tách image job retry/backoff.
- [x] Ghi failed job/partial generation để draft không tự publish.

**Kết quả cần đạt:** hệ thống tự tạo 2--3 Facebook bundle draft/ngày, đủ content
và 3--4 image slots, không tự publish.

### MP-08 — Hardening và nghiệm thu — IN PROGRESS — 2--3 ngày

- [x] Backend tests cho schema, permission, lifecycle, queue và idempotency.
- [x] Frontend tests cho API client/list contract; build production pass.
- [x] Test deterministic text, image failure/partial và queue workflow bằng provider fake.
- [ ] Test content contract Facebook, 3--4 image fan-out, reorder và gallery
  publish.
- [x] Test Ai Content resource `Social` tạo được content/image run thủ công và
  dùng cùng schema, context snapshot, permission với scheduler.
- [x] Redact token/provider secret khỏi log và response.
- [ ] Chạy smoke test trên Page thật và provider ảnh thật. **BLOCKED:** chờ Meta App/Page token và image provider credentials.
- [x] Cập nhật API/ENV/runbook và QA report.

Ghi chú lịch sử kiểm thử ngày 16/10 (không phải kết quả chạy lại ngày 20/10): Social Post/AI Content tests riêng đạt; lần
chạy backend đầy đủ gần nhất có **619 passed, 2 lỗi**: lỗi parser provider legacy
ngoài phạm vi Social Post và một lỗi `MediaTask8IntegrationTest` chỉ xuất hiện
trong full-suite order (chạy riêng test này đạt). Test reference asset bổ sung
sau lần chạy đó cũng đã đạt.
`AiProviderSettingsApiTest::connection failure waits for manual retry and uses
latest timeout` dùng fixture provider thiếu `finish_reason: stop`, nên strict
response parser trả `failed` thay vì `ready`. Frontend toàn bộ đạt **403 tests**;
build production đã chạy đạt. Smoke test Page thật vẫn **BLOCKED** vì thiếu
Meta App/Page token và image provider credentials.

## 8. Acceptance checklist MVP

- [x] Có menu `Marketing > Social Post` ở cả hai layout.
- [x] Tạo được product và campaign làm AI context.
- [x] Tạo được Facebook variant có content blocks và 3--4 ảnh ordered.
- [x] Ai Content có resource `Social` để tạo thủ công content/image run theo
  cùng Facebook schema và context với daily planner.
- [x] Một Social Post chọn được nhiều target/account.
- [x] Không có scalar `platform_caption`; content nằm trong variant contract.
- [x] Có version history cho content blocks, từng ảnh và prompt snapshot.
- [x] Có thể regenerate content, từng ảnh hoặc cả gallery độc lập.
- [x] Draft chưa duyệt không thể publish.
- [x] Publish status tách theo từng target.
- [x] Retry không tạo bài trùng.
- [x] Scheduler tạo tối đa 2--3 draft mỗi ngày theo unique slot.
- [x] Facebook preview hiển thị đúng header/content/gallery.

## 9. Việc hoãn sau phase này

- Video provider, video prompt, storyboard và FFmpeg; video sẽ được thiết kế khi
  triển khai TikTok.
- Instagram/TikTok publisher thật và các content/media contract riêng của chúng.
- Các content contract khác ngoài Facebook.
- Analytics sâu theo platform.
- AI cost dashboard hoàn chỉnh.
- Multi-tenant workspace nếu chưa chốt ở MP-00.

## 10. Quy ước cập nhật

- Dùng `TODO`, `IN PROGRESS`, `BLOCKED`, `DONE`.
- Mỗi task chỉ có một checklist tại file này.
- Khi task hoàn tất, đánh dấu checklist và ghi link QA/API nếu có.
- Không đánh dấu phase `DONE` khi còn acceptance item chưa đạt.
- Migration mới phải là timestamped migration; không sửa migration đã chạy.
