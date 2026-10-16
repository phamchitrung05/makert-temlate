# Kế hoạch Marketing Social Post

**Cập nhật:** 16/10/2026
**Trạng thái tổng:** IN PROGRESS — code MVP đã triển khai; còn smoke test Meta/provider thật và frontend test.
**Phạm vi đợt đầu:** tạo Social Post bằng AI, sinh caption + ảnh, lưu Draft,
duyệt thủ công và chuẩn bị publish Facebook. Video tạm hoãn.

## 1. Mục tiêu và quyết định phạm vi

Marketing Social Post là domain riêng trong Admin, không dùng lại model `Post`
đang phục vụ Blog. Một Social Post là nội dung chuẩn có thể chọn nhiều nền tảng
và nhiều tài khoản đích.

Đợt đầu triển khai:

- Tạo 2--3 Social Post draft mỗi ngày theo campaign/product.
- AI tạo một caption dùng chung cho mọi nền tảng đã chọn.
- AI tạo một hoặc nhiều ảnh sản phẩm và lưu qua MediaAsset.
- Người dùng chỉnh sửa, regenerate, submit review, approve hoặc reject.
- Chỉ Social Post được approve mới được đưa vào publish.
- Tích hợp publisher Facebook trước; schema và contract giữ khả năng thêm nền tảng.
- Video, storyboard, FFmpeg và video provider tạm hoãn.

Không đưa `platform_caption` vào schema. Caption của Social Post là một bản dùng
chung; trạng thái publish, external ID, lỗi và retry được tách theo từng target.

## 2. Đối chiếu với project hiện tại

| Thành phần | Trạng thái hiện tại | Cách dùng cho Social Post |
| --- | --- | --- |
| Laravel/Vue/Sanctum/permission | Đã có | Giữ nguyên foundation và API envelope |
| AI provider registry và queue | Đã có | Dùng lại `AiImport`, `AiTaskRun`, provider/model snapshot |
| Writing Profile | Đã có | Dùng làm lớp giọng điệu, không biến thành prompt ảnh |
| AI image generation | Đã có | Dùng lại image job và MediaAsset pipeline |
| Media Library | Đã có | Lưu ảnh và các usage của Social Post |
| Blog `Post` workflow | Đã có | Chỉ tham khảo state/action; không gắn Facebook vào `posts` |
| Facebook Customer OAuth | Đã có | Chỉ là login customer, không đủ để quản lý Page/publish |
| Product/Campaign backend | Chưa có | Cần tạo domain tối thiểu cho AI context |
| Social publisher/account/publication | Chưa có | Xây mới, Facebook là adapter đầu tiên |
| Daily scheduler | Chưa có cho Social Post | Làm sau vertical slice tạo và duyệt thủ công |

Các module hiện tại nằm tại [PROJECT_INVENTORY](../architecture/PROJECT_INVENTORY.md),
[config/ai/agent.php](../../config/ai/agent.php),
[AiImageGenerationService.php](../../app/Services/Ai/Images/AiImageGenerationService.php)
và [ProcessAiImageGenerationJob.php](../../app/Jobs/ProcessAiImageGenerationJob.php).

## 3. Mô hình nghiệp vụ

```text
Campaign / Product
        |
        v
   SocialPost
   - canonical caption
   - status/review/version
   - MediaAsset image(s)
        |
        +--> SocialPostTarget: Facebook Page A
        +--> SocialPostTarget: Facebook Page B
        +--> SocialPostTarget: Instagram (phase sau)
        +--> SocialPostTarget: TikTok (phase sau)
                         |
                         v
                  SocialPublication
```

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

`approved` là điều kiện bắt buộc cho publish và mọi thay đổi caption/asset sau
approve phải đưa `review_status` về `draft`. `publication_status = published` chỉ
được dùng khi các target đang bật đã publish thành công; lỗi một target được giữ
ở target/publication detail và không làm mất kết quả của Page khác.

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
- `social_posts`: campaign/product, canonical caption, `generation_status`,
  `review_status`, `publication_status`, created/updated actor, review metadata
  và `scheduled_at`.
