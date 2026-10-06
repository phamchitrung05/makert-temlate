# Ảnh content bằng link và Gallery độc lập — 2026-10-06

## Phạm vi và contract

- Ảnh trong `posts.content` là URL trong HTML; một URL có thể xuất hiện nhiều lần
  với alt/caption riêng. Không tạo `media_asset_usages` cho ảnh content.
- Post editor có **Chèn ảnh bằng link**. Chọn/upload ảnh MediaLibrary cũng
  chèn URL, không tự thêm vào Gallery hoặc giữ asset ID cho ảnh mới trong Post.
- Gallery nhận `media.gallery_image_ids`, trả `media.gallery_images` và lưu
  usage `post.gallery` theo thứ tự người dùng chọn. Thumbnail giữ field riêng.
- Payload Post và attach API không cho ghi contract `post.content_images` cũ.
  Enum legacy chỉ giữ để đọc dữ liệu trước migration và phục vụ rollback tên field.
- Candidate AI vẫn kiểm ref nội bộ tại boundary riêng để kiểm nguồn/regenerate.
  Apply vào Post không tạo usage content hoặc tự điền Gallery. Cleanup run đọc
  URL trong Post để giữ file đang dùng, không ghi quan hệ media.
- Chỉ kiểm định URL/markup; không tải ảnh ngoài khi lưu Post. Link phải là
  HTTP/HTTPS, protocol-relative hoặc đường dẫn gốc website; ảnh blob/data tạm
  và URL thực thi/file không được lưu.
- `post_type = gallery` và giao diện trình diễn ảnh là phạm vi tương lai.

## Kiểm chứng tự động

- Backend: **49 tests / 432 assertions** đạt trong `PostGalleryTest`,
  `ContentMediaReferenceTest`, `PostSeoSlugTest`, `MediaTask8IntegrationTest`,
  `MediaAssetContractTest` và `AiContentReviewApiTest`.
- Frontend: **8 files / 55 tests** đạt cho Post service/form/editor/media/SEO,
  inline media, MediaLibrary dialog và fake Media API.
- Các trường hợp gồm: link ngoài/gốc/lặp không cần quyền media.attach; Gallery
  độc lập qua create/partial update/reorder/clear; validation/quyền và rollback;
  legacy contract bị từ chối; migration giữ ảnh riêng/thứ tự/HTML/file; candidate
  AI giữ ref và cleanup không làm hỏng link Post đã lưu.
- Scoped ESLint và Laravel Pint đạt. Production build cuối đạt trong **38.40 giây**.
- Frontend test stubs vẫn có cảnh báo unresolved component hiện có; không có
  test thất bại. Không lấy kết quả full suites của các milestone cũ làm kết quả
  kiểm chứng của mốc này.

## Browser trên dữ liệu thử riêng

- Laravel/Vue thật tại `127.0.0.1:8001`, SQLite fixture và tài khoản giả riêng.
- Chèn `/images/avatars/avatar-1.png` hai lần bằng dialog link với alt/caption
  khác nhau. Gallery vẫn rỗng; lưu draft và mở lại giữ cả hai lần xuất hiện.
- Chọn asset QA riêng cho Gallery; đối chiếu giá trị HTML trước/sau selection
  xác nhận không thay đổi. Lưu/mở lại Gallery vẫn có ảnh đã chọn.
- Sửa thêm đoạn văn content, lưu draft và mở lại; hai ảnh content và Gallery
  đã chọn vẫn tồn tại độc lập.
- Fixture DB đối chiếu hai URL lặp, không có asset marker trong Post HTML và
  chỉ một usage `post.gallery` cho bài thử. Reorder/clear được kiểm bằng test
  tự động, không giả lập thao tác đó trong browser với fixture chỉ có một asset.
- TinyMCE localhost bị khóa theo cấu hình/key hiện có; browser kiểm nhánh HTML
  fallback và Preview văn bản hiện có. Không sửa `.env`, key hoặc bỏ qua rào cản của editor.
- Không publish bài, gọi provider AI, gửi email hoặc sửa dữ liệu thật qua browser.
- Tab QA đã đóng và xác nhận server tạm ở cổng 8001 không còn chạy sau kiểm tra.

Ảnh chụp sau khi lưu và mở lại: [Content và Gallery](./post-content-gallery.png).
Đối chiếu dữ liệu thử: [Fixture verification](./fixture-verification.json).

## Migration trên local

Đã áp dụng riêng
`2026_10_06_100000_separate_post_gallery_from_content_images.php`.

| Dữ liệu | Trước | Sau |
|---|---:|---:|
| Post | 4 | 4 |
| MediaAsset | 22 | 22 |
| Bản ghi file Spatie | 22 | 22 |
| Usage `post.content_images` | 2 | 0 |
| Usage `post.gallery` | 0 | 0 |

Cả hai usage local cũ là ảnh trong HTML nên được bỏ quan hệ, không tự đưa vào
Gallery. Hash nội dung của tất cả Post, metadata file và file gốc của asset
liên quan giữ nguyên. Snapshot metadata/hashes trước migration được giữ trong
thư mục QA bị Git ignore để đối chiếu/khôi phục usage khi cần.

Migration chuyển ảnh legacy không xuất hiện trong content sang Gallery theo
thứ tự cũ, giữ MediaAsset/file và chạy lặp không tạo usage trùng. `down()` chỉ
đổi tên field Gallery về legacy; không tái tạo những quan hệ inline đã bỏ.
