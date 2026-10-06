/**
 * Danh mục tab/field UI; giá trị và options nghiệp vụ thuộc API.
 * Input: DTO options. Output: mô tả form; không ghi hoặc giữ dữ liệu mẫu.
 */
export const settingsTabs = [
  { id: 'general', group: 'site', title: 'Tổng quan', icon: 'tabler-settings', description: 'Thông tin website public và múi giờ hiển thị.' },
  { id: 'ai-content', title: 'AI & Content', icon: 'tabler-sparkles', description: 'Mặc định cho tác vụ nội dung và thumbnail Post trong luồng AI Content.' },
  { id: 'media', group: 'media', title: 'Media', icon: 'tabler-photo', description: 'Giới hạn upload và ảnh chuyển đổi dùng chung cho thư viện Media.' },
  { id: 'seo', group: 'seo', title: 'SEO', icon: 'tabler-search', description: 'Metadata mặc định và robots.txt của website public.' },
  { id: 'email', group: 'mail', title: 'Email', icon: 'tabler-mail', description: 'Cấu hình gửi mail, người gửi và kiểm tra cấu hình đã lưu.' },
  { id: 'cron', title: 'Tác vụ định kỳ', icon: 'tabler-clock', description: 'Lịch thực tế do scheduler của project đăng ký.' },
  { id: 'webhooks', title: 'Webhooks', icon: 'tabler-webhook', description: 'Khả năng tích hợp sự kiện với hệ thống bên ngoài.' },
  { id: 'languages', group: 'languages', title: 'Ngôn ngữ', icon: 'tabler-language', description: 'Ngôn ngữ giao diện đã có bản dịch trong project.' },
  { id: 'security', group: 'security', title: 'Bảo mật', icon: 'tabler-shield-check', description: 'Thời hạn token và giới hạn đăng nhập của trang quản trị.' },
  { id: 'system-info', title: 'Thông tin hệ thống', icon: 'tabler-info-circle', description: 'Thông số được đọc từ môi trường đang chạy.' },
]

/** Input: group/options. Output: field allowlist; chỉ UI hints, không default dữ liệu. */
export function settingsFields(group, options = {}) {
  const fields = {
    site: [
      { key: 'site_name', label: 'Tên website', max: 120 },
      { key: 'site_url', label: 'URL website public', hint: 'Dùng cho thông tin website; không thay địa chỉ đăng nhập hay kết nối ứng dụng.' },
      { key: 'site_description', label: 'Mô tả website', type: 'textarea', full: true, max: 1000 },
      { key: 'contact_email', label: 'Email liên hệ', type: 'email' },
      { key: 'timezone', label: 'Múi giờ website', type: 'select', items: options.timezones ?? [] },
    ],
    media: [
      { key: 'image_max_size_kb', label: 'Giới hạn ảnh (KB)', type: 'number' },
      { key: 'document_max_size_kb', label: 'Giới hạn tài liệu (KB)', type: 'number' },
      { key: 'video_max_size_kb', label: 'Giới hạn video (KB)', type: 'number' },
      { key: 'archive_max_size_kb', label: 'Giới hạn file ZIP (KB)', type: 'number' },
      { key: 'allowed_extensions', label: 'Định dạng cho phép', type: 'select', items: options.extensions ?? [], multiple: true, full: true, hint: 'Vẫn kiểm tra MIME và loại file. ZIP luôn được lưu riêng tư.' },
      { key: 'conversion_format', label: 'Định dạng thumbnail Post / Open Graph', type: 'select', items: ['webp', 'jpg', 'png'], hint: 'Áp dụng cho chuyển đổi ảnh mới hoặc thử lại; giữ file gốc.' },
      { key: 'conversion_quality', label: 'Chất lượng ảnh chuyển đổi (1–100)', type: 'number' },
    ],
    seo: [
      { key: 'title_format', label: 'Mẫu tiêu đề SEO', full: true, hint: 'Bắt buộc có %title%. Dùng %sitename% cho tên website. SEO riêng của bài viết được ưu tiên.' },
      { key: 'default_description', label: 'Mô tả SEO mặc định', type: 'textarea', full: true, max: 1000 },
      { key: 'robots_txt', label: 'Nội dung robots.txt', type: 'textarea', full: true, rows: 7, hint: 'Được phục vụ tại /robots.txt. Project hiện chưa có sitemap tự động.' },
    ],
    mail: [
      { key: 'mailer', label: 'Phương thức gửi', type: 'select', items: options.mailers ?? [], hint: 'log/array chỉ ghi nội dung thử, không gửi email. Các driver khác SMTP dùng cấu hình môi trường.' },
      { key: 'from_name', label: 'Tên người gửi' },
      { key: 'from_address', label: 'Email người gửi', type: 'email' },
      { key: 'host', label: 'Máy chủ SMTP', smtp: true },
      { key: 'port', label: 'Cổng SMTP', type: 'number', smtp: true },
      { key: 'scheme', label: 'Kết nối SMTP', type: 'select', items: [{ title: 'SMTP / STARTTLS', value: 'smtp' }, { title: 'SMTPS', value: 'smtps' }], smtp: true },
      { key: 'username', label: 'Tài khoản SMTP', smtp: true },
      { key: 'password', label: 'Mật khẩu SMTP mới', type: 'password', smtp: true, hint: 'Để trống để giữ mật khẩu đã cấu hình. Giá trị được mã hóa và không đọc lại.' },
    ],
    security: [
      { key: 'token_expiration_days', label: 'Thời hạn token quản trị (ngày)', type: 'number', hint: '1–90 ngày. Chỉ áp dụng cho token được cấp từ lần đăng nhập tiếp theo.' },
      { key: 'login_max_attempts', label: 'Số lần đăng nhập tối đa', type: 'number', hint: '1–30 lần cho mỗi địa chỉ IP trong khoảng thời gian bên dưới.' },
      { key: 'login_decay_minutes', label: 'Khoảng giới hạn đăng nhập (phút)', type: 'number', hint: '1–60 phút; bảo vệ cả các yêu cầu đăng nhập thất bại.' },
    ],
  }

  return fields[group] ?? []
}