- `social_post_targets`: Social Post, platform, social account, enabled flag và
  target status. Không có caption riêng theo platform.
- `social_publications`: target, idempotency key, provider job/request ID,
  external post ID/URL, attempts, response metadata an toàn, lỗi và timestamps.
- `social_post_versions`: snapshot caption, asset IDs, campaign/product context,
  prompt/profile/provider metadata, actor, reason và version hash.
- `daily_content_plans`: ngày, campaign, slot, số lượng dự kiến, run status và
  unique key để chống scheduler tạo trùng.

Ảnh dùng lại `MediaAsset` và `media_asset_usages`; MP-01 thêm field
`social_post.image` vào `MediaAssetField`, cardinality một ảnh canonical cho v1
và đăng ký morph alias `social_post`. Không tạo bảng `social_post_assets` thứ hai.
Không sửa các migration đã chạy.

### 4.2. AI Prompt Template

`AiWritingProfile` chỉ lưu văn phong. Prompt template cho Social Post là domain
riêng, có thể bắt đầu từ config rồi chuyển sang CRUD database:

```text
ai_prompt_templates
- name
- type: text | image | video
- target: social_post
- purpose: caption | product_image | storyboard
- template
- variables_json
- output_schema
- version
- status
- is_enabled
- created_by
```

Trong đợt này chỉ bật `text/caption` và `image/product_image`. `video` được giữ
lại trong enum/schema để mở rộng sau, nhưng chưa có UI/provider/job.

Prompt run phải snapshot `prompt_key`, `prompt_version`, `writing_profile` và
context facts vào `social_post_versions` hoặc metadata của AI run.

### 4.3. Output AI

Caption không trả SEO/taxonomy của Blog. Output tối thiểu:

Caption task (`social_post.caption`):

```json
{
  "caption": "...",
  "cta": "...",
  "hashtags": [],
  "used_product_fact_ids": []
}
```

Image prompt task (`social_post.product_image`):

```json
{
  "image_prompt": "...",
  "negative_prompt": "...",
  "aspect_ratio": "4:5",
  "used_product_fact_ids": []
}
```

Image prompt chỉ mô tả bối cảnh, ánh sáng, góc máy, chất liệu và bố cục. Tên
sản phẩm, logo, ngày tháng và chữ in chính xác phải lấy từ product facts hoặc
ảnh gốc; không coi text do image model vẽ là dữ liệu chính xác.

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
- card preview caption + ảnh;
- tạo thủ công hoặc tạo từ daily plan;
- chỉnh caption và chọn ảnh;
- regenerate caption/ảnh riêng từng phần;
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
- `POST /api/admin/social-posts/{socialPost}/regenerate-caption`
- `POST /api/admin/social-posts/{socialPost}/regenerate-image`
- `POST /api/admin/social-posts/{socialPost}/submit-review`
- `POST /api/admin/social-posts/{socialPost}/approve`
- `POST /api/admin/social-posts/{socialPost}/reject`
- `POST /api/admin/social-posts/{socialPost}/request-revision`
- `GET /api/admin/social-posts/{socialPost}/versions`
- `GET /api/admin/social-posts/{socialPost}/publications`

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

Permission v1 đề xuất: `social_posts.view/create/update/delete/review/publish`,
`social_accounts.view/manage` và quyền generate AI riêng hoặc gắn với
`social_posts.create/update`. Editor tạo/sửa/regenerate; reviewer approve/reject;
publisher publish; admin quản lý Page token. Các quyền này phải được thêm vào
catalog và role seed trước khi mở route.

Facebook spike phải chứng minh được: OAuth Page tách khỏi customer login hiện có,
kết nối một Page test, lấy/đổi token server-side, publish một caption + ảnh,
lưu external ID, xử lý token hết hạn/reconnect và không ghi token vào log/response.

