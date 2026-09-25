# Kế hoạch triển khai nền tảng bán Digital Resources

**Phiên bản:** 2.0
**Ngày cập nhật:** 2026-09-25
**Trạng thái:** Kế hoạch thực thi
**Repository:** `D:\AI\market-template`

## 1. Mục tiêu và nguyên tắc

Xây dựng một cửa hàng bán và phân phối tài nguyên số cho developer/designer. Sản phẩm đầu tiên là một cửa hàng do một đội ngũ quản lý; marketplace nhiều seller chỉ được mở sau khi mô hình bán hàng đã được kiểm chứng.

Các nguyên tắc bắt buộc:

- Public catalog và blog render bằng Laravel Blade để tối ưu SEO.
- Admin dashboard chạy Vue 3 + Vuetify trên `/admin`.
- Laravel là source of truth cho authentication, authorization, resource, order, payment và download.
- Frontend chỉ gọi API; không truy cập database và không quyết định entitlement.
- Dùng `spatie/laravel-permission` cho role/permission.
- Dùng `spatie/laravel-medialibrary` cho toàn bộ file và ảnh; không tạo bảng `mediables` hoặc media manager riêng.
- Dùng `spatie/laravel-activitylog` cho audit trail; không tạo `audit_logs` riêng.
- Dùng một bảng `slugable` tập trung cho các model có URL public; không đặt cột `slug` rải rác trên từng bảng.
- Frontend viết JavaScript; không thêm TypeScript vào module mới.
- Customer dùng Socialite với `customer` session guard; admin dùng `admin` session guard và CSRF.
- API authentication dùng Laravel Sanctum personal access token với `Authorization: Bearer ...`; không lưu JWT tự viết.
- Chỉ thêm abstraction khi có một domain boundary rõ ràng hoặc có kiểm thử độc lập.

## 1.1. Chuẩn comment bắt buộc cho developer và AI

Đây là quy định bắt buộc của repository. Mọi developer, code reviewer và AI agent phải đọc và tuân thủ trước khi tạo hoặc chỉnh sửa code.

### Phạm vi áp dụng

- Mỗi class phải có comment mô tả ở ngay phía trên khai báo class.
- Mỗi method/function nằm trong class phải có comment mô tả ở ngay phía trên method.
- Comment phải mô tả đúng behavior hiện tại của code, không mô tả ý định chưa triển khai.
- Khi thay đổi input, output, side effect, exception hoặc transaction boundary, phải cập nhật comment trong cùng pull request.
- Không được xóa comment chỉ để làm diff ngắn hơn.
- Comment của method phải ghi rõ nếu method yêu cầu transaction, lock, authenticated user, policy hoặc quyền cụ thể.
- Comment không thay thế type declaration, Form Request, Policy, test hoặc validation.

### Template comment chuẩn

Class và method phải dùng block comment theo cấu trúc sau. Có thể thay nội dung bên trong cho đúng class/method, nhưng không được bỏ các nhóm thông tin chính.

```php
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tăng state_revision của bàn để đánh dấu projection vừa thay đổi
 * =====================================================================
 *
 * Action phụ trợ được gọi ở cuối mọi transaction nghiệp vụ POS (thêm món, thanh
 * toán, mở bàn, phiếu bếp...) để mỗi lần thay đổi dữ liệu bàn phát ra một
 * revision tăng dần. Event PosStateChanged phát sau commit chỉ cần mang revision
 * này, giúp client biết chính xác trạng thái nào đã được lưu thành công.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(DiningTable $lockedTable): tăng state_revision lên 1 và trả giá trị mới
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : DiningTable đã giữ khóa ghi (lockForUpdate) truyền vào handle() trong transaction của caller
 * - OUTPUT: handle() trả int state_revision mới (refresh từ DB); side effect: UPDATE cột state_revision
 *           của dining_tables; throw ModelNotFoundException không xảy ra ở đây (bàn phải tồn tại sẵn)
 * =====================================================================
 */
final class IncrementDiningTableRevisionAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tăng state_revision của bàn đã được khóa ghi
     * =====================================================================
     *
     * INPUT:
     * - $lockedTable: DiningTable đã được lockForUpdate() trong transaction của caller
     *
     * OUTPUT:
     * - int: state_revision mới sau khi refresh từ database
     *
     * SIDE EFFECT:
     * - UPDATE state_revision của dining_tables
     *
     * EXCEPTION/TRANSACTION:
     * - Không tự mở transaction; caller chịu trách nhiệm transaction và lock
     * =====================================================================
     */
    public function handle(DiningTable $lockedTable): int
    {
        // implementation
    }
}
```

### Quy tắc viết comment

- `CHỨC NĂNG FILE` mô tả trách nhiệm chính của file/class trong một câu.
- Phần mô tả class phải giải thích context nghiệp vụ, nơi class được gọi và lý do tồn tại.
- `CÁC HÀM/METHOD TRONG FILE` phải liệt kê toàn bộ public method, protected method và private method quan trọng.
- Comment method phải ghi `INPUT`, `OUTPUT`, `SIDE EFFECT` và `EXCEPTION/TRANSACTION` khi có liên quan.
- Với query/list method, ghi rõ filter, sort, pagination và quan hệ được eager load.
- Với action/mutation, ghi rõ transaction boundary, lock, event/job được phát và dữ liệu bị thay đổi.
- Với upload/download, ghi rõ disk, collection, authorization, temporary URL và rate limit.
- Với OAuth/authentication, ghi rõ guard, provider, session và identity linking rule.
- Không đưa secret, access token, password hoặc dữ liệu cá nhân thật vào comment.
- Nếu method quá đơn giản nhưng vẫn là method của class, vẫn phải có comment ngắn theo cùng cấu trúc.

### Quy tắc cho AI agent

