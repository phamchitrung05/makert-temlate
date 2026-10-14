# Kế hoạch backend cho public website Blade

**Cập nhật:** 11/10/2026  
**Trạng thái:** PARTIAL — public Resource Template backend PB-01/PB-02/PB-03/PB-04/PB-05 đã hoàn tất trong RT-01; blog/taxonomy/sitemap đầy đủ và UI public mở rộng vẫn để phase riêng.

## Mục tiêu

Chuẩn bị đầy đủ route, query, dữ liệu view, SEO và download để public website
được render bằng Laravel Blade. Laravel là source of truth; Blade chỉ nhận dữ
liệu đã được backend lọc và chuẩn hóa, không tự query database hoặc quyết định
quyền truy cập.

## Phạm vi đợt backend

- Public catalog Resource: danh sách, chi tiết, lọc theo category/tag/type và
  phân trang.
- Public blog Post: danh sách, chi tiết, lọc taxonomy và phân trang.
- Slug public: slug primary, slug cũ trả redirect 301 đúng loại nội dung, slug
  không tồn tại hoặc nội dung không public trả 404.
- SEO contract: title/description/canonical/robots/OG/JSON-LD, sitemap và
  robots.txt dùng Settings hiện có.
- Dữ liệu menu public: category active có `show_on_menu`, thứ tự và quan hệ
  cần thiết; không trả taxonomy private hoặc bản ghi chưa publish.
- Download backend ở phase riêng: customer/session, signed URL hoặc stream,
  rate limit, kiểm tra package ready/scan clean, ghi lịch sử và tăng counter.

## Ngoài phạm vi

- Không thiết kế hoặc hoàn thiện `resources/views/public`, layout, CSS,
  responsive, component Blade hay nội dung landing page.
- Không làm checkout/payment, order, invoice, entitlement trả phí hoặc webhook
  commerce; đó là C-01 sau khi D-01 được chốt.
- Không mở rộng customer admin A-01; chỉ dùng customer auth hiện có khi
  download cần đăng nhập.
- Không mở API public song song nếu route Blade đã đáp ứng nhu cầu; API chỉ
  được thêm khi có consumer cụ thể.

## Checklist triển khai

### PB-01 — Chốt public contract và route (`TODO`)

- [ ] Chốt route name và URL: `/`, `/resources`, `/resources/{slug}`,
  `/blog`, `/blog/{slug}`, taxonomy/filter và `/sitemap.xml`.
- [ ] Chốt query parameters được phép: `q`, `category`, `tag`, `type`, `page`,
  `sort`; reject hoặc bỏ qua field ngoài allowlist.
- [ ] Chốt 404/301: slug cũ chỉ redirect tới primary URL khi model vẫn public;
  không redirect draft/private để tránh lộ trạng thái.
- [ ] Chốt locale hiện tại từ `app.locale`; để dành cấu trúc locale prefix cho
  khi bật đa ngôn ngữ.

### PB-02 — Query/service public (`TODO`)

- [ ] Tạo query/service riêng dưới `app/Services/Public` hoặc repository query
  object; controller chỉ điều phối và trả view.
- [ ] Resource bắt buộc `status=published`, `visibility=public` và
  `published_at <= now()`; dùng lại `PubliclyVisibleResourceCriteria` và bổ
  sung điều kiện thời gian nếu criteria hiện tại chưa có.
- [ ] Post bắt buộc `status=published`, `published_at` hợp lệ và không
  soft-deleted.
- [ ] Eager-load có allowlist: primary slug, SEO metadata, thumbnail/OG public,
  category/tag và default ready version; không load payload admin, provenance,
  private media hoặc raw AI data.
- [ ] Category/tag/type filter chỉ nhận bản ghi active/public; giữ query
  pagination ổn định và không để client tự chọn cột/order tùy ý.
- [ ] Chuẩn hóa dữ liệu thành DTO/view model public bounded; không truyền model
  Eloquent nguyên trạng vào Blade.

### PB-03 — Slug và SEO backend (`TODO`)

- [ ] Tạo resolver dùng `SlugService` để phân biệt primary slug và historical
  slug cho Resource/Post/Category/Tag.
- [ ] Tạo SEO presenter dùng metadata đã lưu, fallback theo title/excerpt/site
  settings và canonical route; canonical ngoài domain chỉ được dùng khi policy
  cho phép.
- [ ] Tạo presenter cho Open Graph/Twitter và JSON-LD (`WebSite`, `CollectionPage`,
  `Product`/`Article`, `BreadcrumbList`) với dữ liệu allowlist.
- [ ] Hoàn thiện `/sitemap.xml`: chỉ URL public, primary, indexable; phân trang
  sitemap nếu vượt ngưỡng; cache và invalidation sau publish/unpublish/slug đổi.