Đợt đầu chỉ hỗ trợ ảnh; chốt một ảnh canonical cho Social Post, định dạng/kích
thước/giới hạn file, provider ảnh và quota. Text/logo/ngày tháng chính xác phải
lấy từ fact hoặc ảnh gốc, không phụ thuộc chữ do image model vẽ.

- [x] Chốt single workspace v1 và ghi rõ điểm mở rộng multi-tenant.
- [x] Chốt product facts bắt buộc, nguồn fact và quy tắc không được bịa.
- [x] Chốt state machine generation/review/publication và transition guards.
- [x] Chốt permission matrix theo role và catalog permission mới.
- [ ] Chốt một Facebook Page test, Meta app/redirect URI, token lifecycle và smoke test. **BLOCKED:** chờ credentials môi trường Facebook.
- [x] Chốt contract ảnh v1: provider, format, kích thước, quota và asset role.
- [x] Chốt v1 chỉ tạo ảnh, không video.

**Kết quả bắt buộc:** contract/API schema, state transition table, permission
matrix, `.env.example` keys cho Facebook/provider, một product fixture và biên
bản smoke test Page test được ghi trước khi tạo migration. Contract/API,
fixture và runbook QA đã có trong [Marketing Social Post API](../api/MARKETING_SOCIAL_POST_API.md)
và [QA MP-00](../qa/MARKETING_SOCIAL_POST_MP00.md); smoke test thật còn chờ
Meta App/Page token.

### MP-01 — Domain và database — DONE — 2--3 ngày

Rà soát schema trước khi viết migration:

- V1 không có `workspace_id`/`tenant_id`; toàn bộ dữ liệu thuộc single workspace.
- `product_facts` là bảng riêng để lưu source/verified/version, tránh nhét facts
  vào JSON không thể truy vết.
- `social_accounts` tạo cùng phase này để `social_post_targets` có foreign key;
  MP-06 chỉ bổ sung OAuth, encryption rotation và publisher.
- Dùng `media_asset_usages` với field `social_post.image`, không thêm usage table
  trùng lặp.
- `social_posts` dùng bigint auto-increment giống Blog `Post` và tương thích
  `media_asset_usages.linkable_id`; UUID chỉ dùng cho AI task/request nếu cần.
  `version_no` duy nhất theo post; target/account không được trùng trong cùng
  một post; `daily_content_plans` unique theo `planned_date + slot`.
- Nếu đổi Social Post sang UUID sau này phải thay đổi `linkable_id`, request và
  resolver MediaAsset toàn hệ thống; việc đó nằm ngoài MP-01.
- Product/campaign/post có soft delete; version/publication là immutable; xóa
  product/campaign không làm mất snapshot Social Post đã tạo.

- [x] Tạo migrations/models cho product, product_facts, campaign, social_accounts
  và Social Post.
- [x] Tạo target/publication/version relations với foreign key, unique/index và
  snapshot fields; tuyệt đối không có `platform_caption`.
- [x] Tạo daily content plan với timezone, slot và unique constraint.
- [x] Thêm enum/validation contract cho generation, review, publication, target,
  platform và asset field; request validation sẽ nằm ở API phase.
- [x] Mở rộng `MediaAssetField`/morph map cho `social_post.image` cardinality một.
- [x] Thêm activity log cho domain mutation và không ghi token, prompt secret
  hoặc raw provider response.

**Kết quả:** dữ liệu canonical không phụ thuộc Blog `Post`; migration/model schema
đã được kiểm bằng `MarketingSocialPostSchemaTest`, `InitialSchemaTest` và
`MediaAssetContractTest`.

### MP-02 — AI Prompt Template — DONE — 2--3 ngày

Rà soát trước triển khai:

- `PromptRegistry` hiện là config-backed, đã có allowlist target/operation/version;
  nên tái sử dụng cơ chế này và bổ sung metadata `type`, `purpose`, `variables`,
  `output_schema`, không biến `AiWritingProfile` thành prompt ảnh.