AI agent chỉ được tạo hoặc sửa class/method sau khi:

1. Đọc phần comment đầu file hiện tại.
2. Giữ nguyên cấu trúc comment và cập nhật nội dung nếu behavior thay đổi.
3. Thêm comment cho class/method mới trước khi viết implementation.
4. Kiểm tra `INPUT/OUTPUT`, side effect, exception và transaction có khớp code thực tế.
5. Báo trong review nếu file cũ chưa có comment chuẩn và task có chạm vào file đó.

Pull request thiếu comment bắt buộc hoặc comment không còn khớp behavior sẽ không đạt Definition of Done.

## 1.2. Chuẩn architecture bắt buộc

### Laravel

- Tuân theo Laravel architecture: Route → Middleware → Form Request → Controller → Action/Service → Model/Policy → Resource/Response.
- Controller chỉ điều phối HTTP; không chứa business rule dài, query phức tạp hoặc transaction không có tên.
- Validation nằm trong Form Request.
- Authorization nằm trong Policy, Gate hoặc permission middleware của Spatie.
- Business mutation nhiều bước nằm trong Action/Service và ghi rõ transaction boundary.
- Query dùng Eloquent scope/query object khi được tái sử dụng; list endpoint phải eager load quan hệ cần trả về.
- Model giữ relation, cast, scope và invariant cấp model; không biến model thành một service lớn.
- Job dùng cho conversion, scan, email, webhook retry và aggregate; job phải idempotent khi có thể.
- Không truy cập database từ Blade hoặc Vue.
- Migration phải có foreign key, index, rollback và không sửa migration đã chạy ở môi trường dùng chung.

### Vue 3

- Tất cả module frontend mới dùng Vue 3 Composition API và `<script setup>`.
- Frontend dùng JavaScript theo quyết định của project; không thêm TypeScript vào module mới.
- Page là composition surface; tách form, table, filter, dialog và feature panel thành component nhỏ.
- State server dùng Pinia/composable; state dẫn xuất dùng `computed`; watcher chỉ dùng cho side effect.
- Props đi xuống, events đi lên; `v-model` chỉ dùng cho contract hai chiều rõ ràng.
- API client và mapping response nằm trong `services/` hoặc composable; không gọi API rải rác trong template.
- Backend Laravel là source of truth cho auth, permission, validation và entitlement; CASL chỉ điều khiển UX.
- Mọi task Vue phải có loading, empty, error và retry state phù hợp.

## 1.3. Quy tắc cập nhật trạng thái task

- Mỗi task trong roadmap phải có trạng thái `TODO`, `IN PROGRESS`, `BLOCKED` hoặc `DONE`.
- Khi task hoàn thành, phải đánh dấu `[x]` ngay trong `docs/PLAN.md` ở đúng đợt và cập nhật `Đợt gần nhất đã hoàn thành`.
- Không đánh dấu cả đợt là `DONE` nếu còn một acceptance criterion chưa đạt.
- Nếu task bị dừng do phụ thuộc bên ngoài, ghi rõ blocker, owner và bước tiếp theo.
- Developer/AI phải đọc status trước khi bắt đầu để không triển khai lại task đã `DONE`.

### Trạng thái hiện tại

- Đợt 0 — Foundation: `DONE` cho dependency, package migrations, Sanctum package/config/migration, schema V1 và schema rollback test; `IN PROGRESS` cho route shell, environment docs và tắt MSW production.
- Đợt 1 — Authentication và permission: `IN PROGRESS`.
- Đợt gần nhất đã hoàn thành: migration V1, package setup, schema test và role/permission seeder.

## 2. Phạm vi theo giai đoạn

### 2.1 MVP 1: catalog và free download

MVP đầu tiên chỉ cần chứng minh được chuỗi sau:

```text
Admin tạo resource → upload version/package → publish
→ khách xem catalog → đăng nhập → tải package private
→ hệ thống ghi download history
```

Bao gồm:

- Public home, catalog, category và resource detail.
- Khách đăng nhập Google/Facebook, logout và hoàn tất email nếu nhà cung cấp không cung cấp email tin cậy.
- Quản trị viên đăng nhập email/password, logout và đặt lại mật khẩu.
- Admin resource CRUD.
- Resource status, visibility, slug và taxonomy cơ bản.
- Resource version.
- Upload cover, preview và package.
- Public/private storage qua Media Library.
- Free download có authorization, rate limit, activity event và download history.
- Admin roles/permissions.
- Loading, empty và error state ở admin.

Chưa đưa vào MVP 1:

- Payment thật.
- Cart/coupon.
- License activation.
- Review, Q&A, support ticket.
- Multi-vendor.
- Search engine riêng.
- Polymorphic SEO và generic metadata.

### 2.2 MVP 2: blog và SEO

- Post CRUD, draft/review/published.
- Revision cơ bản.
- Category/tag dùng lại từ catalog nếu phù hợp.
- Cover image qua Media Library.
- Meta title, description, canonical và Open Graph.
- Sitemap, robots và legal pages.

### 2.3 MVP 3: commerce

- Resource variants và license terms snapshot.
- Cart hoặc direct checkout; chọn một flow trước.
- Một payment provider ở test mode.
- Webhook inbox, signature validation và idempotency.
- Order, payment, entitlement và invoice.
- Refund test flow.
- Paid download sau khi webhook xác nhận.

### 2.4 Giai đoạn sau khi có người dùng thật

- License key/activation nếu sản phẩm thực sự cần.
- Review, wishlist, collection, follow.
- Support ticket và knowledge base.
- Search nâng cao và Meilisearch.
- Newsletter, update notification và conversion analytics.
- Multi-vendor, KYC, payout và revenue share.

## 3. Kiến trúc được chốt

### 3.1 Stack

