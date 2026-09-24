# MASTER PLAN — Nền tảng kinh doanh Template, UI Kit và Digital Resources

Phiên bản: 1.0
Ngày cập nhật: 2026-09-25
Trạng thái: Kế hoạch triển khai tổng thể
Repository: E:\Web\makert-temlate

---

## 1. Tầm nhìn và mục tiêu

Xây dựng một nền tảng bán và phân phối tài nguyên số cho developer/designer, gồm:

- Admin template.
- Vue/React/HTML template.
- UI kit.
- CSS component và theme.
- Landing page.
- Dashboard template.
- Icon, illustration và design resource.
- Starter kit, boilerplate, plugin và snippet.
- Documentation, ebook hoặc course.

Hệ thống có ba trải nghiệm chính:

1. Public website bằng Laravel Blade để tối ưu SEO, blog và catalog.
2. Admin dashboard bằng Vue 3 + Vuetify để quản trị.
3. Client account để đăng ký, tải resource, mua, nhận update và hỗ trợ.

Mục tiêu kinh doanh:

- Cho phép phát hành resource miễn phí để thu hút traffic.
- Cho phép bán resource trả phí theo license tier.
- Cho phép khách hàng tải file an toàn và nhận update.
- Xây dựng blog/content marketing để tạo traffic SEO.
- Theo dõi view, download, order, conversion và retention.
- Có thể mở rộng thành marketplace nhiều seller sau khi mô hình được xác nhận.

---

## 2. Phạm vi và ưu tiên

### 2.1 MVP bắt buộc

MVP phải có:

- Public catalog Blade.
- Resource detail page.
- Client register/login/logout.
- Email verification và password reset.
- Admin dashboard Vue 3.
- Resource CRUD.
- Resource type/status/visibility.
- Slug polymorphic.
- Category polymorphic.
- Tag polymorphic.
- Technology metadata.
- Resource version.
- Media/file upload.
- Preview gallery.
- Private package storage.
- Signed download.
- Download history.
- Blog cơ bản.
- SEO metadata.
- Admin roles/permissions.
- View/download counters.
- Terms, privacy, license và refund pages.
- Audit log cho thao tác quan trọng.
- Rate limit download và authentication.

### 2.2 Commerce MVP

Trước khi nhận payment thật phải có:

- Resource variant/license tier.
- Cart hoặc direct checkout.
- Order và order item.
- Payment provider test mode.
- Webhook inbox.
- Webhook signature validation.
- Idempotency.
- Entitlement.
- Invoice.
- Refund.
- License key nếu cần.
- Automated payment tests.
- Quy định update/support/refund rõ ràng.

### 2.3 Growth

Thực hiện sau khi MVP có người dùng thật:

- Advanced search.
- Wishlist.
- Collection.
- Follow resource.
- Rating/review.
- Q&A.
- Support ticket.
- Coupon.
- Bundle.
- Notification center.
- Newsletter.
- Update notification.
- Conversion analytics.
- Affiliate/referral.

### 2.4 Optional

Chỉ thực hiện nếu mô hình kinh doanh yêu cầu:

- Subscription.
- Multi-currency.
- Gift purchase.
- Multi-vendor marketplace.
- Seller onboarding/KYC.
- Revenue share và seller payout.
- Public API.
- Mobile application.

---

## 3. Kiến trúc tổng thể

Browser:

- Public pages: Laravel Blade.
- Account pages: Laravel Blade.
- Admin pages: Vue 3 + Vuetify.

Laravel:

- Web routes.
- Admin API.
- Authentication.
- Policies và permissions.
- Resource domain.
- Blog domain.
- Media/download domain.
- Commerce domain.
- Notification domain.
- Analytics domain.
- Queue jobs.

Infrastructure:

- MySQL hoặc PostgreSQL.
- Redis cho cache, queue và rate limit.
- S3-compatible storage.
- CDN cho ảnh public.
- Private storage cho package.
- Mail provider.
- Payment provider.
- Error monitoring và uptime monitoring.

### 3.1 URL boundary

Public:

- /
- /resources
- /resources/{slug}
- /categories/{slug}
- /tags/{slug}
- /search
- /blog
- /blog/{slug}
- /account

Admin:

- /admin
- /admin/{path}

API:

- /api/account/*
- /api/admin/*
- /webhooks/{provider}
- /download/{token}

### 3.2 Technology stack

| Khu vực | Công nghệ |
|---|---|
| Backend | Laravel 12 |
| Public | Laravel Blade |
| Admin | Vue 3 + Vuetify 3 |
| Admin state | Pinia |
| Admin routing | Vue Router |
| Build | Vite |
| Database | MySQL/PostgreSQL |
| Cache/queue | Redis |
| File | S3-compatible private/public disk |
| Search | Database trước, Scout + Meilisearch sau |
| Payment | Chọn một provider phù hợp thị trường |
| Mail | SMTP/API provider |

### 3.3 Nguyên tắc boundary

- Public không gọi endpoint admin.
- Admin không truy cập database trực tiếp.
- CASL ở frontend chỉ phục vụ UX, không thay thế Policy backend.
- Search index có thể rebuild từ database.
- Payment provider không phải source of truth duy nhất.
- Webhook là nguồn xác nhận order/entitlement.
- Package không lưu binary trong database.
- Private file không nằm trong public web root.

---

## 4. Định hướng từ project hiện tại

Project hiện có Vuexy Vue/Laravel template với:

- Laravel 12.
- Vue 3.5.
- Vuetify 3.
- Vite.
- Pinia.
- Auto route.
- Vuexy layout.
- MSW fake API.
- CASL.
- i18n.

### 4.1 Nên tái sử dụng

- resources/js/@core.
- resources/js/@layouts.
- resources/js/plugins/vuetify.
- resources/js/plugins/casl.
- resources/js/pages.
- resources/js/views.
- resources/styles.

### 4.2 Cần thay đổi

- Tách public Blade và admin Blade.
- Tách route public/admin/API.
- Giới hạn MSW ở local/demo.
- Thay auth demo bằng Laravel auth thật.
- Thêm API Controller, Request, Resource, Policy.
- Ẩn các demo page không dùng production.
- Chuẩn hóa API client.
- Thêm test, lint, type-check và build pipeline.
- Cập nhật README và environment documentation.

### 4.3 Cấu trúc backend đề xuất

- app/Actions
- app/Enums
- app/Http/Controllers/Admin
- app/Http/Controllers/Public
- app/Http/Requests
- app/Http/Resources
- app/Jobs
- app/Models
- app/Notifications
- app/Policies
- app/Services
- app/Support

### 4.4 Cấu trúc view/frontend đề xuất

- resources/views/layouts/public.blade.php
- resources/views/layouts/account.blade.php
- resources/views/layouts/admin.blade.php
- resources/views/public
- resources/views/account
- resources/views/components
- resources/js/pages/admin
- resources/js/views/admin
- resources/js/stores
- resources/js/composables
- resources/js/services

---

## 5. Domain model

### 5.1 Core entities

User:

- roles
- downloads
- orders
- reviews
- favorites
- support tickets
- notifications

Resource:

- author
- versions
- slugs
- categories
- tags
- technologies
- media
- variants
- downloads
- reviews
- favorites
- questions
- statistics

Post:

- author
- slug
- categories
- tags
- cover media
- revisions
- related resources

Order:

- user
- items
- payments
- invoice
- refunds
- entitlements

Entitlement:

- user
- resource
- variant
- license key
- download permission

### 5.2 Resource types

- template
- ui_kit
- css
- component
- theme
- snippet
- plugin
- icon_pack
- illustration
- ebook
- course
- other

### 5.3 Resource states

Resource:

- draft
- pending_review
- rejected
- published
- suspended
- archived

Order:

- pending
- paid
- fulfilled
- failed
- cancelled
- expired
- partially_refunded
- refunded

Entitlement:

- pending
- active
- expired
- suspended
- revoked

Support ticket:

- open
- pending_customer
- pending_staff
- resolved
- closed

### 5.4 Resource lifecycle

draft → pending_review → published → archived

Có thể chuyển sang rejected hoặc suspended khi review/moderation fail.

---

## 6. Database schema

### 6.1 Quy ước

- Primary key dùng BIGINT UNSIGNED.
- Nội dung chính dùng soft delete.
- Lưu thời gian theo UTC.
- Dùng PHP Enum, lưu string trong database.
- Dùng morph map.
- Index các cột filter/sort.
- Dùng foreign key cho quan hệ thông thường.
- Không lưu file binary trong database.
- Snapshot thông tin quan trọng tại thời điểm mua.

### 6.2 users

- id
- name
- username nullable unique
- email unique
- email_verified_at nullable
- password
- avatar_media_id nullable
- status
- last_login_at nullable
- remember_token nullable
- created_at
- updated_at
- deleted_at nullable

Index:

- unique(email)
- unique(username)
- index(status, created_at)

### 6.3 roles và permissions

roles:

- id
- name
- guard_name
- created_at
- updated_at

permissions:

- id
- name
- guard_name
- created_at
- updated_at

Pivots:

- model_has_roles
- role_has_permissions

Permission ban đầu:

- users.view
- users.manage
- resources.view
- resources.create
- resources.update
- resources.delete
- resources.publish
- media.manage
- taxonomy.manage
- posts.manage
- posts.publish
- orders.view
- orders.refund
- licenses.manage
- analytics.view
- settings.manage

### 6.4 resources

- id
- author_id
- created_by
- updated_by nullable
- type
- title
- code nullable unique
- short_description nullable
- description nullable
- status
- visibility
- is_featured
- demo_url nullable
- documentation_url nullable
- license_name nullable
- view_count
- download_count
- published_at nullable
- created_at
- updated_at
- deleted_at nullable

Index:

- unique(code)
- index(type, status, published_at)
- index(author_id, status)
- index(is_featured, status)

### 6.5 slugs

- id
- sluggable_type
- sluggable_id
- slug
- locale
- is_primary
- created_at
- updated_at

Index:

- unique(slug, locale)
- index(sluggable_type, sluggable_id)

Khi slug đổi, giữ slug cũ nếu cần redirect 301.

### 6.6 categories

- id
- parent_id nullable
- name
- description nullable
- status
- sort_order
- created_at
- updated_at
- deleted_at nullable

Pivot categorizables:

- category_id
- categorizable_type
- categorizable_id
- sort_order
- created_at

Index:

- index(parent_id, sort_order)
- unique(category_id, categorizable_type, categorizable_id)
- index(categorizable_type, categorizable_id)

### 6.7 tags

- id
- name
- description nullable
- created_at
- updated_at
- deleted_at nullable

Pivot taggables:

- tag_id
- taggable_type
- taggable_id
- created_at

Index:

- unique(tag_id, taggable_type, taggable_id)
- index(taggable_type, taggable_id)

### 6.8 technologies

technologies:

- id
- name
- slug
- type
- created_at
- updated_at

resource_technology:

- resource_id
- technology_id
- version_constraint nullable
- created_at

Technology type:

- framework
- language
- css
- build_tool
- database
- runtime
- package_manager

### 6.9 resource_versions

- id
- resource_id
- version
- changelog nullable
- requirements JSON nullable
- status
- is_default
- released_at nullable
- created_by
- created_at
- updated_at
- deleted_at nullable

Index:

- unique(resource_id, version)
- index(resource_id, status, released_at)

Mỗi resource chỉ có một version default.

### 6.10 media

- id
- uploaded_by nullable
- disk
- path
- original_name
- stored_name
- mime_type
- extension nullable
- size
- checksum nullable
- metadata JSON nullable
- created_at
- updated_at
- deleted_at nullable

Pivot mediables:

- media_id
- mediable_type
- mediable_id
- collection
- sort_order
- created_at

Collection:

- package
- preview
- thumbnail
- cover
- documentation
- video
- license
- attachment

### 6.11 downloads

- id
- user_id
- resource_id
- resource_version_id
- media_id
- entitlement_id nullable
- ip_hash nullable
- user_agent nullable
- referer nullable
- downloaded_at
- created_at

Index:

- index(user_id, downloaded_at)
- index(resource_id, downloaded_at)
- index(resource_version_id, downloaded_at)

### 6.12 posts

- id
- author_id
- title
- excerpt nullable
- body
- status
- featured_media_id nullable
- view_count
- published_at nullable
- created_at
- updated_at
- deleted_at nullable

post_revisions:

- id
- post_id
- user_id
- title
- excerpt nullable
- body
- created_at

post_resource:

- post_id
- resource_id
- sort_order
- created_at

### 6.13 favorites và collections

favorites:

- id
- user_id
- resource_id
- created_at

Index:

- unique(user_id, resource_id)

collections:

- id
- owner_id nullable
- name
- description nullable
- visibility
- is_featured
- created_at
- updated_at

collection_resource:

- collection_id
- resource_id
- sort_order
- created_at

resource_follows:

- user_id
- resource_id
- created_at

### 6.14 reviews và Q&A

reviews:

- id
- user_id
- resource_id
- order_item_id nullable
- rating
- title nullable
- body
- status
- is_verified
- admin_reply nullable
- replied_at nullable
- created_at
- updated_at
- deleted_at nullable

resource_questions:

- id
- user_id
- resource_id
- question
- status
- created_at
- updated_at

resource_answers:

- id
- question_id
- user_id
- answer
- is_official
- created_at
- updated_at

### 6.15 resource_variants

- id
- resource_id
- name
- code
- billing_type
- price
- currency
- project_limit nullable
- activation_limit nullable
- update_months nullable
- support_months nullable
- is_active
- created_at
- updated_at

Ví dụ variant:

- Free
- Personal
- Commercial
- Extended
- Agency

### 6.16 carts và discounts

carts:

- id
- user_id nullable
- session_id nullable
- currency
- expires_at nullable
- created_at
- updated_at

cart_items:

- id
- cart_id
- resource_variant_id
- quantity
- created_at
- updated_at

discounts:

- id
- code
- type
- value
- starts_at nullable
- ends_at nullable
- usage_limit nullable
- usage_limit_per_user nullable
- minimum_amount nullable
- status
- created_at
- updated_at

discount_redemptions:

- id
- discount_id
- user_id
- order_id
- amount
- created_at

### 6.17 orders và payments

orders:

- id
- user_id
- order_number unique
- status
- currency
- subtotal
- discount
- tax
- total
- payment_provider nullable
- payment_reference nullable
- paid_at nullable
- cancelled_at nullable
- created_at
- updated_at

order_items:

- id
- order_id
- resource_id
- resource_version_id nullable
- resource_variant_id
- title_snapshot
- license_terms_snapshot JSON nullable
- price
- quantity
- subtotal

payments:

- id
- order_id
- provider
- provider_payment_id
- status
- amount
- currency
- payload JSON nullable
- paid_at nullable
- created_at
- updated_at

### 6.18 entitlements và license

entitlements:

- id
- user_id
- resource_id
- resource_variant_id
- order_id
- status
- starts_at
- expires_at nullable
- update_until nullable
- support_until nullable
- created_at
- updated_at

license_keys:

- id
- entitlement_id
- key_hash
- status
- activation_limit nullable
- activation_usage
- expires_at nullable
- created_at
- updated_at

license_activations:

- id
- license_key_id
- instance_id
- instance_name nullable
- domain nullable
- activated_at
- deactivated_at nullable

### 6.19 billing, invoice và refund

billing_profiles:

- id
- user_id
- company_name nullable
- tax_id nullable
- country_code
- address_line_1
- address_line_2 nullable
- city
- state nullable
- postal_code nullable
- created_at
- updated_at

invoices:

- id
- order_id
- invoice_number unique
- status
- currency
- subtotal
- discount
- tax
- total
- pdf_media_id nullable
- issued_at
- paid_at nullable
- created_at
- updated_at

refunds:

- id
- order_id
- payment_id nullable
- amount
- reason nullable
- status
- provider_reference nullable
- refunded_at nullable
- created_at
- updated_at

### 6.20 webhook_events

- id
- provider
- provider_event_id
- event_type
- payload JSON
- status
- attempts
- processed_at nullable
- last_error nullable
- created_at
- updated_at

Unique:

- unique(provider, provider_event_id)

### 6.21 support

support_tickets:

- id
- user_id
- resource_id nullable
- order_id nullable
- assigned_to nullable
- subject
- status
- priority
- last_replied_at nullable
- closed_at nullable
- created_at
- updated_at

support_messages:

- id
- ticket_id
- user_id
- body
- is_internal
- created_at

### 6.22 notification và newsletter

notification_preferences:

- id
- user_id
- channel
- event
- enabled
- created_at
- updated_at

newsletter_subscribers:

- id
- email unique
- status
- subscribed_at
- confirmed_at nullable
- unsubscribed_at nullable
- created_at
- updated_at

### 6.23 analytics và audit

resource_daily_stats:

- id
- resource_id
- date
- views
- downloads
- unique_users
- created_at
- updated_at

post_daily_stats:

- id
- post_id
- date
- views
- unique_visitors
- created_at
- updated_at

audit_logs:

- id
- user_id nullable
- event
- auditable_type
- auditable_id
- old_values JSON nullable
- new_values JSON nullable
- ip_address nullable
- user_agent nullable
- created_at

### 6.24 Migration order

1. users
2. roles, permissions và pivots
3. media
4. resources
5. slugs
6. categories và categorizables
7. tags và taggables
8. technologies và resource_technology
9. resource_versions
10. mediables
11. posts và post_revisions
12. post_resource
13. favorites, collections và follows
14. reviews, questions và answers
15. resource_variants
16. downloads
17. carts và cart_items
18. discounts và redemptions
19. orders, order_items và payments
20. entitlements, license_keys và activations
21. billing_profiles, invoices và refunds
22. webhook_events
23. support_tickets và support_messages
24. notifications, preferences và newsletter
25. daily stats
26. audit_logs

---

## 7. Public website bằng Blade

### 7.1 Routes

- GET /
- GET /resources
- GET /resources/{slug}
- GET /categories
- GET /categories/{slug}
- GET /tags/{slug}
- GET /search
- GET /collections/{slug}
- GET /blog
- GET /blog/{slug}
- GET /blog/categories/{slug}
- GET /blog/tags/{slug}
- GET /pricing
- GET /about
- GET /contact
- GET /faq
- GET /terms
- GET /privacy
- GET /license-terms
- GET /refund-policy

### 7.2 Resource listing

Hiển thị:

- Title.
- Thumbnail.
- Resource type.
- Framework/technology.
- Free/paid badge.
- Rating.
- Download count.
- Last updated.
- Featured badge.
- Filter.
- Sort.
- Pagination.

### 7.3 Resource detail

Hiển thị:

- Title.
- Slug.
- Description.
- Preview gallery.
- Live demo.
- Version mới nhất.
- Changelog.
- Requirements.
- Supported technologies.
- Browser support.
- Responsive/RTL/dark mode.
- License tiers.
- Price.
- Download/purchase CTA.
- Favorites.
- Reviews.
- Q&A.
- Related resources.
- Related blog posts.
- Support policy.

### 7.4 Account pages

- /account
- /account/profile
- /account/security
- /account/downloads
- /account/orders
- /account/orders/{order}
- /account/licenses
- /account/favorites
- /account/collections
- /account/support
- /account/notifications
- /account/billing

### 7.5 SEO

- Meta title/description.
- Canonical.
- Open Graph/Twitter card.
- JSON-LD.
- Breadcrumb.
- Sitemap.
- Robots.
- 301 slug redirects.
- 404 search suggestions.
- Alt text.
- Lazy loading.
- Core Web Vitals.

---

## 8. Admin dashboard Vue 3

### 8.1 Navigation

Dashboard:

- Overview.
- Revenue.
- Download analytics.

Catalog:

- Resources.
- Resource versions.
- Media library.
- Categories.
- Tags.
- Technologies.
- Collections.

Content:

- Posts.
- Post revisions.
- Pages.
- SEO redirects.

Commerce:

- Orders.
- Payments.
- Invoices.
- Refunds.
- Discounts.
- Variants.
- Entitlements.
- License keys.

Customers:

- Users.
- Reviews.
- Questions.
- Support tickets.
- Newsletter.

System:

- Roles/permissions.
- Webhooks.
- Audit logs.
- Settings.
- Failed jobs.

### 8.2 Resource editor

Sections:

1. Basic information.
2. Description/editor.
3. Type and taxonomy.
4. Technology compatibility.
5. SEO.
6. Thumbnail/gallery.
7. Demo/documentation.
8. Version/package.
9. Variant/license/pricing.
10. Publish checklist.

### 8.3 Frontend rules

- Composition API và script setup.
- Page là composition surface.
- Form/list/filter/detail/dialog tách component.
- Props down, events up.
- Computed cho derived state.
- Composable cho fetch/upload/filter/pagination.
- Loading/empty/error state.
- Server-side pagination.
- Permission-based action visibility.
- Không để fake API chạy production.

---

## 9. API và backend module

### 9.1 Authentication

- POST /register
- POST /login
- POST /logout
- POST /forgot-password
- POST /reset-password
- GET /email/verify/{id}/{hash}

### 9.2 Admin resource

- GET /api/admin/resources
- POST /api/admin/resources
- GET /api/admin/resources/{resource}
- PUT /api/admin/resources/{resource}
- DELETE /api/admin/resources/{resource}
- POST /api/admin/resources/{resource}/restore
- POST /api/admin/resources/{resource}/submit-review
- POST /api/admin/resources/{resource}/publish
- POST /api/admin/resources/{resource}/archive

### 9.3 Version/media

- GET /api/admin/resources/{resource}/versions
- POST /api/admin/resources/{resource}/versions
- PUT /api/admin/resource-versions/{version}
- POST /api/admin/resource-versions/{version}/publish
- POST /api/admin/resource-versions/{version}/make-default
- POST /api/admin/media
- GET /api/admin/media/{media}
- DELETE /api/admin/media/{media}
- POST /api/admin/media/{media}/attach
- DELETE /api/admin/media/{media}/detach

### 9.4 Taxonomy/content

- CRUD /api/admin/categories
- CRUD /api/admin/tags
- CRUD /api/admin/technologies
- CRUD /api/admin/posts
- POST /api/admin/posts/{post}/publish
- GET /api/admin/posts/{post}/revisions

### 9.5 Commerce

- GET /api/admin/orders
- GET /api/admin/orders/{order}
- POST /api/admin/orders/{order}/refund
- GET /api/admin/payments
- GET /api/admin/invoices
- GET /api/admin/entitlements
- POST /api/admin/entitlements/{entitlement}/revoke
- GET /api/admin/license-keys
- POST /api/admin/license-keys/{license}/disable

### 9.6 Client

- GET /api/account/profile
- PUT /api/account/profile
- GET /api/account/orders
- GET /api/account/downloads
- GET /api/account/licenses
- POST /api/resources/{resource}/favorite
- DELETE /api/resources/{resource}/favorite
- POST /api/resources/{resource}/download
- POST /api/resources/{resource}/reviews
- POST /api/resources/{resource}/questions

### 9.7 Webhook

- POST /webhooks/{provider}

Webhook process:

1. Validate signature.
2. Lưu raw payload.
3. Trả HTTP 200 nhanh.
4. Đẩy queue.
5. Kiểm tra idempotency.
6. Cập nhật order/payment/entitlement.
7. Ghi audit.
8. Replay event lỗi được.

### 9.8 Service/action classes

- CreateResourceAction.
- UpdateResourceAction.
- PublishResourceAction.
- CreateResourceVersionAction.
- PublishResourceVersionAction.
- GenerateSlugAction.
- UploadMediaAction.
- AttachMediaAction.
- AuthorizeDownloadAction.
- CreateDownloadAction.
- GenerateSignedDownloadUrlAction.
- PublishPostAction.
- CreateOrderAction.
- CompletePaymentAction.
- RefundOrderAction.
- GrantEntitlementAction.
- RevokeEntitlementAction.
- GenerateLicenseKeyAction.
- ActivateLicenseKeyAction.
- ProcessWebhookAction.
- AggregateStatsJob.
- ScanArchiveJob.
- GeneratePreviewJob.

---

## 10. File, preview và download

### 10.1 Storage

Public:

- Thumbnail.
- Cover.
- Optimized preview.
- Public branding.

Private:

- ZIP package.
- Source code.
- Paid documentation.
- License attachment.
- Invoice private copy.

### 10.2 Upload pipeline

Admin upload → validate → temporary storage → checksum → malware/archive scan → metadata extraction → image variants → attach resource/version → audit log.

### 10.3 Archive security

- Chặn path traversal.
- Chặn symlink nguy hiểm.
- Giới hạn số file.
- Giới hạn uncompressed size.
- Không thực thi file upload.
- Validate extension bên trong archive.
- Không overwrite path khác.

### 10.4 Download pipeline

Client click → authenticate → load resource/version/media → check published → check visibility/entitlement → rate limit → create download record → increment counter → signed URL → stream/private redirect.

### 10.5 Abuse protection

- Rate limit user/IP.
- Phát hiện request bất thường.
- Signed URL ngắn hạn.
- Revoke entitlement/license.
- Audit download thất bại.
- Không log token đầy đủ.
- Admin xem activity đáng ngờ.

---

## 11. Blog, SEO và CMS

### 11.1 Blog workflow

draft → review → scheduled → published → archived

### 11.2 Blog features

- Rich text editor.
- Markdown tùy chọn.
- Cover image.
- Inline image.
- Category/tag.
- Related resources.
- Author.
- Revision history.
- Scheduled publishing.
- Preview.
- SEO fields.
- Reading time.
- Table of contents.
- Social metadata.

### 11.3 CMS pages

- About.
- Contact.
- FAQ.
- Terms.
- Privacy.
- License terms.
- Refund policy.
- Copyright/DMCA.
- Help center.

---

## 12. Commerce, license và payment

### 12.1 Product structure

Resource là sản phẩm nội dung. Resource variant là phương án mua/license.

Ví dụ:

- Resource: Vue Admin Dashboard.
- Free.
- Personal License.
- Commercial License.
- Agency License.

### 12.2 Checkout flow

Resource detail → select variant → cart/checkout → login/register → billing profile → coupon → payment provider → webhook confirms → order paid → entitlement granted → license generated → invoice/receipt → download enabled.

Không cấp entitlement chỉ dựa vào redirect client.

### 12.3 License policy

Phải quy định:

- Số project/domain.
- Số activation.
- Thời hạn update.
- Thời hạn support.
- Có dùng cho client hay không.
- Có redistribute hay không.
- Có dùng trong SaaS hay không.
- Refund policy.
- Revoke policy.

### 12.4 Provider checklist

Trước khi chốt provider:

- Quốc gia hỗ trợ.
- Merchant of Record hay không.
- VAT/sales tax.
- Hosted customer portal.
- License key/webhook.
- Transaction fee.
- Payout time.
- Refund/dispute API.
- Test mode.
- Export dữ liệu.

### 12.5 Refund/dispute

- Full refund.
- Partial refund.
- Refund reason.
- Entitlement policy sau refund.
- License revoke/suspend.
- Download lock theo policy.
- Chargeback review.
- Audit thao tác.

---

## 13. Security, privacy và compliance

### 13.1 Authentication

- Password hashing.
- Email verification.
- Password reset expiry.
- Optional 2FA cho admin.
- Session invalidation sau đổi password.
- Login rate limit.
- Admin timeout.

### 13.2 Authorization

- Policy cho resource, post, media, order, refund, entitlement và user.
- Backend permission bắt buộc.
- Frontend permission chỉ là UX.
- Không tin permission từ client.

### 13.3 Web security

- CSRF.
- Secure cookie.
- SameSite.
- CSP.
- HSTS.
- X-Content-Type-Options.
- Referrer-Policy.
- Frame-ancestors.
- Escape HTML.
- Sanitize rich text.
- Không dùng v-html với input chưa sanitize.

### 13.4 Privacy

- Hash/anonymize IP nếu chỉ cần thống kê.
- Không log password/token/license plain text.
- Data retention.
- Export dữ liệu.
- Delete account.
- Cookie consent.
- Email unsubscribe.

### 13.5 Copyright

- Terms cho người upload.
- Copyright declaration.
- Takedown form.
- Evidence.
- Suspend resource/seller.
- Audit case.

---

## 14. Analytics và marketing

### 14.1 Product metrics

- Resource views.
- Unique visitors.
- Downloads.
- Download/view ratio.
- Favorites.
- Reviews.
- Checkout starts.
- Paid orders.
- Refunds.
- Revenue.
- Version adoption.

### 14.2 Business dashboards

- Revenue theo ngày/tháng.
- Top resource.
- Top category.
- Top technology.
- New/returning users.
- Conversion funnel.
- No-result search.
- Coupon performance.
- Affiliate performance.
- Support response time.

### 14.3 Marketing

- Featured resource.
- New release.
- Bundle.
- Coupon.
- Newsletter.
- Lead magnet.
- Update notification.
- Affiliate.
- Landing pages.
- UTM attribution.

### 14.4 Notification events

- email_verified
- password_reset
- order_paid
- invoice_issued
- download_ready
- resource_updated
- license_expiring
- support_replied
- refund_completed
- newsletter_confirmed

---

## 15. Roadmap theo từng đợt

Mỗi đợt phải kết thúc bằng migration/test/demo checkpoint.

### Đợt 0 — Foundation

Tasks:

- [ ] Setup .env và APP_KEY.
- [ ] Tách public/admin Blade shell.
- [ ] Tách route public/admin/API.
- [ ] Xác định admin Vue tại /admin.
- [ ] Giới hạn fake API local.
- [ ] Chọn database/cache/storage.
- [ ] Coding conventions.
- [ ] README.
- [ ] CI baseline.

Acceptance:

- [ ] / render public placeholder.
- [ ] /admin render Vue app.
- [ ] /api/health hoạt động.
- [ ] Laravel test chạy.
- [ ] Frontend build chạy.

### Đợt 1 — Auth và roles

Tasks:

- [ ] Register.
- [ ] Login/logout.
- [ ] Email verification.
- [ ] Password reset.
- [ ] Profile.
- [ ] User status.
- [ ] Roles/permissions.
- [ ] Admin user list.
- [ ] Middleware.
- [ ] Policies.

Acceptance:

- [ ] Guest không vào admin.
- [ ] Client register/login.
- [ ] Admin quản lý user.
- [ ] Permission tests pass.

### Đợt 2 — Resource và taxonomy

Tasks:

- [ ] Resource model/migration.
- [ ] Type/status/visibility.
- [ ] Slug polymorphic.
- [ ] Category tree.
- [ ] Categorizable.
- [ ] Tag/taggable.
- [ ] Technology.
- [ ] Resource CRUD API.
- [ ] Admin list/form.
- [ ] Slug collision.
- [ ] Seed sample.

Acceptance:

- [ ] Tạo/sửa/xóa resource.
- [ ] Nhiều category/tag.
- [ ] Resolve bằng slug.
- [ ] Duplicate slug xử lý.
- [ ] Policy tests pass.

### Đợt 3 — Media và version

Tasks:

- [ ] Media migration.
- [ ] Public/private disk.
- [ ] Upload endpoint.
- [ ] Validation.
- [ ] Checksum.
- [ ] Version.
- [ ] ZIP attach.
- [ ] Preview gallery.
- [ ] Thumbnail job.
- [ ] Archive safety.
- [ ] Media library.

Acceptance:

- [ ] Upload package.
- [ ] Package không public trực tiếp.
- [ ] Nhiều version.
- [ ] Preview/thumbnail.
- [ ] File nguy hiểm bị chặn.

### Đợt 4 — Admin workflow

Tasks:

- [ ] Dashboard metrics.
- [ ] Search/filter/sort.
- [ ] Draft/review/publish/archive.
- [ ] Bulk action.
- [ ] Restore.
- [ ] Resource editor.
- [ ] Technology metadata.
- [ ] Demo/documentation.
- [ ] SEO fields.
- [ ] Publish checklist.

Acceptance:

- [ ] Editor publish resource end-to-end.
- [ ] Không thao tác DB thủ công.
- [ ] Publish cần permission.
- [ ] UI đủ loading/empty/error.

### Đợt 5 — Public catalog và free download

Tasks:

- [ ] Home.
- [ ] Catalog.
- [ ] Detail.
- [ ] Category/tag.
- [ ] Search cơ bản.
- [ ] Related resource.
- [ ] Client download.
- [ ] Download history.
- [ ] Signed URL.
- [ ] Rate limit.
- [ ] View/download counter.

Acceptance:

- [ ] Guest xem public.
- [ ] Guest không tải members-only.
- [ ] Client tải được.
- [ ] Record chính xác.
- [ ] Signed URL hết hạn.

### Đợt 6 — Blog và CMS

Tasks:

- [ ] Post CRUD.
- [ ] Editor.
- [ ] Draft/review/publish/schedule.
- [ ] Revision.
- [ ] Cover.
- [ ] Shared slug/category/tag.
- [ ] Related resource.
- [ ] Public blog.
- [ ] Sitemap.
- [ ] SEO.
- [ ] Legal pages.

Acceptance:

- [ ] Admin publish blog.
- [ ] Resolve bằng slug.
- [ ] Metadata đúng.
- [ ] Restore revision.

### Đợt 7 — Commerce

Tasks:

- [ ] Resource variants.
- [ ] Pricing.
- [ ] Cart/checkout.
- [ ] Coupon.
- [ ] Order/payment.
- [ ] Test mode.
- [ ] Webhook inbox.
- [ ] Idempotency.
- [ ] Entitlement.
- [ ] Invoice.
- [ ] Refund.

Acceptance:

- [ ] Test payment tạo order.
- [ ] Webhook cấp entitlement.
- [ ] Duplicate webhook không duplicate order.
- [ ] Refund update đúng.
- [ ] Invoice snapshot đúng.

### Đợt 8 — License và account

Tasks:

- [ ] License terms.
- [ ] License key.
- [ ] Activation/deactivation.
- [ ] Project/domain limit.
- [ ] Update entitlement.
- [ ] Customer order.
- [ ] Customer license.
- [ ] Paid download.
- [ ] Expiry reminder.

Acceptance:

- [ ] Paid client chỉ tải đúng entitlement.
- [ ] License limit hoạt động.
- [ ] Revoke chặn access.
- [ ] Account hiển thị order/license/download.

### Đợt 9 — Trust và support

Tasks:

- [ ] Rating/review.
- [ ] Verified purchase/download.
- [ ] Review moderation.
- [ ] Q&A.
- [ ] Support ticket.
- [ ] Knowledge base.
- [ ] Wishlist.
- [ ] Collection.
- [ ] Follow.
- [ ] Update notification.

Acceptance:

- [ ] Chỉ user hợp lệ được review.
- [ ] Admin moderation.
- [ ] Support có status/priority.
- [ ] Update notification gửi đúng.

### Đợt 10 — Search và growth

Tasks:

- [ ] Advanced filter.
- [ ] Technology filter.
- [ ] Search index nếu cần.
- [ ] Trending.
- [ ] Featured collection.
- [ ] Bundle.
- [ ] Newsletter.
- [ ] Affiliate baseline.
- [ ] Conversion funnel.
- [ ] Business dashboard.

Acceptance:

- [ ] Search relevance tốt.
- [ ] Admin hiểu performance.
- [ ] Coupon/bundle attribution đúng.
- [ ] Newsletter opt-in/out đúng.

### Đợt 11 — Production hardening

Tasks:

- [ ] Security audit.
- [ ] Dependency audit.
- [ ] Rate limit audit.
- [ ] Backup/restore test.
- [ ] Queue monitoring.
- [ ] Webhook replay.
- [ ] Malware scan.
- [ ] Storage lifecycle.
- [ ] CDN/image optimization.
- [ ] Error monitoring.
- [ ] Load test.
- [ ] Accessibility audit.
- [ ] SEO audit.

Acceptance:

- [ ] Restore backup thành công.
- [ ] Không có critical security issue.
- [ ] Queue failure có alert.
- [ ] Download ổn định dưới tải dự kiến.

### Đợt 12 — Multi-vendor, chỉ khi được duyệt

Tasks:

- [ ] Seller onboarding.
- [ ] KYC.
- [ ] Seller storefront.
- [ ] Submission/review.
- [ ] Revenue share.
- [ ] Seller balance.
- [ ] Payout.
- [ ] Seller analytics.
- [ ] Copyright/dispute.

Không bắt đầu nếu chưa chốt seller ownership, payout và legal responsibility.

---

## 16. Testing và quality gates

### 16.1 Backend

- [ ] Model relationship.
- [ ] Migration up/down.
- [ ] Factory/seed.
- [ ] Auth.
- [ ] Policy.
- [ ] Resource CRUD.
- [ ] Slug collision.
- [ ] Polymorphic taxonomy.
- [ ] Upload validation.
- [ ] Download authorization.
- [ ] Order/payment.
- [ ] Webhook idempotency.
- [ ] Refund/entitlement.
- [ ] Blog publish.
- [ ] Account delete/export.

### 16.2 Frontend

- [ ] Form validation.
- [ ] Resource table.
- [ ] Resource editor.
- [ ] Upload progress/error.
- [ ] Version manager.
- [ ] Permission navigation.
- [ ] Loading/empty/error.
- [ ] Download state.
- [ ] Checkout state.

### 16.3 End-to-end

Flow 1:

Register → verify email → login → browse → download free → history.

Flow 2:

Admin login → create resource → upload version → publish → public page.

Flow 3:

Select variant → checkout → payment test → webhook → entitlement → paid download → refund → access policy.

### 16.4 CI

Pull request phải chạy:

- composer validate.
- composer test.
- composer lint.
- pnpm lint.
- pnpm type-check.
- pnpm build.
- Migration test.
- Dependency/security audit.

Không merge khi test, build hoặc rollback migration fail.

---

## 17. Deployment và vận hành

### 17.1 Environment production

- APP_ENV=production.
- APP_DEBUG=false.
- APP_KEY.
- APP_URL.
- Database credentials.
- Redis credentials.
- Private storage credentials.
- Mail credentials.
- Payment secret.
- Webhook secret.

Secrets không commit.

### 17.2 Services

- PHP application.
- Queue worker.
- Scheduler.
- Database.
- Redis.
- Object storage.
- CDN.
- Mail provider.
- Payment provider.
- Error monitoring.
- Uptime monitoring.

### 17.3 Backup

- Daily database backup.
- Point-in-time recovery nếu cần.
- Object storage versioning.
- Backup encryption.
- Retention policy.
- Restore drill.
- Backup khác server/region.

### 17.4 Observability

- Structured logs.
- Request ID.
- Failed job alert.
- Webhook alert.
- Upload failure alert.
- Download failure metric.
- Slow query monitoring.
- Queue latency.
- Error tracking.
- Uptime monitoring.

### 17.5 Release

feature branch → pull request → CI → review → staging → migration dry-run → smoke test → production → monitor → rollback plan.

Migration destructive phải tách nhiều bước và backup trước.

---

## 18. Backlog tổng hợp

### Foundation

- [ ] Public/admin shell.
- [ ] Domain enums.
- [ ] API response convention.
- [ ] Error convention.
- [ ] Audit convention.
- [ ] File naming convention.
- [ ] Storage policy.
- [ ] CI.

### Users

- [ ] Registration.
- [ ] Verification.
- [ ] Login/logout.
- [ ] Password reset.
- [ ] Profile.
- [ ] Roles.
- [ ] Policies.
- [ ] Admin user management.

### Catalog

- [ ] Resource.
- [ ] Type/status/visibility.
- [ ] Slug.
- [ ] Category.
- [ ] Tag.
- [ ] Technology.
- [ ] Version.
- [ ] Demo.
- [ ] Documentation.
- [ ] Related resource.

### Media

- [ ] Upload.
- [ ] Validation.
- [ ] Checksum.
- [ ] Scan.
- [ ] Thumbnail.
- [ ] Gallery.
- [ ] Private download.
- [ ] Signed URL.
- [ ] Retention.

### Blog/CMS

- [ ] Post.
- [ ] Revision.
- [ ] Schedule.
- [ ] SEO.
- [ ] Sitemap.
- [ ] Legal pages.
- [ ] FAQ/help center.

### Commerce

- [ ] Variant.
- [ ] Pricing.
- [ ] Cart.
- [ ] Coupon.
- [ ] Order.
- [ ] Payment.
- [ ] Webhook.
- [ ] Entitlement.
- [ ] Invoice.
- [ ] Refund.
- [ ] License.

### Trust/support

- [ ] Review.
- [ ] Moderation.
- [ ] Q&A.
- [ ] Support ticket.
- [ ] Knowledge base.
- [ ] Update notification.

### Growth

- [ ] Wishlist.
- [ ] Collection.
- [ ] Follow.
- [ ] Search.
- [ ] Bundle.
- [ ] Newsletter.
- [ ] Affiliate.
- [ ] Conversion analytics.

### Operations

- [ ] Audit log.
- [ ] Error monitoring.
- [ ] Queue monitoring.
- [ ] Backup.
- [ ] Restore drill.
- [ ] Security audit.
- [ ] Load test.
- [ ] Accessibility audit.
- [ ] SEO audit.

---

## 19. Definition of Done

### Backend task

- [ ] Migration up/down.
- [ ] Model relation.
- [ ] Request validation.
- [ ] Policy/permission.
- [ ] Action/service.
- [ ] Error handling.
- [ ] Feature test.
- [ ] Audit nếu nhạy cảm.
- [ ] Không lộ secret/private path.

### Admin task

- [ ] Composition API.
- [ ] Boundary component rõ ràng.
- [ ] Typed props/emits khi dùng TypeScript.
- [ ] Loading state.
- [ ] Empty state.
- [ ] Error state.
- [ ] Server-side pagination.
- [ ] Permission-based actions.
- [ ] Responsive.

### Public task

- [ ] Blade render.
- [ ] SEO metadata.
- [ ] Canonical.
- [ ] Responsive.
- [ ] Accessible HTML.
- [ ] Empty/404 state.
- [ ] Không lộ private resource.
- [ ] Cache strategy.

### Commerce task

- [ ] Test mode.
- [ ] Webhook signature.
- [ ] Idempotency.
- [ ] Refund behavior.
- [ ] Entitlement behavior.
- [ ] Invoice/order snapshot.
- [ ] Audit/payment logs.
- [ ] Không fulfill bằng redirect client.

---

## 20. Quyết định cần chốt

### Trước Đợt 0

- [ ] Brand/domain.
- [ ] Thị trường/ngôn ngữ.
- [ ] Resource đầu tiên.
- [ ] Tự bán hay multi-vendor.
- [ ] MySQL hay PostgreSQL.
- [ ] S3 provider.
- [ ] Search trong MVP hay sau.

### Trước Đợt 5

- [ ] Free download rule.
- [ ] Email verification bắt buộc hay không.
- [ ] Download limit.
- [ ] Preview policy.
- [ ] Free license terms.
- [ ] Privacy/cookie policy.

### Trước Đợt 7

- [ ] Payment provider.
- [ ] Merchant of Record.
- [ ] Currency.
- [ ] Pricing tiers.
- [ ] License model.
- [ ] Support period.
- [ ] Update period.
- [ ] Refund window.
- [ ] Subscription có cần không.

### Trước Đợt 12

- [ ] Seller ownership.
- [ ] KYC.
- [ ] Revenue share.
- [ ] Payout schedule.
- [ ] Seller moderation.
- [ ] Copyright liability.
- [ ] Dispute handling.

---

## 21. Chiến lược triển khai khuyến nghị

Thứ tự:

1. Foundation.
2. Authentication.
3. Resource + taxonomy.
4. Media + version.
5. Admin workflow.
6. Public catalog + free download.
7. Blog + SEO.
8. Commerce + entitlement.
9. License + support.
10. Search + retention + analytics.
11. Production hardening.
12. Multi-vendor nếu business yêu cầu.

Không nên bắt đầu bằng payment hoặc multi-vendor. Cần xác minh trước:

- Admin publish resource ổn định.
- Client tìm thấy resource.
- Package private an toàn.
- Blog tạo traffic.
- Client quay lại tải update.
- Support xử lý được sau bán hàng.

---

## 22. Tài liệu tham khảo

- Vuetify 3: https://v3.vuetifyjs.com/en/
- Vuetify installation: https://v3.vuetifyjs.com/en/getting-started/installation/
- Vuetify theme: https://v3.vuetifyjs.com/en/features/theme/
- Lemon Squeezy products: https://docs.lemonsqueezy.com/help/products
- Lemon Squeezy licensing: https://docs.lemonsqueezy.com/help/licensing
- Lemon Squeezy license keys: https://docs.lemonsqueezy.com/help/licensing/generating-license-keys
- Lemon Squeezy webhooks: https://docs.lemonsqueezy.com/guides/developer-guide/webhooks
- Lemon Squeezy webhook events: https://docs.lemonsqueezy.com/help/webhooks/event-types
- Lemon Squeezy customer portal: https://docs.lemonsqueezy.com/help/online-store/customer-portal

---

## 23. Đối chiếu với JSON schema đã cung cấp

### 23.1 Bảng hiện có trong JSON

JSON hiện có 10 bảng:

1. posts
2. categories
3. categoriable
4. tags
5. tagable
6. slugs
7. slugable
8. seo
9. user_meta
10. meta_data

JSON chưa có relationships, foreign keys và phần lớn index business.

### 23.2 Kết quả so sánh

| Nhóm | JSON | Plan | Kết luận |
|---|---:|---:|---|
| Blog posts | Có | Có | Cần chuẩn hóa content/body và thêm SEO |
| Categories | Có | Có | Bổ sung color, icon, parent, status, sort_order |
| Tags | Có | Có | Bổ sung color, icon, description, status |
| Category/tag pivot | Có | Có | Đổi tên, thêm index và sort_order |
| Slug | Có | Có | Bỏ slugable pivot khỏi core |
| SEO | Có | Thiếu bảng riêng | Đưa seo polymorphic vào core |
| User metadata | Có | Chưa có | Chỉ dùng khi có use case cụ thể |
| Generic metadata | Có | Chưa có | Không dùng cho counter nóng |
| Resource | Không | Có | JSON thiếu entity trung tâm |
| Version/media/download | Không | Có | Bắt buộc bổ sung |
| Order/payment/license | Không | Có | Bắt buộc nếu bán resource |

Kết luận: JSON mới là bản nháp cho blog, taxonomy, slug và SEO; chưa phải schema đầy đủ cho marketplace template.

### 23.3 Sửa kiểu dữ liệu

- [ ] Đổi toàn bộ INT sang BIGINT UNSIGNED.
- [ ] Foreign key dùng cùng kiểu với primary key.
- [ ] Timestamps có nullable/default thống nhất.
- [ ] Rich text dùng LONGTEXT thay vì TEXT.
- [ ] structured_data dùng JSON thay vì TEXT.
- [ ] og_image nên tham chiếu media_id thay vì path string.
- [ ] Khai báo foreign key cho quan hệ thông thường.
- [ ] Khai báo composite index cho morph/type/id.

### 23.4 Chuẩn hóa tên pivot

Tên JSON hiện tại:

- categoriable.
- tagable.
- slugable.

Tên đề xuất:

- categorizables.
- taggables.
- slugs có trực tiếp sluggable_type và sluggable_id.

Tên cột chuẩn:

- categorizable_type, categorizable_id.
- taggable_type, taggable_id.
- sluggable_type, sluggable_id.
- seoable_type, seoable_id.
- metable_type, metable_id.

Không được trộn categoriable/categorizable hoặc tagable/taggable trong code và migration.

### 23.5 Category cần bổ sung

JSON category nên bổ sung:

- parent_id nullable.
- status.
- sort_order.
- updated_at.
- deleted_at nullable.
- slug qua hệ thống slugs.
- color nullable.
- icon nullable.

Giữ color/icon vì có ích cho UI filter và navigation.

Indexes:

- index(parent_id, sort_order).
- index(status).
- unique(category_id, categorizable_type, categorizable_id).
- index(categorizable_type, categorizable_id).

### 23.6 Tag cần bổ sung

JSON tag nên bổ sung:

- description nullable.
- status.
- updated_at.
- deleted_at nullable.
- slug qua hệ thống slugs.
- color nullable.
- icon nullable.

Tên tag phải được normalize để Vue, vue và VUE không thành ba tag khác nhau.

Indexes:

- unique(tag_id, taggable_type, taggable_id).
- index(taggable_type, taggable_id).

### 23.7 Quyết định slug

JSON dùng slugs và slugable pivot. Pivot này không cần thiết với URL public vì một slug phải resolve duy nhất và slug đã unique.

Thiết kế chính thức:

slugs:

- id.
- sluggable_type.
- sluggable_id.
- slug.
- locale.
- is_canonical.
- created_at.
- updated_at.

Indexes:

- unique(slug, locale).
- index(sluggable_type, sluggable_id).
- unique canonical theo object và locale.

Khi slug đổi, giữ slug cũ ở trạng thái non-canonical để redirect 301.

Chỉ dùng slugable pivot nếu có yêu cầu đặc biệt cho một slug gắn với nhiều object.

### 23.8 SEO polymorphic

Đưa bảng seo trong JSON vào core plan:

seo:

- id.
- seoable_type.
- seoable_id.
- meta_title nullable.
- meta_description nullable.
- meta_keywords nullable.
- canonical_url nullable.
- og_title nullable.
- og_description nullable.
- og_image_media_id nullable.
- robots default index,follow.
- structured_data JSON nullable.
- created_at.
- updated_at.

Index:

- unique(seoable_type, seoable_id).
- index(canonical_url) nếu cần.

SEO áp dụng cho:

- Resource.
- Post.
- Category.
- Tag.
- Collection.
- CMS page.

### 23.9 Post

JSON dùng content, plan dùng body. Chọn một tên duy nhất; khuyến nghị body.

Post cần:

- body LONGTEXT.
- updated_at.
- deleted_at nullable.
- featured_media_id nullable.
- view_count.
- slug relation.
- category/tag relation.
- seo relation.
- revision relation.

Rich text phải được sanitize trước khi render Blade.

### 23.10 user_meta

user_meta dạng EAV không nên dùng cho field cố định.

Field cố định giữ ở users:

- name.
- email.
- status.
- avatar.
- last_login_at.

Nếu cần metadata động, dùng user_metadata với:

- unique(user_id, meta_key).
- index(user_id, meta_key).
- value_type hoặc value JSON.
- deleted_at nullable.

Chỉ tạo bảng này khi có use case cụ thể.

### 23.11 meta_data

Không dùng meta_data để lưu view_count, download_count hoặc state quan trọng.

Counter nên nằm ở:

- resources.view_count.
- resources.download_count.
- posts.view_count.
- resource_daily_stats.
- post_daily_stats.

Nếu cần extension point, dùng model_metadata với:

- metable_type.
- metable_id.
- meta_key.
- string_value nullable.
- integer_value nullable.
- decimal_value nullable.
- boolean_value nullable.
- json_value nullable.
- unique(metable_type, metable_id, meta_key).

### 23.12 Bảng JSON còn thiếu

Bắt buộc bổ sung:

- users.
- roles.
- permissions.
- model_has_roles.
- role_has_permissions.
- resources.
- resource_versions.
- media.
- mediables.
- downloads.
- technologies.
- resource_technology.
- resource_variants.
- carts.
- cart_items.
- discounts.
- discount_redemptions.
- orders.
- order_items.
- payments.
- entitlements.
- license_keys.
- license_activations.
- billing_profiles.
- invoices.
- refunds.
- webhook_events.
- support_tickets.
- support_messages.
- notification_preferences.
- newsletter_subscribers.
- resource_daily_stats.
- post_daily_stats.
- audit_logs.

### 23.13 Relationships phải thêm vào Laravel

- posts.author_id belongsTo User.
- categorizables.category_id belongsTo Category.
- taggables.tag_id belongsTo Tag.
- Resource morphMany Slug.
- Post morphMany Slug.
- Category morphMany Slug.
- Tag morphMany Slug.
- Resource morphOne Seo.
- Post morphOne Seo.
- Category morphOne Seo.
- Tag morphOne Seo.
- Resource hasMany ResourceVersion.
- Resource morphMany Media.
- Resource hasMany Download.
- User hasMany Download.
- Order hasMany OrderItem.
- Order hasMany Payment.
- Order hasMany Entitlement.
- Entitlement belongsTo User, Resource và ResourceVariant.

Morph map phải được khai báo. Vì morph key không tạo foreign key SQL trực tiếp, cần relationship tests và orphan cleanup command.

---

## 24. Schema chuẩn hóa sau khi so sánh

### Giữ lại

- posts.
- categories.
- tags.
- slugs.
- seo.

### Đổi tên

- categoriable thành categorizables.
- tagable thành taggables.
- user_meta thành user_metadata nếu thật sự cần.
- meta_data thành model_metadata nếu thật sự cần.
- content hoặc body: chọn một, khuyến nghị body.

### Loại khỏi core

- slugable pivot.
- EAV counter trong meta_data.
- EAV cho user field cố định.

### Bổ sung vào core

- resources.
- resource_versions.
- media.
- mediables.
- downloads.
- technologies.
- resource_variants.
- users/roles/permissions.
- orders/payments.
- entitlements/licenses.
- invoices/refunds.
- webhook_events.
- resource_daily_stats.
- audit_logs.

---

## 25. Migration order điều chỉnh

1. users.
2. roles, permissions và pivots.
3. media.
4. resources.
5. slugs.
6. categories.
7. categorizables.
8. tags.
9. taggables.
10. technologies.
11. resource_technology.
12. seo.
13. resource_versions.
14. mediables.
15. posts.
16. post_revisions.
17. post_resource.
18. favorites, collections và follows.
19. reviews, questions và answers.
20. resource_variants.
21. downloads.
22. carts và cart_items.
23. discounts và redemptions.
24. orders, order_items và payments.
25. entitlements, license_keys và activations.
26. billing_profiles, invoices và refunds.
27. webhook_events.
28. support_tickets và support_messages.
29. notification_preferences và newsletter_subscribers.
30. resource_daily_stats và post_daily_stats.
31. audit_logs.
32. user_metadata nếu có use case.
33. model_metadata nếu có use case.

---

## 26. Database acceptance checklist

- [ ] Migrations tạo đủ core tables.
- [ ] Migration rollback không lỗi.
- [ ] BIGINT UNSIGNED thống nhất.
- [ ] Foreign key hợp lệ.
- [ ] Morph map được khai báo.
- [ ] Composite indexes đầy đủ.
- [ ] Một post có slug/category/tag/SEO.
- [ ] Một resource có slug/category/tag/SEO.
- [ ] Một resource có nhiều version.
- [ ] Một version có package và preview media.
- [ ] Client tạo được download record.
- [ ] Order tạo được entitlement.
- [ ] Entitlement kiểm soát download.
- [ ] Không có counter quan trọng nằm trong EAV.
- [ ] Có factories và seeders.
- [ ] Có relationship tests.
- [ ] Có migration rollback test.
- [ ] Có dữ liệu demo cho public/admin.