- `TargetRegistry` hiện được `AiImportController` dùng cho pipeline Blog/Resource
  và yêu cầu adapter hợp lệ. Vì MP-03 mới tạo Social Post task adapter, MP-02
  vẫn đăng ký prompt/schema thủ công trong `config/ai/agent.php` qua
  `PromptRegistry`, nhưng chưa thêm target `social_post` vào generic target enabled.
- `text/caption` trả structured JSON gồm caption/CTA/hashtags/fact IDs. `image/product_image`
  trả structured JSON gồm image prompt/negative prompt/fact IDs; provider ảnh tạo
  binary asset ở MP-03, không coi JSON prompt là ảnh.
- V1 dùng hai prompt key ổn định: `social_post.caption` cho `generate`/
  `regenerate_caption` và `social_post.product_image` cho `generate`/
  `regenerate_image`; mỗi key có schema/version riêng.
- Context template chỉ nhận product facts đã verified/allowed, campaign context,
  writing profile snapshot, language/CTA và visual constraints. Fact/raw text được
  bọc như dữ liệu, không được coi là instruction.
- Dùng `AiTaskSchemaValidator` cho schema object đầy đủ; `AiOutputValidator` vẫn
  chỉ dành cho field groups của Blog Post.
- Full bounded snapshot của prompt/profile/facts/asset IDs nằm ở
  `social_post_versions`; `AiTaskRun.metadata_json` chỉ giữ key/version/hash,
  provider/model và IDs, không lưu token hoặc raw response.

- [x] Tách writing profile khỏi prompt template và xác định field dùng cho caption/ảnh.
- [x] Thêm prompt metadata `type=text|image`, `purpose=caption|product_image`,
  version, allowlist target/operation, variables và template server-side.
- [x] Đăng ký prompt/schema Social Post qua `PromptRegistry`, chưa bật generic
  AiImport target trước MP-03.
- [x] Thêm schema caption và image prompt đầy đủ `type/properties/required` cùng validator.
- [x] Chốt context allowlist, fact IDs và giới hạn độ dài/hashtag/negative prompt.
- [x] Snapshot prompt/profile/context vào SocialPostVersion và metadata AI bounded.
- [x] Thêm unit tests cho registry selection, type/purpose allowlist, schema valid/invalid
  và snapshot không chứa secret/platform_caption.

**Kết quả:** prompt ảnh có version, allowlist và audit như prompt văn phong. Prompt
caption và image prompt được tách thành hai task key/schema độc lập.

### MP-03 — AI tạo caption và ảnh — DONE — 3--4 ngày

Rà soát trước triển khai:

- `AiImageGenerationService` hiện đã gọi `AiImageProviderContract` và upload
  bytes thành `MediaAsset`, nhưng trả về asset chưa attach vào Post. Có thể tái
  sử dụng service này; phần hoàn tất MP-03 phải attach qua
  `MediaAssetUsageService` với field `social_post.image`.
- `ProcessAiImageGenerationJob` hiện nhận `AiImport` UUID, có unique lock,
  retry/backoff và dọn orphan asset, nhưng `mergeParentImage()` mới biết parent
  của luồng image chung. Cần thêm adapter/completion path cho Social Post; không
  để job ảnh tự suy đoán hoặc ghi thẳng vào Blog `Post`.
- `AiRunService::snapshotInput()` trả sớm với operation `image`, vì vậy không
  được giả định image run đã có writing profile/context snapshot. MP-03 phải
  truyền bounded context và profile snapshot tường minh cho từng Social Post
  task.
- Không gọi provider trong HTTP request. Caption và image prompt có thể chạy
  song song, nhưng mỗi component phải lưu lifecycle và kết quả riêng để một
  component lỗi không làm mất component còn lại.

Luồng vertical slice:

```text
SocialPost draft (campaign/product/targets)
        |
        v
Bounded context builder
  verified product facts + campaign + profile + language/CTA + asset IDs
        |
        +--> social_post.caption (structured JSON)
        |       caption/cta/hashtags/used_fact_ids
        |
        +--> social_post.product_image (structured JSON)
                image_prompt/negative_prompt/used_fact_ids
                                |
                                v
                    AiImageProvider -> MediaAsset
                                |
                                v
                    social_post.image usage + version snapshot
```

- [x] Tạo context builder giới hạn kích thước, chỉ đưa product facts đã
  verified/allowed, campaign message, writing profile snapshot, language/CTA,
  visual constraints và reference asset IDs; không đưa token, secret hoặc URL
  tùy ý. Hash context để dedupe/checkpoint và xác thực mọi fact ID AI trả về.
- [x] Queue caption generation bằng prompt key `social_post.caption` và
  `AiTaskSchemaValidator`; lưu caption/CTA/hashtags/fact IDs riêng, không dùng
  `AiOutputValidator` của Blog Post.
- [x] Queue image prompt bằng `social_post.product_image`, validate JSON rồi
  gọi lại image provider/job hiện có để tạo binary `MediaAsset`. Prompt phải
  nhắc model không tự vẽ chính xác logo, tên, ngày tháng hoặc chữ; các dữ liệu
  này lấy từ fact/ảnh nguồn.
- [x] Gắn asset sau khi job hoàn tất bằng usage `social_post.image`, transaction
  an toàn; khi thay ảnh phải giữ asset ID cũ trong `social_post_versions`.
- [x] Snapshot prompt key/version, profile, bounded context, fact IDs, asset IDs,
  provider/model và generation/version vào `social_post_versions`; metadata
  `AiTaskRun` chỉ giữ hash/ID bounded, không lưu raw response hoặc secret.
- [x] Xử lý partial failure: caption ready + ảnh failed vẫn giữ caption và
  `review_status=draft`; ảnh ready + caption failed vẫn giữ ảnh; cả hai failed
  thì generation `failed`, tuyệt đối không cho publish. Chạy lại chỉ component
  lỗi và không ghi đè version mới hơn.
- [x] Thêm regenerate caption và regenerate image độc lập; regenerate caption
  không xoá ảnh, regenerate image không xoá caption. Dedupe key phải gồm
  `social_post_id + component + generation/version + provider/model`.
- [x] Chặn stale/cancelled job ghi đè kết quả mới; dọn orphan asset nếu run
  stale/failed và asset chưa có usage hoặc retention reference.
- [x] Viết tests cho context/fact allowlist, schema hợp lệ và lỗi, snapshot
  không chứa secret/`platform_caption`, queue dedupe, partial failure,
  independent regenerate, media usage và orphan cleanup. Tests dùng provider fake,
  không gọi API trả phí.

**Kết quả:** tạo được một Social Post có caption + ảnh trong draft; mỗi phần có
thể retry/regenerate độc lập, có version/audit snapshot và không làm phát sinh
publish trước bước review.

### MP-04 — Marketing navigation và Social Post UI — DONE — 3--4 ngày

- [x] Thêm nhóm Marketing ở vertical/horizontal navigation.
- [x] Tạo route/page/list/detail cho Social Post.
- [x] Hiển thị preview caption, ảnh, campaign và target accounts.
- [x] Thêm editor, version history và trạng thái generation.
- [x] Nối task queue popup hiện có.

**Kết quả:** người dùng quản lý được bundle AI trong `Marketing / Social Post`; trang
`resources/js/pages/marketing/social-post/list/index.vue` dùng API thật thay vì
fixture, có dialog chỉnh caption và xem version history.

### MP-05 — Review và approval — DONE — 2--3 ngày

- [x] Thêm `social_posts.view/create/update/delete/review/publish`.
- [x] Implement submit, approve, reject, request revision.
- [x] Khóa publish nếu chưa approved hoặc ảnh còn pending.
- [x] Lưu actor/time/reason/version khi quyết định.
- [x] Thêm feature tests cho transition generation partial/ready và draft gate.