| Khu vực | Công nghệ |
|---|---|
| Backend | Laravel 12, PHP 8.2+ |
| Public/account | Laravel Blade |
| Admin | Vue 3.5, Composition API, `<script setup>`, JavaScript |
| UI | Vuetify 3.10.8 hiện tại của repository |
| State admin | Pinia |
| Router admin | Vue Router auto hiện có |
| Customer auth | Laravel Socialite + `customer` session guard |
| Admin auth | `admin` session guard + CSRF trên cùng origin |
| API auth | Laravel Sanctum personal access tokens |
| Permission | `spatie/laravel-permission` |
| File/media | `spatie/laravel-medialibrary` |
| Activity log | `spatie/laravel-activitylog` |
| Slug | Bảng `slugable` + `ResolveSlugAction` nội bộ |
| Database | MySQL 8 trong `compose.yaml` |
| Cache/queue/rate limit | Redis |
| Storage | Local private ở development, S3-compatible ở production |
| Build | Vite |
| Tests | PHPUnit/Pest-compatible Laravel tests, Vitest/Playwright khi frontend flow bắt đầu |

### 3.2 Boundary URL

Public Blade:

- `/`
- `/resources`
- `/resources/{slug}`
- `/categories/{slug}`
- `/blog`
- `/blog/{slug}`
- `/terms`, `/privacy`, `/license-terms`, `/refund-policy`

Account Blade hoặc API account:

- `/account`
- `/account/downloads`
- `/account/orders`
- `/account/profile`

Admin Vue:

- `/admin`
- `/admin/{path}`

API:

- `/api/admin/*`
- `/api/account/*`
- `/api/resources/*`

Webhook và download:

- `/webhooks/{provider}`
- `/download/{download}`

Catch-all route chỉ được dùng cho `/admin/{path}` sau khi đã tách public routes.

### 3.3 Auth và authorization

- Customer đăng nhập qua Google/Facebook bằng Laravel Socialite và `customer` guard.
- Admin đăng nhập bằng email/password qua `admin` guard; không dùng OAuth customer để vào admin.
- Admin API cùng origin dùng session guard `auth:admin` và CSRF.
- API Bearer request dùng `auth:sanctum`; guard/token ability không thay thế Policy và Spatie Permission.
- Session guard vẫn được giữ cho OAuth callback và browser redirect; Vue API client sẽ dùng Sanctum token sau khi token issue flow được triển khai.
- `User` là model quản trị, dùng trait `HasRoles` của Spatie; `Customer` không có back-office role.
- `User` và `Customer` đều implement `Authenticatable`, nhưng mỗi model thuộc một provider/guard riêng.
- `config/auth.php` có hai provider (`users`, `customers`), hai session guard (`admin`, `customer`) và password broker riêng cho admin.
- `/api/account/*` dùng `auth:customer`; `/admin` và `/api/admin/*` dùng `auth:admin`, kiểm tra `status=active` và CSRF cho mutation.
- Hai guard có thể dùng chung session store; tách guard/provider không tự tạo hai cookie hoặc hai miền bảo mật. Nếu cần cách ly cookie, thiết kế subdomain và session domain riêng ở giai đoạn vận hành.
- Chỉ tạo bản ghi `users` admin qua seeder/luồng mời do quản trị viên cấp quyền; không có endpoint đăng ký admin công khai.
- Tìm OAuth identity bằng cặp `(provider, provider_user_id)` trước. Không tự liên kết tài khoản chỉ vì email provider trùng email đã có; liên kết thêm identity phải yêu cầu customer đang đăng nhập hoặc quy trình xác minh riêng.
- Không đánh dấu `email_verified_at` chỉ dựa vào email trả về từ provider; cần tín hiệu xác minh đáng tin cậy hoặc email verification của ứng dụng.
- Dùng permission middleware cho coarse route access.
- Dùng Laravel Policy cho quyền theo model, ownership và trạng thái resource.
- CASL chỉ ẩn/hiện action ở UI; không được xem là security boundary.
- Không lưu access token demo trong cookie khi API thật đã bật.

Roles ban đầu:

- `super-admin`
- `admin`
- `editor`
- `support`

Permission ban đầu:

- `resources.view`
- `resources.create`
- `resources.update`
- `resources.delete`
- `resources.publish`
- `resources.archive`
- `resource_versions.manage`
- `media.view`
- `media.manage`
- `posts.manage`
- `posts.publish`
- `orders.view`
- `orders.refund`
- `users.view`
- `users.manage`
- `analytics.view`
- `settings.manage`

Không tạo bảng roles, permissions, model_has_roles hoặc role_has_permissions thủ công; dùng migration/config của Spatie.
Tất cả role/permission của quản trị dùng `guard_name = admin`. Không tạo role/permission cho customer khi customer không có back-office permission.

## 4. Tích hợp Spatie

### 4.1 Permission

Việc cài đặt và seed permission phải là một task Foundation riêng:

1. Cài package tương thích Laravel 12.
2. Publish config và migration của package.
3. Giữ `User` model cho admin và thêm `HasRoles` vào `App\Models\User`.
4. Tạo `RolePermissionSeeder` idempotent.
5. Đăng ký middleware alias theo tên package.
6. Viết policy test và route authorization test.
7. Cache permission đúng theo hướng dẫn package và clear cache khi seed/update.

Quy tắc:

- Dùng permission cho hành động, role để gom permission.
- Không kiểm tra tên role ở mọi controller.
- Không tin role/permission do client gửi lên.
- Khi một admin bị vô hiệu hóa, mọi admin route/API phải từ chối truy cập.
- Quyền Spatie của quản trị viên luôn có `guard_name = admin`; tránh tạo bản sao permission dưới guard `web`.

### 4.2 Media Library

