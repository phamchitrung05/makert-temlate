# Kế hoạch nâng cấp trang Add/Edit Post

Đã được duyệt ngày 2026-09-29. Lưu kế hoạch trước khi triển khai.

> Ghi chú cập nhật 2026-09-30: file này giữ checklist riêng cho Add/Edit Post.
> Taxonomy, Media API và AI Content Agent đã được hợp nhất theo dõi ở
> [PLAN_POST_MEDIA_AI_INTEGRATION.md](./PLAN_POST_MEDIA_AI_INTEGRATION.md); không
> tạo thêm pipeline hoặc plan song song.

## Phạm vi

- Sinh slug từ backend khi blur Title; không tạo bản ghi lúc preview.
- Hoàn thiện dialog Media cho Featured Image và Gallery.
- Lưu metadata SEO độc lập và chấm điểm/checklist realtime.
- AI và taxonomy không triển khai thành pipeline riêng trong file này; trạng thái
  tích hợp hiện tại được ghi ở plan hợp nhất bên trên.
- Giữ nguyên thay đổi local không thuộc nhiệm vụ.

## 1. Slug

- [x] Thêm preview trong SlugService dùng chung thuật toán với khi lưu.
- [x] Kiểm tra duy nhất theo morph type + locale; hậu tố base, -1, -2...
- [x] Loại trừ chính model khi edit, giữ slug lịch sử của model khác; tối đa 250 ký tự.
- [x] API POST /api/admin/slugs/preview với title/model_type/model_id, quyền theo model, throttle và validation. Endpoint riêng Post đã được gỡ theo yêu cầu dọn dẹp.
- [x] Title blur phát sự kiện; form nhận slug server, loading/error, bỏ response cũ.
- [x] Title rỗng xóa preview; title không đổi không gọi lại; slug readonly.
- [x] Backend kiểm tra lại lúc lưu, retry collision có giới hạn; không tin preview là giữ chỗ.
- [x] Không sửa URL cũ; cập nhật regression tests các model dùng chung service.

## 2. Media

- [x] Import MediaAssetField rõ ràng trong PostMediaPanel.
- [x] Featured Image chọn một; Gallery chọn nhiều; lọc image/public.
- [x] Dialog nhận selection hiện có. Gallery chọn tạm, xác nhận mới cập nhật; hủy giữ nguyên. Featured Image chọn một ảnh thì cập nhật ngay.
- [x] Giữ selection qua tìm kiếm/phân trang; không trùng ID; cho phép xác nhận rỗng.
- [x] Giữ thứ tự gallery và hỗ trợ sắp xếp.
- [x] Truyền trạng thái disabled, tôn trọng quyền và kiểm tra asset backend.
- [x] Lưu Post mới đồng bộ usage; bỏ ảnh không xóa asset; upload tạo asset độc lập.

## 3. SEO

### Các trường

- focus_keyword; seo_title (fallback title); seo_description (fallback excerpt); excerpt.
- canonical_url (fallback permalink); robots_index; robots_follow.
- og_title/og_description fallback SEO; ảnh chia sẻ mặc định dùng Featured Image.
- alt_text dùng metadata media sẵn có; ảnh editor dùng alt.
- Migration, model, validation, resource và payload phải round-trip các trường.
- Không có meta keywords; score là hướng dẫn biên tập, không bảo đảm thứ hạng và không chặn lưu.

### Checklist và trọng số

| Tiêu chí | Ngưỡng nội bộ | Điểm |
|---|---|---:|
| Nội dung | >=300 đơn vị từ | 20 |
| Từ khóa chính | Có | 5 |
| SEO title | 30–60 ký tự | 10 |
| Từ khóa trong title | Có | 10 |
| Slug | Server đã kiểm tra cho title hiện tại | 5 |
| Từ khóa trong slug | Có sau chuẩn hóa | 5 |
| SEO description | 120–160 ký tự | 10 |
| Từ khóa trong description | Có | 5 |
| Từ khóa mở đầu | Trong 100 từ đầu | 10 |
| H2 | >=1 | 5 |
| Liên kết nội bộ | >=1 hợp lệ | 5 |
| Featured Image | Có | 5 |
| Alt ảnh | Đủ trên các ảnh nội dung | 5 |

