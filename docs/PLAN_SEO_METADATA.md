# Kế hoạch triển khai nghiệp vụ SEO dùng chung

## Mục tiêu

Tách metadata SEO khỏi bảng `posts` để có thể dùng chung cho Post, Resource,
Page và các loại nội dung khác trong tương lai. SEO score/checklist chỉ là dữ
liệu phân tích realtime, không lưu như dữ liệu nghiệp vụ.

## Phạm vi

- Tạo bảng `seo_metadata` liên kết polymorphic với model nội dung.
- Di chuyển dữ liệu SEO hiện tại của Post sang bảng mới.
- Giữ API Post tương thích với frontend hiện tại.
- Chuẩn hóa fallback metadata và quy tắc canonical/robots/Open Graph.
- Tái sử dụng SEO form, checklist và preview cho nhiều model.
- Không triển khai SEO public HTML, sitemap hoặc structured data trong đợt này.

## 1. Thiết kế dữ liệu

### Bảng `seo_metadata`

Các cột dự kiến:

- `id`
- `seoable_type`
- `seoable_id`
- `focus_keyword` nullable
- `seo_title` nullable
- `seo_description` nullable
- `canonical_url` nullable
- `robots_index` boolean, mặc định `true`
- `robots_follow` boolean, mặc định `true`
- `og_title` nullable
- `og_description` nullable
- `og_image_id` nullable, tham chiếu logic tới media asset
- `created_at`, `updated_at`

Ràng buộc:

- Unique theo `seoable_type` + `seoable_id`.
- Index cho `seoable_type` + `seoable_id`.
- Không lưu `seo_score`, checklist result hoặc dữ liệu suy diễn.
- Không lưu `excerpt`; excerpt thuộc model nội dung và chỉ được dùng làm fallback.

## 2. Model và trait dùng chung

- Tạo `SeoMetadata` model.
- Tạo trait `HasSeoMetadata` với quan hệ `morphOne`.
- Thêm trait vào `Post`, sau đó mở rộng cho `Resource`, `Page` và model khác.
- Tạo helper lấy metadata hiệu lực:
  - SEO title → `seo_title` hoặc title model.
  - Description → `seo_description` hoặc excerpt model.
  - Canonical → `canonical_url` hoặc permalink model.
  - OG title/description → giá trị OG, fallback về SEO metadata hiệu lực.
  - OG image → `og_image_id`, fallback về Featured Image.

## 3. Migration dữ liệu Post

- Tạo migration `seo_metadata`.
- Backfill dữ liệu từ các cột SEO hiện tại của `posts` sang bản ghi polymorphic.
- Kiểm tra số lượng bản ghi và đối chiếu từng field trước khi xóa cột cũ.
- Trong giai đoạn tương thích, Resource/PostResource vẫn trả shape field SEO cũ.
- Sau khi frontend và API dùng quan hệ mới ổn định, tạo migration riêng xóa các
  cột SEO khỏi `posts`.
- Migration phải an toàn khi chạy lại trên môi trường đã có dữ liệu.

## 4. Backend API và validation

- Tạo request/concern validation dùng chung cho SEO metadata.
- Post create/update nhận nested payload hoặc shape hiện tại nhưng chuẩn hóa về
  `seo_metadata` trong action/service.
- Không cho client gửi hoặc lưu `seo_score`.
- Validate:
  - giới hạn độ dài title/description/keyword;
  - canonical chỉ nhận URL hợp lệ;
  - robots nhận boolean;
  - `og_image_id` phải là media asset hợp lệ và được phép sử dụng.
- PostResource trả SEO metadata đã resolve fallback để frontend không phải tự
  ghép dữ liệu từ nhiều nguồn.
- Chuẩn bị service dùng chung, ví dụ `SeoMetadataService`, để create/update,
  fallback và đồng bộ không bị lặp giữa các controller.

## 5. Frontend dùng chung

- Tách state SEO khỏi `PostForm` thành composable/store có thể nhận model type.
- Tái sử dụng các component hiện tại:
  - `PostSeoSettings` → component SEO settings dùng chung.
  - `PostSeoAnalysis` → nhận content/title/metadata và trả checklist.
  - `PostSeoPreview` → nhận metadata hiệu lực và URL public.
- Payload service chuyển sang metadata chung nhưng vẫn giữ adapter cho Post cũ
  trong giai đoạn chuyển tiếp.