Model nào sở hữu file phải `implements HasMedia` và dùng `InteractsWithMedia`.

Collections chuẩn:

| Model | Collection | Disk | Mục đích |
|---|---|---|---|
| `Resource` | `cover` | public | Ảnh cover |
| `Resource` | `preview` | public | Gallery/preview |
| `ResourceVersion` | `package` | private | ZIP/source package |
| `ResourceVersion` | `documentation` | private hoặc public | Tài liệu theo policy |
| `Post` | `cover` | public | Ảnh bài viết |
| `User` | `avatar` | public | Avatar |
| `Invoice` | `pdf` | private | Hóa đơn |

Quy tắc file:

- Không tự tạo bảng `media`, `mediables` hoặc pivot media riêng.
- Dùng bảng `media` do Media Library publish.
- Package luôn ở private disk.
- Cover/preview có conversion `thumb` và `web`.
- Conversion chạy queue; upload response không chờ conversion nặng.
- Lưu `checksum`, scan status và upload metadata bằng custom properties của Media Library.
- Validate MIME, extension, size và archive trước khi đánh dấu package `ready`.
- Chặn path traversal, symlink nguy hiểm, file executable và archive vượt giới hạn.
- Download package qua controller authorize trước rồi cấp temporary URL hoặc stream private file.
- Không expose path nội bộ hoặc URL S3 lâu hạn.

Upload pipeline:

```text
Request validation → temporary upload → Media Library
→ checksum → archive scan → conversion/thumbnail queue
→ media status ready → attach resource/version → activity event
```

Media Library yêu cầu migration `media`, disk riêng và queue cho conversion. Cấu hình disk/conversion phải được lưu trong `config/media-library.php` và `.env`, không hard-code trong controller.

### 4.3 Activity Log

Việc cài đặt và seed activity log là một task Foundation riêng:

1. Cài `spatie/laravel-activitylog` phiên bản tương thích Laravel 12.
2. Publish migration/config chính thức của package.
3. Dùng bảng `activity_log` do package cung cấp.
4. Thêm `LogsActivity` cho các model business cần theo dõi.
5. Dùng `logOnlyDirty()` và `logExcept()` để không ghi counter, password, token, webhook payload hoặc dữ liệu thanh toán nhạy cảm.
6. Dùng `log_name` theo domain: `auth`, `resources`, `media`, `orders`, `downloads`.
7. Ghi activity thủ công cho publish, archive, refund, entitlement revoke và download bị chặn.

`activity_log` là audit trail, còn `downloads`, `payments` và `webhook_events` vẫn là bảng nghiệp vụ riêng. Không dùng activity log để thay thế các bảng nghiệp vụ này.

Activity Log hỗ trợ subject và causer polymorphic, custom properties và tự động ghi model events. Job hoặc command không có user đăng nhập phải gán causer tường minh khi cần.

### 4.4 Centralized slugable

Không dùng `spatie/laravel-sluggable` theo cách mặc định vì cách đó lưu slug trực tiếp trên model. Project này dùng model `Slugable` và `SlugService` nội bộ để lưu tất cả slug tập trung trong bảng `slugable`.

Các model có URL public trong MVP:

- `Resource`
- `Category`
- `Tag`
- `Post`

Các model nội bộ như User, Order, Payment, Media, Download không cần slug để tránh tạo URL không cần thiết.

Quy tắc:

- Khi tạo hoặc đổi title/name, `SlugService` tạo slug trong transaction.
- Slug hiện tại có `is_primary = true`.
- Slug cũ giữ lại với `is_primary = false` để redirect 301.
- Controller resolve theo `sluggable_type + slug + locale`.
- Nếu tìm thấy slug cũ, redirect tới primary slug hiện tại.
- Collision được xử lý bằng suffix (`-2`, `-3`) trong cùng model type và locale.
- Mỗi model chỉ có một primary slug cho mỗi locale; invariant này phải được kiểm tra bằng action và feature test.

## 5. Mô hình dữ liệu canonical

MVP dùng bảng `slugable` tập trung cho các model có URL public. SEO vẫn giữ bằng column trực tiếp trên `resources` và `posts` để tránh thêm một polymorphic SEO table.

Tên bảng `slugable` được giữ đúng theo quyết định của project. Đây là bảng liên kết polymorphic, không phải pivot giữa hai entity.

`slugable`:

- `id`
- `sluggable_type`
- `sluggable_id`
- `slug`
- `locale` default theo `APP_LOCALE`
- `is_primary`
- `created_at`, `updated_at`

Indexes:

- unique(`sluggable_type`, `slug`, `locale`)
- index(`sluggable_type`, `sluggable_id`, `locale`)
- index(`slug`, `locale`)

Slug cũ không bị xóa. Resolver tìm slug cũ, lấy model tương ứng, sau đó redirect 301 về primary slug. Việc bảo đảm mỗi model chỉ có một primary slug mỗi locale nằm trong `SlugService` và transaction.

`activity_log` dùng migration chính thức của Spatie, thường gồm:

- `id`, `log_name`, `description`, `event`
- `subject_type`, `subject_id` để biết object bị tác động
- `causer_type`, `causer_id` để biết user/job gây ra thay đổi
- `properties` JSON cho metadata và attribute changes
- `batch_uuid` nếu bật batch tracking
- timestamps và các index polymorphic của package

Không thêm `old_values` hoặc `new_values` vào bảng riêng. Attribute changes được lưu trong `properties` theo cơ chế của Activity Log.

### 5.1 Core tables tự quản lý

`users` giữ đúng vai trò tài khoản quản trị, dựa trên migration mặc định của Laravel:

- `name`
- `email` unique
- `email_verified_at` nullable
- `username` nullable unique
- `password` bắt buộc và được hash
- `status` (`active`, `suspended`, `pending`)
- `last_login_at` nullable
- `remember_token`
- `deleted_at`
- timestamps