- [x] Tách SEO Settings, Analysis, Preview khỏi sidebar; PostForm là nguồn state duy nhất.
- [x] Parser HTML giải mã entity, bỏ script/style, giữ ranh giới đoạn; dùng chung thống kê editor/SEO.
- [x] Đếm đơn vị phân cách bằng khoảng trắng có chữ/số, nhất quán tiếng Việt.
- [x] Mỗi tiêu chí có progress, passed/pending/not-applicable và gợi ý; đủ thì tích xanh, giảm thì bỏ tích.
- [x] Score = tổng điểm đạt / tổng điểm áp dụng; alt không có ảnh là N/A; thiếu keyword không được điểm phụ thuộc.
- [x] Canonical/noindex là cảnh báo riêng; SEO/social preview dùng metadata hiệu lực.
- [x] Không gọi API mỗi lần gõ; không lưu score do client gửi.

## Phân chia trách nhiệm component

- Page add/index: tải/lưu Post và điều hướng, giữ mỏng.
- PostForm: state chung, đồng bộ dữ liệu, submit; kết nối slug composable.
- PostContentPanel: v-model title/content/excerpt, phát title-blur; nhận trạng thái slug.
- PostMediaPanel: v-model thumbnail/contentImages; nhận disabled.
- PostSeoSettings: v-model SEO metadata riêng.
- PostSeoAnalysis: nhận kết quả rules và hiển thị score/checklist.
- PostSeoPreview: nhận metadata hiệu lực và URL, hiển thị preview.
- Slug composable: quản lý request/cache/loading/error và chống response cũ.
- SEO utility/composable: parse nội dung, derive rules và score.

## Kiểm thử / thứ tự

1. Slug service + API + frontend blur: tiếng Việt, collision, khác type/locale, edit, request cũ, lưu đồng thời.
2. Media dialog: single/multiple, preselection, cancel, empty, reopen, thứ tự và persistence.
3. SEO persistence: tách title/excerpt; validation và round-trip.
4. SEO checklist: 299/300/301 từ, HTML/entity, keyword/heading/link/alt, scoring và fallback.
5. Chạy backend/frontend tests, lint phạm vi thay đổi và production build nếu môi trường hỗ trợ.

## Giới hạn public SEO

Chưa có trang blog public. Metadata admin chưa tự xuất ra HTML public. Khi xây blog public cần title/description/canonical/robots/OG, alt, redirect 301 và structured data. Không nằm trong lượt triển khai này.

## Nhật ký triển khai

### Điều chỉnh theo yêu cầu tiếp theo

- [x] Đổi Permalink preview thành Slug; prefix chỉ là domain, value là slug backend sau blur Title. Không đổi đường dẫn /blog trong preview/copy; chưa có trang public.
- [x] Tích hợp PostEditor TinyMCE, giữ contract HTML v-model và disabled, cập nhật SEO realtime. Chưa kích hoạt editor thực tế vì thiếu lựa chọn giấy phép/API key.
- [x] Thêm Pinia slug store gọi API chung theo model_type/model_id; kiểm tra allowlist và quyền ở backend. Đã gỡ controller/route PostSlugPreview thừa và chuyển regression tests sang API chung.
- [x] Chuyển SEO sang cột trái, gom Analysis/Settings/Preview thành tab; state ở PostForm không mất khi đổi tab.
- [x] Chuyển Featured Image và Image Gallery sang cột phải cùng Post Settings.
- [x] Chuyển engine SEO từ `postSeo.js` sang `resources/js/composables/seoMetadata.js` để dùng chung model.
- [x] Đổi `usePostSlug.js` thành composable `useSlug.js`, nhận title/model type/model ID dạng reactive hoặc getter.
- [x] Frontend 75 tests qua; ESLint JavaScript/Vue và targeted Pint cho file thay đổi
  qua; production build qua, TinyMCE runtime được tách thành chunk tải lười.
- [x] Toàn bộ backend 127 tests / 670 assertions qua, gồm API đa model, phân quyền
  create/update, validation, collision, taxonomy và AI candidate/apply.
- [x] Chủ dự án chọn dùng key Tiny Cloud; đã thêm chỗ nhập `VITE_TINYMCE_API_KEY` vào `.env` và hướng dẫn trong `.env.example`. Để `VITE_TINYMCE_LICENSE_KEY` trống khi dùng Cloud.
- [ ] Chủ dự án điền key, khởi động lại Vite/build và kiểm thử TinyMCE trực tiếp trên trình duyệt. Chưa kiểm chứng key/domain thật.

