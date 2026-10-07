# Settings — chuẩn hóa 10 tab, 2026-10-06

## Phạm vi

- Trang Settings dùng JavaScript Composition API, Vuetify/Vuexy và màu alert chung.
- Tổng quan, Media, SEO, Email, Ngôn ngữ, Bảo mật đọc/lưu typed Settings trong bảng `settings`.
- AI & Content giữ writer/catalog hiện có, thêm mẫu văn phong mặc định và kiểm version.
- Cron đọc registry dùng chung với scheduler; System Info đọc runtime thật.
- Webhooks là empty state có capability chưa hỗ trợ. Không tạo CRUD hoặc delivery giả.
- Bỏ tùy chọn không có consumer: upload logo/favicon, GA/GSC, CAPTCHA, 2FA, xóa ảnh gốc.

## Kiểm chứng

- Backend regression: 64 tests / 558 assertions đã qua trước sửa registry HTTP; lượt cuối 8 Settings tests / 77 assertions đạt, gồm HTTP không boot Artisan, registry không đăng ký trùng và public canonical/contact.
- Frontend: 4 files / 32 tests đạt, gồm dirty từng nhóm, SMTP write-only, version/409, stale response, locale runtime và mẫu văn phong.
- Scoped ESLint/Stylelint, Laravel Pint và production build đạt; build có cảnh báo asset/chunk hiện có của template.
- Cua browser: tài khoản/dữ liệu thử riêng tại `127.0.0.1:8001`; không gửi email, webhook hoặc gọi model thật.
- Đủ 10 tab trên desktop sáng/tối, và mobile 390 × 844; bộ chọn mobile mở đúng tab, không tràn ngang.
- Panel trái tăng mỗi dòng lên 52px, chữ body-1 15px và icon 22px theo giao diện project; đã đo đủ 10 dòng, nhãn không bị cắt và chuyển tab đúng. Scoped ESLint đạt sau chỉnh kích thước.
- Tổng quan lưu/tải lại thật; draft giữ khi đổi tab. Dialog hoàn tác: hủy giữ draft, đồng ý khôi phục bản đã lưu.
- Thông báo lưu thành công đã xác nhận sau khi điền/lưu Email liên hệ trên fixture. Browser không có console error; vẫn có cảnh báo thiếu key dịch navigation từ template.
- Cron web đã phát hiện danh sách rỗng do lifecycle Artisan, đã sửa dùng `ProjectScheduleRegistry` chung và xác nhận hiển thị hai tác vụ cleanup.
- Secret SMTP không xuất hiện trong response/audit hoặc storage trình duyệt; encryption và mail test được kiểm bằng fake trong backend tests.
- Hai migration thêm nhóm Settings/quyền đã áp dụng local; dữ liệu AI/legacy hiện có được giữ.

## Bằng chứng

- [Desktop](settings-desktop.png)
- [Email dark](email-dark.png)
- [Media mobile](media-mobile.png)

## Giới hạn hiện tại

- Chưa có webhook subscriber/delivery/history, lịch sử scheduler hoặc heartbeat worker.
- Logo/favicon, GA/GSC, CAPTCHA, 2FA cần triển khai riêng trước khi có field bật/tắt.
- Locale chỉ en/fr/ar có file dịch; trang quản trị tùy chỉnh vẫn dùng tiếng Việt.
- Mail Settings dùng `ProjectMailService` tại boundary gửi email thử; project chưa có luồng mail nghiệp vụ khác. Tích hợp sau phải dùng cùng service/config.
- Kiểm thiếu quyền/validation/conflict/timeout/empty state ở test tự động; không giả lập tất cả lỗi API trong browser.
- Chưa kiểm SMTP thật hoặc môi trường VPS.