`customers` là hồ sơ và tài khoản nội bộ của khách hàng:

- `id`
- `name`
- `email` nullable unique; bắt buộc hoàn tất trong onboarding nếu OAuth không trả email
- `email_verified_at` nullable; không suy ra trạng thái verified chỉ từ một email OAuth
- `status` (`active`, `suspended`)
- `last_login_at` nullable
- `deleted_at`
- timestamps

Customer không dùng password trong flow OAuth mặc định. Không tạo endpoint đăng ký/password reset cho customer trong MVP.

`users` chỉ dùng cho admin; customer OAuth không thể dùng `admin` guard và không tự động nhận admin role.

Password reset của admin dùng broker `admins` và bảng `password_reset_tokens` mặc định của Laravel. Customer không có password reset trong MVP.

`customer_identities` lưu các identity OAuth của customer:

- `id`
- `customer_id` foreign key tới customers
- `provider` (`google`, `facebook`)
- `provider_user_id`
- `provider_email` nullable
- `provider_name` nullable
- `provider_avatar_url` nullable
- `last_used_at` nullable
- timestamps

Indexes:

- unique(`provider`, `provider_user_id`)
- index(`customer_id`, `provider`)

Không lưu access token/refresh token nếu chỉ cần đăng nhập. Nếu có tính năng gọi API provider về sau, token phải được mã hóa và có migration riêng.

`resources`:

- `id`
- `author_id` nullable foreign key tới users (admin đứng tên phát hành)
- `created_by`, `updated_by` nullable foreign key tới users
- `type`
- `title`
- `code` nullable unique
- `short_description` nullable
- `description` LONGTEXT nullable
- `status` (`draft`, `pending_review`, `published`, `suspended`, `archived`)
- `visibility` (`public`, `members`, `private`)
- `is_featured`
- `demo_url`, `documentation_url` nullable
- `seo_title`, `seo_description`, `canonical_url` nullable
- `view_count`, `download_count`
- `published_at` nullable
- timestamps, soft deletes

`resource_versions`:

- `id`, `resource_id`
- `version` unique per resource
- `changelog` LONGTEXT nullable
- `requirements` JSON nullable
- `status` (`draft`, `ready`, `archived`)
- `is_default`
- `released_at`, `created_by` foreign key tới users
- timestamps, soft deletes

`categories`:

- `id`, `parent_id` nullable
- `name`
- `description` nullable
- `status`, `sort_order`
- timestamps, soft deletes

`tags`:

- `id`, `name`
- `description` nullable
- timestamps, soft deletes

Pivots:

- `categorizables`: `category_id`, `categorizable_type`, `categorizable_id`, `sort_order`
- `taggables`: `tag_id`, `taggable_type`, `taggable_id`
- `resource_technology`: `resource_id`, `technology_id`, `version_constraint`

`technologies`:

- `id`, `name`, `type`
- timestamps

`downloads`:

- `id`, `customer_id` nullable, `resource_id`, `resource_version_id`, `media_id`
- MVP 1 chưa có `entitlement_id`; thêm cột nullable và foreign key ở migration Commerce sau khi tạo `entitlements`
- `ip_hash`, `user_agent` nullable
- `status` (`started`, `completed`, `blocked`, `failed`)
- `downloaded_at`
- indexes theo user/resource/date

### 5.2 Commerce tables chỉ tạo ở MVP 3

`resource_variants`:

- `resource_id`, `name`, `code`
- `price_minor`, `currency`
- `billing_type` (`free`, `one_time`)
- `update_until_days`, `support_until_days` nullable
- `license_terms` JSON nullable
- `is_active`

`orders`:

- `customer_id`, `order_number` unique
- `status` (`pending`, `paid`, `failed`, `cancelled`, `refunded`, `partially_refunded`)
- `currency`, `subtotal_minor`, `discount_minor`, `tax_minor`, `total_minor`
- `provider`, `provider_reference` nullable
- `paid_at`, `cancelled_at` nullable
- timestamps

`order_items`:

- `order_id`, `resource_id`, `resource_version_id` nullable, `resource_variant_id`
- `title_snapshot`, `license_terms_snapshot` JSON nullable
- `unit_price_minor`, `quantity`, `subtotal_minor`

`payments`:

- `order_id`, `provider`, `provider_payment_id` unique per provider
- `status`, `amount_minor`, `currency`
- `payload` JSON nullable, `paid_at`
- timestamps

`entitlements`:

- `customer_id`, `resource_id`, `resource_variant_id`, `order_id`
- `status` (`active`, `expired`, `suspended`, `revoked`)
- `starts_at`, `expires_at`, `update_until`, `support_until` nullable
- unique phù hợp cho một order item/resource

`webhook_events`:

- `provider`, `provider_event_id` unique composite
- `event_type`, `payload` JSON
- `status`, `attempts`, `processed_at`, `last_error`
- timestamps

`invoices` và `refunds` chỉ tạo khi payment provider đã được chọn và flow test đã chạy.

### 5.3 Blog tables tạo ở MVP 2

`posts`:

- `author_id` foreign key tới users, `title`
- `excerpt` nullable, `body` LONGTEXT
- `status` (`draft`, `review`, `scheduled`, `published`, `archived`)
- `seo_title`, `seo_description`, `canonical_url` nullable
- `view_count`, `published_at`
- timestamps, soft deletes

`post_revisions`:

- `post_id`, `user_id` foreign key tới users, `title`, `excerpt`, `body`, timestamps

### 5.4 Không tạo trong MVP