- [x] Post form round-trip category/tag và SEO metadata được đồng bộ qua API;
  từ 2026-10-05 tạo nội dung AI tập trung tại trang AI Content. Đã gỡ nút tạo/điền
  nội dung AI và dialog riêng khỏi Post List/Add/Edit. Chi tiết AI nằm ở plan hợp nhất.

Component map đợt điều chỉnh: PostForm giữ dữ liệu; PostContentPanel hiển thị Title/Slug; PostEditor bọc TinyMCE; PostSeoTabs điều phối ba tab; useSlug giữ vòng đời request riêng của form và gọi useSlugStore; Pinia slug phụ trách request API chung, không chia sẻ slug kết quả giữa các form.

#### Cách dùng Slug chung

`useSlugStore().generateSlug({ title, modelType: 'post', modelId: null }, { signal })` trả `{ slug, model_type }` cho từng caller. API `POST /api/admin/slugs/preview` nhận `title`, `model_type`, `model_id` tùy chọn. Hỗ trợ `post`, `resource`, `category`, `tag`, `technology`; thêm model tại `config/slug-models.php` kèm quyền create/update. Permission `create` đủ để preview slug cả khi có `model_id`; permission `update` cũng được chấp nhận cho luồng edit. Post hỗ trợ cả `posts.manage` và `posts.create`; kiểm tra dựa trên permission thực tế user đã được cấp. Preview không giữ chỗ; khi lưu vẫn phải qua SlugService để kiểm tra collision lần cuối.

#### Kích hoạt TinyMCE

- Đã pin `tinymce@8.9.2` và `@tinymce/tinymce-vue@6.3.0`; không thay Tiptap ở trang khác.
- Nếu chủ dự án chọn GPL self-host: cấu hình `VITE_TINYMCE_LICENSE_KEY=gpl`; runtime, skin và plugin đi cùng bundle, không dùng Tiny Cloud.
- Nếu chọn Tiny Cloud: điền `VITE_TINYMCE_API_KEY`, để license key trống. API key frontend phải được giới hạn domain theo cấu hình Tiny Cloud.
- Self-host thương mại cần bundle/license manager tương ứng; chưa tích hợp trong phạm vi này.
- Sau khi cấu hình phải khởi động lại Vite hoặc build lại. Chưa cấu hình sẽ hiện cảnh báo và ô HTML tạm; lỗi tải sau 20 giây cũng giữ nội dung HTML để không mất bản nháp.
- Các test TinyMCE hiện kiểm tra contract wrapper bằng mock, không thay thế kiểm thử trình duyệt/editor thật.

- Khởi tạo kế hoạch trước thay đổi ứng dụng. Mặc định dùng Featured Image cho ảnh chia sẻ; chưa thêm picker OG riêng.
- Đã triển khai 3 nhóm chức năng và tách SEO thành Settings/Analysis/Preview; giữ JavaScript + Composition API theo stack hiện tại.
- Thêm PostEditor riêng để có H2/H3, chèn link và giữ HTML image/alt; không thay editor dùng ở các trang khác.
- SEO được lưu độc lập; nội dung và sidebar dùng chung kết quả phân tích HTML. Score không được lưu từ client.
- Slug readonly dùng kết quả server, lỗi preview không chặn lưu; backend quyết định slug cuối cùng. Retry unique dùng savepoint, Post actions retry deadlock tối đa 5 lần.
- Đã chạy riêng 4 migration trên MySQL local (127.0.0.1 / makert-template): media_assets, media_asset_usages, posts và các cột SEO; không xóa dữ liệu.
- Kết quả cuối: toàn bộ frontend 37 tests qua; toàn bộ backend 87 tests / 483 assertions qua.
- ESLint và Laravel Pint trong phạm vi thay đổi đều qua; git diff --check không có lỗi whitespace.
- Build production đã qua; cảnh báo asset section-title-icon.png có sẵn ở phần front-pages, ngoài phạm vi Post.
- Kiểm thử collision bao gồm preview bị chiếm trước khi lưu, retry lỗi unique mô phỏng và rollback khi hết retry. Chưa chạy stress test nhiều connection MySQL thực hoặc kiểm thử UI trên trình duyệt thật.