- Feature Image tiếp tục là fallback cho OG image; chỉ thêm picker OG riêng khi
  có yêu cầu sản phẩm.

## 6. Quy tắc SEO checklist

Giữ checklist hiện tại và chuẩn hóa thành cấu hình dùng chung:

- Nội dung tối thiểu 300 từ.
- Focus keyword tồn tại.
- SEO title 30–60 ký tự.
- Keyword trong title.
- Slug đã được backend kiểm tra.
- Keyword trong slug.
- SEO description 120–160 ký tự.
- Keyword trong description.
- Keyword xuất hiện trong 100 từ đầu.
- Có ít nhất một H2.
- Có liên kết nội bộ hợp lệ.
- Có Featured Image.
- Alt text đầy đủ cho ảnh nội dung.

Checklist phải:

- dùng cùng một engine phân tích cho mọi model;
- hiển thị progress và trạng thái passed/pending/not-applicable;
- tính score theo tổng điểm áp dụng;
- không chặn lưu nội dung;
- không gửi score lên backend.

## 7. Quyền và bảo mật

- SEO metadata dùng quyền create/update của model cha.
- Không tạo endpoint SEO cho model chưa được allowlist.
- Media OG image phải qua cùng quy tắc visibility/ownership như media field.
- Sanitize/validate canonical URL và nội dung HTML trước khi dùng cho preview.

## 8. Kiểm thử

### Backend

- Tạo/cập nhật SEO cho Post.
- Fallback khi field SEO null.
- Round-trip qua PostResource.
- Backfill dữ liệu migration.
- Unique một bản ghi SEO cho mỗi model.
- Dùng chung cho Resource hoặc model giả lập.
- Validation canonical, boolean robots và media image.
- Không nhận/lưu `seo_score` từ client.
- Không cho user thiếu quyền cập nhật SEO.

### Frontend

- SEO settings giữ state khi chuyển tab.
- Checklist 299/300/301 từ.
- Fallback title/excerpt/permalink.
- Score thay đổi realtime khi dữ liệu tăng/giảm.
- Preview social dùng đúng metadata hiệu lực.
- Payload không chứa score client.

## 9. Thứ tự triển khai

1. Chốt schema và fallback contract.
2. Tạo migration/model/trait/service backend.
3. Backfill dữ liệu Post và thêm test migration.
4. Cập nhật Post action/request/resource nhưng giữ backward compatibility.
5. Tách component/composable SEO dùng chung.
6. Chuyển Post frontend sang contract mới.
7. Chạy toàn bộ backend/frontend test, Pint, ESLint và production build.
8. Kiểm tra dữ liệu production/staging rồi mới xóa cột SEO cũ.
9. Mở rộng trait và component cho Resource/model tiếp theo.

## Tiêu chí hoàn thành

- Post vẫn hiển thị và lưu đúng toàn bộ SEO metadata hiện tại.
- Không mất dữ liệu trong quá trình backfill.
- Một service/trait dùng được cho ít nhất hai model.
- Fallback metadata nhất quán giữa API, preview và checklist.
- Score/checklist không được lưu hoặc tin từ client.
- Có migration rollback rõ ràng và test regression đầy đủ.

## Nhật ký triển khai

- [x] Tạo `seo_metadata` polymorphic và unique theo model nội dung.
- [x] Tạo `SeoMetadata`, `HasSeoMetadata` và `SeoMetadataService` dùng chung.
- [x] Backfill SEO legacy của Post/Resource.
- [x] Đồng bộ SEO trong Post/Resource actions và trả metadata qua Resource.
- [x] Xóa cột SEO trùng sau backfill; giữ `posts.excerpt` là nội dung.
- [x] Bổ sung validation `og_image_id` và test fallback/reuse giữa model.
- [x] Tách `useSeoMetadata` để PostForm dùng chung engine phân tích content/checklist.
- [x] Chuyển utility SEO sang `composables/seoMetadata.js`; hỗ trợ field title/description/media khác nhau giữa các model.
- [x] Chuẩn hóa preview slug thành `composables/useSlug.js`, không còn logic Post-specific trong component.
- [x] Backend test hiện tại: 96 tests, 539 assertions; frontend: 59 tests.
- [x] ESLint file thay đổi, Pint và production build đã chạy đạt.
- [ ] Chưa triển khai trang public SEO, sitemap, structured data và trang quản lý role.