- Bảng `slugs` hoặc `slugable` thứ hai.
- Cột `slug` riêng trên resources, categories, tags, technologies hoặc posts.
- Generic `meta_data` hoặc EAV counter.
- Generic `user_meta` cho field cố định.
- Custom `mediables`.
- Custom `audit_logs`; dùng `activity_log` của Spatie.
- License activation tables khi chưa có yêu cầu kỹ thuật.
- Multi-vendor seller/payout tables.

## 6. Migration order

1. Migrations mặc định của Laravel: users, password reset, sessions, cache, jobs.
2. Mở rộng users cho admin status và profile.
3. `customers`.
4. `customer_identities`.
5. Published migrations của Spatie Permission.
6. Published migration của Spatie Media Library.
7. Published migration của Spatie Activity Log (`activity_log`).
8. Published migration của Sanctum (`personal_access_tokens`).
9. `slugable`.
10. resources.
11. categories và `categorizables`.
12. tags và `taggables`.
13. technologies và `resource_technology`.
14. resource_versions.
15. downloads, chưa có `entitlement_id`.
16. posts và post_revisions khi bắt đầu MVP 2.
17. resource_variants khi bắt đầu MVP 3.
18. orders, order_items và payments.
19. entitlements.
20. Bổ sung `downloads.entitlement_id` nullable với foreign key sau khi có `entitlements`.
21. webhook_events.
22. invoices và refunds.
23. favorites/reviews/support/growth sau khi có use case.

Foreign key vòng giữa users, media và resource owner phải được xử lý bằng migration bổ sung sau khi bảng chính đã tồn tại. `slugable` và `activity_log` dùng polymorphic key nên không có foreign key SQL trực tiếp tới mọi model. Mọi migration phải có `up`, `down`, index và test rollback.

## 7. Backend structure

```text
app/
├── Actions/
│   ├── Resources/
│   ├── Media/
│   ├── Downloads/
│   └── Commerce/
├── Enums/
├── Http/
│   ├── Controllers/Public/
│   ├── Controllers/Admin/
│   ├── Controllers/Account/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Models/
├── Notifications/
├── Policies/
├── Services/
└── Support/
```

Quy tắc backend:

- Controller nhận request, gọi Action/Query và trả response.
- Validation nằm trong Form Request.
- Authorization nằm trong Policy và permission middleware.
- Mutation nhiều bước chạy trong transaction.
- Conversion, scan, email và aggregate chạy queue.
- API response có format nhất quán cho data, errors, meta.
- Eloquent list phải eager load quan hệ cần hiển thị.
- Download authorization phải chạy ngay trước khi cấp URL.

Actions ưu tiên:

- `CreateResourceAction`
- `UpdateResourceAction`
- `PublishResourceAction`
- `CreateResourceVersionAction`
- `UploadMediaAction`
- `ScanPackageAction`
- `AuthorizeDownloadAction`
- `CreateDownloadAction`
- `CreateOrderAction`
- `ProcessWebhookAction`
- `GrantEntitlementAction`
- `RefundOrderAction`

## 8. Frontend admin bằng JavaScript

### 8.1 Quy ước

- Tất cả module mới dùng `.js` và `<script setup>`.
- Dùng Composition API.
- Không thêm `lang="ts"`, interface hoặc type-only import.
- Props xuống, emits lên; dùng `v-model` cho contract hai chiều thật sự.
- Page là composition surface; form/list/filter/dialog tách component.
- State server ở Pinia hoặc composable theo feature.
- Derived state dùng `computed`; watcher chỉ dùng cho side effect.
- Composable trả state và action rõ ràng; không mutate tùy tiện từ component khác.
- Giữ `VDataTableServer` với `items-length`, server sort và server pagination.

### 8.2 Cấu trúc admin

```text
resources/js/
├── pages/admin/
├── views/admin/
├── components/admin/
├── stores/
├── composables/
├── services/
│   ├── api.js
│   ├── resources.js
│   ├── media.js
│   └── auth.js
└── plugins/
```

Mỗi feature resource có:

- `pages/admin/resources/index.vue`: composition surface.
- `views/admin/resources/ResourceTable.vue`: list/filter/pagination.
- `views/admin/resources/ResourceForm.vue`: create/edit form.
- `views/admin/resources/ResourceVersionPanel.vue`: version/package.
- `composables/useResources.js`: fetch, filters, pagination, mutation.
- `services/resources.js`: endpoint mapping.

### 8.3 Vuetify

- Giữ theme, defaults, icon set và SCSS override Vuexy hiện tại.
- Không nâng Vuetify major trong lúc hoàn thành MVP.
- Dùng `VForm` cho client validation và map lỗi server vào field.
- Dùng `VDataTableServer` cho resources, users, orders và media.
- Dùng `VFileInput` hoặc upload component của project để gửi `FormData`.
- Bổ sung loading overlay, empty state, error alert và retry action.
- Chỉ dùng `v-html` với nội dung đã sanitize ở backend.
- Chuẩn hóa text/icon/theme token trong defaults thay vì rải style inline.

### 8.4 Fake API

- MSW chỉ chạy khi `VITE_ENABLE_MSW=true`.
- Không import toàn bộ fake handler trong production build.
- Sau Foundation, chuyển `useApi` sang endpoint Laravel thật.
- Xóa demo credentials khỏi production UI.

## 9. API tối thiểu

### 9.1 Auth

- `GET /auth/{provider}/redirect` (`google` hoặc `facebook`)
- `GET /auth/{provider}/callback`
- `POST /auth/logout`
- `POST /admin/login`
- `POST /admin/logout`
- `GET /api/admin/me`
- `POST /admin/password/forgot`
- `POST /admin/password/reset`
- `GET /api/account/me`

### 9.2 Admin resources

