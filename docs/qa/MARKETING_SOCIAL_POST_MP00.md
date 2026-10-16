# QA Marketing Social Post — MP-00

**Trạng thái:** IN PROGRESS — contract/config/fixture đã chuẩn bị; smoke test
Facebook Page thật chờ Meta App và Page token của môi trường chạy.

## Đã hoàn tất

- [x] Chốt v1 single workspace; chưa thêm `workspace_id`/`tenant_id`.
- [x] Chốt caption canonical dùng chung; không có `platform_caption`.
- [x] Chốt product facts tối thiểu và quy tắc không bịa.
- [x] Chốt ba nhóm trạng thái generation/review/publication.
- [x] Thêm permission module `marketing.view/manage` và resource
  `social_posts.*`/`social_accounts.*` vào catalog/role seed.
- [x] Ghi quy ước mỗi resource Marketing mới phải có permission riêng, route
  middleware, role seed và tài liệu API.
- [x] Chuẩn bị cấu hình `.env.example` và `config/social.php`.
- [x] Tạo fixture context tại `docs/qa/marketing-social-post/mp00-product-fixture.json`.

## Còn chờ môi trường Facebook

- [ ] Tạo Meta App ở mode phù hợp và cấu hình redirect URI backend.
- [ ] Dùng một Facebook Page test, không dùng Page production.
- [ ] Kết nối Page qua flow riêng, lấy Page token server-side và kiểm tra expiry.
- [ ] Publish một caption + ảnh test, lưu external ID/URL.
- [ ] Revoke/reconnect hoặc dùng token hết hạn để xác nhận lỗi bounded.
- [ ] Kiểm tra token không xuất hiện trong response, log hoặc frontend bundle.

## Payload kiểm tra

Caption test dùng cùng một string cho mọi target:

```json
{
  "caption": "Mẫu thiệp cưới hoa sen tối giản, giữ lại những ngày tháng quan trọng cho ngày trọng đại.",
  "targets": [{"platform": "facebook", "social_account_id": "<test-page>"}],
  "asset": "<test-image-url>"
}
```

Expected: một publication cho Page test, external ID được lưu, không có field
`platform_caption`, retry cùng idempotency key không tạo bài thứ hai.

## Giới hạn

MP-00 chưa tạo migration/model/route publish. Những bước đó thuộc MP-01 và
MP-06; smoke test thật chỉ chạy sau khi publisher Facebook được triển khai.