- [ ] Giữ `/robots.txt` đọc từ `ProjectSettingsService`, bổ sung sitemap URL và
  không index `/admin`, auth, download token.

### PB-04 — Controller/view contract (`TODO`)

- [ ] Thêm controller public tách theo domain (`CatalogController`,
  `BlogController`, `TaxonomyController`, `SitemapController`) hoặc cấu trúc
  tương đương.
- [ ] Mỗi action truyền view model + SEO data + pagination/filter state; không
  gọi query trong Blade.
- [ ] Giữ `HomeController` hiện có làm entry point; chỉ nối dữ liệu khi contract
  trang chủ được chốt.
- [ ] Chỉ thêm placeholder view tối thiểu nếu route cần smoke test; phần markup
  và UI thật để chủ dự án thực hiện sau.

### PB-05 — Download backend (`TODO`, sau PB-02)

- [ ] Tạo model/repository/action cho `downloads`; liên kết resource, version,
  media và customer nullable theo schema hiện có.
- [ ] Chỉ cho tải version `ready`, resource public/published và package media có
  `scan_status=clean`; không trả đường dẫn disk trực tiếp.
- [ ] Chốt free-download policy: guest hay bắt buộc customer, verify email,
  số lượt/IP/customer, thời hạn signed URL và retention user-agent/IP hash.
- [ ] Dùng signed route/temporary URL hoặc stream controller; token không chứa
  ID nhạy cảm ngoài allowlist và không xuất hiện trong canonical/robots.
- [ ] Ghi download atomically, chống double-count khi retry, tăng
  `download_count` có kiểm soát và giữ audit cần thiết.
- [ ] Throttle theo route và policy; lỗi hết hạn, chưa scan, vượt quota trả mã
  và thông báo ổn định.

### PB-06 — Cache, vận hành và bảo mật (`TODO`)

- [ ] Cache catalog/taxonomy/SEO/sitemap theo key gồm locale + filter + page;
  xóa cache khi publish, archive, đổi slug, đổi visibility hoặc sửa SEO.
- [ ] Bổ sung policy/authorization boundary cho customer download; public read
  không được dùng admin policy một cách ngầm định.
- [ ] Sanitize/escape content Post trước khi đưa ra Blade; giữ sanitizer hiện
  có và không cho raw attribute/URL nguy hiểm lọt vào JSON-LD hoặc meta.
- [ ] Ghi log bounded cho 404/redirect/download failure; không log token,
  cookie, authorization header hoặc URL private.
- [ ] Kiểm query count/N+1 và response cache header ở môi trường staging.

### PB-07 — Kiểm thử và bàn giao contract (`TODO`)

- [ ] Feature test route catalog: published/public xuất hiện; draft/private,
  future publish, soft-deleted và private media không xuất hiện.
- [ ] Feature test filter, pagination, taxonomy, primary slug và redirect slug
  cũ; kiểm tra không redirect nội dung đã ẩn.
- [ ] Feature test SEO/sitemap/robots/JSON-LD: canonical đúng, noindex đúng,
  không chứa private URL hoặc secret.
- [ ] Feature test download: version/scan gate, signed URL expiry, rate limit,
  customer/guest policy, history và counter idempotency.
- [ ] Ghi contract route/data vào `docs/api/` và bằng chứng vào `docs/qa/`;
  cập nhật W-01/D-01 trong `PLAN.md` khi từng phase đạt.

## Thứ tự thực hiện đề xuất

1. PB-01 chốt URL/status/locale và policy free download.
2. PB-02 xây query/service + DTO cho Resource/Post/taxonomy.
3. PB-03 xây slug/SEO/sitemap/robots presenter.
4. PB-04 nối controller với contract để chủ dự án có thể viết Blade UI.
5. PB-07 kiểm thử catalog/SEO trước khi cho index public.
6. PB-05 triển khai download sau khi policy auth/quota/license được chốt.
7. PB-06 cache, rate limit, monitoring và staging verification.

## Điều kiện hoàn thành backend

- Mọi route public chỉ đọc dữ liệu đã publish/public và không truy vấn từ Blade.
- Resource/Post/taxonomy có URL primary ổn định, redirect slug cũ và SEO data
  đầy đủ.
- Sitemap/robots/canonical/OG không làm lộ draft, private asset, admin route
  hoặc secret.
- Download không bypass scan/visibility, có token hết hạn, rate limit, lịch sử
  và counter kiểm chứng được.
- Chủ dự án có thể tự viết giao diện Blade dựa trên route name và view contract
  mà không phải sửa business rule hoặc query bảo mật.