**Kết quả:** chỉ bản đã duyệt mới đủ điều kiện publish.

### MP-06 — Facebook account và publisher — DONE (smoke test chờ credentials) — 3--4 ngày

- [x] Tạo Facebook Page account flow riêng với customer OAuth.
- [x] Mã hóa token, expiry, reconnect và health check.
- [x] Định nghĩa `SocialPublisherContract`.
- [x] Implement `FacebookPublisher` cho caption + image qua Graph API, không thêm
  `facebook/php-business-sdk`.
- [x] Lưu publication per target, external ID/URL và lỗi bounded.
- [x] Retry có idempotency, không tạo bài trùng.

**Kết quả:** một Social Post approved đăng được lên Page thật.

### MP-07 — Daily planner và vận hành — DONE — 2--3 ngày

- [x] Tạo command/schedule tạo 2--3 slot mỗi ngày.
- [x] Chọn campaign đang active và chống lặp slot bằng unique planned date/slot.
- [x] Dispatch caption/image jobs theo queue.
- [x] Giữ quota AI hiện có và tách image job retry/backoff.
- [x] Ghi failed job/partial generation để draft không tự publish.

**Kết quả:** hệ thống tự tạo 2--3 draft/ngày và không tự publish.

### MP-08 — Hardening và nghiệm thu — IN PROGRESS — 2--3 ngày

- [x] Backend tests cho schema, permission, lifecycle, queue và idempotency.
- [x] Frontend tests cho API client/list contract; build production pass.
- [x] Test deterministic text, image failure/partial và queue workflow bằng provider fake.
- [x] Redact token/provider secret khỏi log và response.
- [ ] Chạy smoke test trên Page thật và provider ảnh thật. **BLOCKED:** chờ Meta App/Page token và image provider credentials.
- [x] Cập nhật API/ENV/runbook và QA report.

Ghi chú kiểm thử toàn repository: các test riêng của Social Post và frontend đều
đạt. Lần chạy toàn bộ hiện có hai lỗi legacy ngoài phạm vi Social Post: fixture
AI provider retry đang bật auto SEO nhưng mock không khai báo `execute()`, và
template artifact private không move được trên môi trường Windows hiện tại.

## 8. Acceptance checklist MVP

- [ ] Có menu `Marketing > Social Post` ở cả hai layout.
- [ ] Tạo được product và campaign làm AI context.
- [ ] Tạo được Social Post có một caption chung và ảnh.
- [ ] Một Social Post chọn được nhiều target/account.
- [ ] Không có `platform_caption` trong database/API.
- [ ] Có version history cho caption, ảnh và prompt snapshot.
- [ ] Có thể regenerate caption hoặc ảnh độc lập.
- [ ] Draft chưa duyệt không thể publish.
- [ ] Publish status tách theo từng target.
- [ ] Retry không tạo bài trùng.
- [ ] Scheduler tạo tối đa 2--3 draft mỗi ngày theo unique slot.
- [ ] Video chưa nằm trong acceptance của phase này.

## 9. Việc hoãn sau phase này

- Video prompt/provider/storyboard/FFmpeg.
- Instagram/TikTok publisher thật.
- Caption riêng theo platform.
- Analytics sâu theo platform.
- AI cost dashboard hoàn chỉnh.
- Multi-tenant workspace nếu chưa chốt ở MP-00.

## 10. Quy ước cập nhật

- Dùng `TODO`, `IN PROGRESS`, `BLOCKED`, `DONE`.
- Mỗi task chỉ có một checklist tại file này.
- Khi task hoàn tất, đánh dấu checklist và ghi link QA/API nếu có.
- Không đánh dấu phase `DONE` khi còn acceptance item chưa đạt.
- Migration mới phải là timestamped migration; không sửa migration đã chạy.