- `GET /api/admin/resources`
- `POST /api/admin/resources`
- `GET /api/admin/resources/{resource}`
- `PUT /api/admin/resources/{resource}`
- `DELETE /api/admin/resources/{resource}`
- `POST /api/admin/resources/{resource}/publish`
- `POST /api/admin/resources/{resource}/archive`

### 9.3 Versions và media

- `GET /api/admin/resources/{resource}/versions`
- `POST /api/admin/resources/{resource}/versions`
- `PUT /api/admin/resource-versions/{version}`
- `POST /api/admin/resource-versions/{version}/package`
- `POST /api/admin/media`
- `DELETE /api/admin/media/{media}`

### 9.4 Public và account

- `GET /api/resources`
- `GET /api/resources/{slug}`
- `POST /api/resources/{slug}/download`
- `GET /api/account/downloads`
- `GET /api/account/profile`
- `PUT /api/account/profile`

### 9.5 Commerce sau MVP 2

- `POST /api/checkout`
- `GET /api/account/orders`
- `GET /api/account/orders/{order}`
- `GET /api/account/entitlements`
- `POST /webhooks/{provider}`

## 10. Download security

Download flow:

```text
Request → auth nếu visibility yêu cầu
→ load published resource/version/media
→ Policy + entitlement check
→ rate limit user/IP
→ tạo download record
→ tăng counter theo transaction/atomic update
→ temporary URL hoặc private stream
```

Quy tắc:

- Guest chỉ tải resource được phép public/free.
- Members-only cần verified account theo policy đã chốt.
- Paid package chỉ tải khi entitlement active.
- URL hết hạn trong thời gian ngắn.
- Không ghi token đầy đủ vào log.
- IP dùng cho abuse detection phải hash hoặc có retention policy.
- Refund/revoke phải ảnh hưởng tới quyền tải theo license policy.

## 11. Roadmap thực thi

### Đợt 0 — Foundation

**Status:** `IN PROGRESS`

Deliverables:

- [ ] Tạo `.env`, APP_KEY và database local.
- [ ] Chọn npm hoặc pnpm và giữ một lockfile.
- [ ] Tách public Blade và admin Blade.
- [ ] Tách route public/admin/API.
- [x] Cài Socialite, Spatie Permission, Media Library và Activity Log.
- [x] Cài Laravel Sanctum và publish `personal_access_tokens` migration/config.
- [x] Tạo migration V1 cho users/customers/identities/slugable/catalog/version/download.
- [x] Publish migrations/config của các package.
- [ ] Tắt MSW mặc định ở production.
- [ ] Tạo `/api/health`.
- [ ] Cập nhật README và environment docs.

Exit criteria:

- `/` trả public placeholder.
- `/admin` mount Vue admin.
- `/api/health` trả JSON.
- `php artisan migrate:fresh --seed` chạy được.
- `npm run build` chạy được.
- `php artisan test` chạy được sau khi tạo APP_KEY.

### Đợt 1 — Auth và permission

**Status:** `IN PROGRESS`

Deliverables:

- [x] Google/Facebook OAuth callback cho customer, xác minh identity theo provider ID.
- [x] Admin email/password login/logout/reset.
- [x] `admin` và `customer` guard/provider.
- [x] Thêm `HasApiTokens` cho User/Customer và cấu hình Sanctum guards.
- [ ] Issue/revoke Sanctum token ở auth endpoint.
- [x] Customer identity linking an toàn và onboarding khi OAuth thiếu email.
- [x] Admin status và session invalidation middleware.
- [x] Roles/permissions seeder.
- [ ] Policy và permission middleware cho resource.
- [ ] Admin xem danh sách customer cơ bản.
- [ ] Vue admin login dùng session API thật, bỏ fake token flow.
- [ ] CASL nhận ability từ API để điều khiển UI.

Exit criteria:

- Guest không vào admin.
- Customer OAuth không thể dùng `admin` guard.
- Email trùng giữa hai identity không tự động hợp nhất customer.
- Admin bị suspended không đăng nhập hoặc gọi admin API.
- Customer không publish resource.
- Editor có đúng permission được cấp.
- User suspended không gọi protected API.
- Có feature test cho login, policy và permission.

### Đợt 2 — Resource và taxonomy

Deliverables:

- Resource model/migration/factory/seeder.
- Resource status/visibility/slug.
- Category tree, tag và technology.
- CRUD API và admin table/form.
- Slug collision test.
- Server pagination/filter/sort.

Exit criteria:

- Tạo, sửa, archive resource.
- Publish yêu cầu permission.
- Public chỉ thấy resource published.
- Admin table dùng `VDataTableServer`.

### Đợt 3 — Media và version

Deliverables:

- Media Library disk/collections/conversions.
- Cover/preview/package upload.
- Resource version manager.
- Checksum, validation và scan status.
- Queue conversion.
- Package private.

Exit criteria:

- Upload package không tạo public URL trực tiếp.
- Preview có thumbnail.
- File nguy hiểm và archive vượt giới hạn bị từ chối.
- Queue failure có trạng thái lỗi và retry.

### Đợt 4 — Publish workflow và public catalog

Deliverables:

- Publish checklist.
- Home/catalog/detail/category.
- SEO cơ bản.
- Empty/404/loading state.
- Related resource đơn giản theo category/technology.

Exit criteria:

- Admin publish end-to-end.
- Guest xem resource public.
- Resource draft/private không lộ trong public query.
- Canonical và metadata đúng.

### Đợt 5 — Free download

Deliverables:

- Download authorization.
- Signed/temporary URL.
- Download history.
- Rate limit và abuse log.
- Atomic counter.

Exit criteria:

- Free download thành công.
- URL hết hạn.
- User không có quyền bị từ chối.
- Có feature test cho download allow/deny/history.

### Đợt 6 — Blog và legal

Deliverables:

- Posts, revision, cover media.
- Public blog.
- Sitemap/robots.
- Terms, privacy, license, refund.

Exit criteria:

- Admin publish bài viết.
- Draft không xuất hiện public.
- Rich text được sanitize.
- SEO page có canonical/metadata.

### Đợt 7 — Commerce test mode

Deliverables:

- Chốt payment provider.
- Variant và price snapshot.
- Checkout một flow.
- Webhook inbox/signature/idempotency.
- Order/payment/entitlement.
- Invoice/refund test.

Exit criteria:

- Payment test tạo một order.
- Webhook lặp không tạo entitlement trùng.
- Redirect client không tự fulfill order.
- Refund cập nhật entitlement theo policy.

### Đợt 8 — Production hardening

Deliverables:

- MySQL/Redis/S3 production config.
- Queue worker và scheduler.
- Backup/restore drill.
- Error monitoring.
- Dependency/security audit.
- Accessibility, load và SEO audit.

Exit criteria:

- Restore backup thành công.
- Không có critical security issue.
- Queue/webhook/download failure có alert.
- Release có migration rollback plan.

## 12. Testing và quality gates

Backend test bắt buộc:

- Migration up/down.
- Factory/seed.
- Auth và email verification.
- Spatie role/permission.
- Google/Facebook OAuth callback, liên kết identity và trường hợp thiếu email.
- Tách `web`/`admin` guard, reset password admin và chặn cross-guard access.
- Policy cho resource/media/download/order.
- Resource CRUD và publish transition.
- Media validation và package authorization.
- Download history/rate limit.
- Webhook signature/idempotency.
- Entitlement/refund.

Frontend test bắt buộc cho feature mới:

- Form validation và server errors.
- Table pagination/sort/filter.
- Permission-based action visibility.
- Upload progress/error/retry.
- Download state.

CI tối thiểu:

- `composer validate`
- `php artisan test`
- `vendor/bin/pint --test`
- `npm run lint:check`
- `npm run build`
- Migration test trên database sạch

Các script cần bổ sung vào `package.json`:

```json
{
  "scripts": {
    "lint": "eslint . -c .eslintrc.cjs --ext .js,.vue",
    "lint:check": "eslint . -c .eslintrc.cjs --ext .js,.vue",
    "build": "vite build"
  }
}
```

Không thêm `type-check` vì frontend thống nhất JavaScript.

## 13. Definition of Done

### Backend task

- Class có block comment chuẩn ở đầu file.
- Mọi method trong class có comment chuẩn ngay phía trên.
- Comment mô tả đúng input/output, side effect, exception và transaction boundary.
- Migration có rollback.
- Model relation và index đầy đủ.
- Form Request validation.
- Policy/permission.
- Action/service có transaction khi cần.
- Feature test cho happy path và deny path.
- Không lộ private path, token hoặc secret.
- Queue/retry được mô tả nếu có xử lý nền.

### Media task

- Class xử lý media và mọi method upload/download có comment chuẩn.
- Collection và disk đã chốt.
- MIME/size/extension validation.
- Scan status và failure handling.
- Conversion chạy đúng queue.
- Private media không có URL public lâu hạn.
- Delete/replace không để orphan media.

### Admin task

- Component/composable service class có comment theo chuẩn khi có class hoặc method.
- JavaScript Composition API.
- Component boundary rõ ràng.
- Loading/empty/error state.
- Server-side pagination.
- Permission action visibility.
- API errors hiển thị được.
- Responsive và keyboard accessible.

### Public task

- Blade render.
- SEO metadata/canonical.
- Accessible HTML.
- Empty/404 state.
- Không lộ draft/private resource.
- Cache policy được ghi rõ.

### Commerce task

- Test mode.
- Webhook signature.
- Idempotency.
- Order snapshot.
- Entitlement transition.
- Refund behavior.
- Audit/payment log.
- Không fulfill bằng redirect client.

## 14. Các quyết định phải chốt

Trước Đợt 0:

- Tên thương hiệu và domain.
- Ngôn ngữ và thị trường.
- Resource đầu tiên.
- MySQL local/production.
- S3 provider.
- Email provider.
- npm hay pnpm; chỉ giữ một lockfile.

Trước Đợt 5:

- Free download có cần verify email không.
- Download limit theo user/IP.
- Preview policy.
- Free license terms.
- Retention của IP/user-agent.

Trước Đợt 7:

- Payment provider và Merchant of Record.
- Currency và tax.
- Pricing tiers.
- Update/support period.
- Refund window.
- Có cần license key/activation không.

## 15. Việc cần làm ngay

1. Sửa `composer.json` để thêm Socialite, Spatie Permission, Media Library và Activity Log theo version tương thích Laravel 12.
2. Publish config/migration của package và chạy migration sạch.
3. Tách route `/admin` khỏi catch-all hiện tại.
4. Thêm APP_KEY vào môi trường development/test.
5. Tắt MSW mặc định, chỉ bật bằng environment flag.
6. Tạo `RolePermissionSeeder` và các Policy đầu tiên.
7. Tạo Resource, ResourceVersion và các Media collection.
8. Hoàn thành free download trước khi bắt đầu payment.

## 16. Tài liệu tham khảo

- Laravel authentication: https://laravel.com/docs/12.x/authentication
- Laravel Sanctum: https://laravel.com/docs/12.x/sanctum
- Laravel Socialite: https://laravel.com/docs/socialite
- Spatie Permission: https://spatie.be/docs/laravel-permission
- Spatie Media Library v11: https://spatie.be/docs/laravel-medialibrary/v11
- Spatie Activity Log: https://spatie.be/docs/laravel-activitylog
- Vuetify 3: https://v3.vuetifyjs.com/en/
- Vuetify server-side data table: https://vuetifyjs.com/en/components/data-tables/server-side-tables/
