/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dữ liệu minh họa và thống kê nguồn cho giao diện Ai Prompt.
 * =====================================================================
 * Giữ nội dung mẫu người dùng cung cấp để custom giao diện; không giả kết quả API.
 * CÁC HÀM/METHOD TRONG FILE:
 * - getSourceMetrics(html): đếm nội dung HTML hiện có bằng DOM parser.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML nguồn được nhập trong editor và dữ liệu mẫu của trang.
 * - OUTPUT: fixture hiển thị, số từ/đoạn/ảnh/link và thời gian đọc ước tính.
 * - SIDE EFFECT: không gọi API/model, không lưu database hoặc render HTML thô.
 * =====================================================================
 */

export const promptPreviewSource = `<h2>Laravel 11 mang đến những tính năng mới gì?</h2>
<p>Laravel 11 là phiên bản mới nhất của framework PHP phổ biến, mang đến nhiều cải tiến đáng chú ý giúp lập trình viên xây dựng ứng dụng nhanh hơn, hiệu quả hơn và bảo mật hơn. Trong bài viết này, chúng ta sẽ cùng tìm hiểu những tính năng nổi bật nhất của Laravel 11.</p>
<h3>1. Hiệu suất được cải thiện</h3>
<p>Laravel 11 tập trung vào việc tối ưu hiệu suất, bao gồm thời gian khởi động nhanh hơn và cải thiện khả năng tự động tải class. Những thay đổi này giúp ứng dụng của bạn chạy mượt mà hơn, đặc biệt là ở quy mô lớn.</p>`

export const promptPreviewReport = {
  topic: 'Công nghệ',
  summary: 'Bài viết giới thiệu các tính năng mới trong Laravel 11, tập trung vào hiệu suất, công cụ phát triển, bảo mật và trải nghiệm lập trình viên. Nội dung phù hợp với đối tượng lập trình viên PHP/Laravel, cung cấp thông tin hữu ích và thực tế.',
  topics: ['Laravel 11', 'Hiệu suất', 'Tính năng mới', 'Bảo mật', 'Công cụ phát triển', 'Lập trình PHP'],
  strengths: [
    'Nội dung rõ ràng, mạch lạc',
    'Cung cấp ví dụ cụ thể',
    'Phù hợp với đối tượng mục tiêu',
    'Cập nhật các tính năng mới',
    'Có cấu trúc tốt (H2, H3)',
  ],
  improvements: [
    'Có thể thêm số liệu thực tế để tăng độ tin cậy',
    'Nên bổ sung hình ảnh minh họa',
    'Thêm liên kết tham khảo chính thức',
    'Tăng tính độc đáo, tránh trùng lặp nội dung',
    'Có thể mở rộng phần so sánh với phiên bản cũ',
  ],
  details: {
    style: {
      title: 'Giọng văn và cách diễn đạt',
      icon: 'tabler-feather',
      description: 'Giọng văn trung tính, thiên về giải thích và dùng thuật ngữ quen thuộc với lập trình viên. Câu ngắn kết hợp với câu mô tả giúp người đọc theo dõi nội dung.',
      items: ['Mở đầu bằng giới thiệu chủ đề', 'Từ ngữ rõ ràng, dễ tiếp cận', 'Có thể tăng tính tự nhiên bằng ví dụ gắn với tình huống cụ thể'],
    },
    structure: {
      title: 'Bố cục bài tham khảo',
      icon: 'tabler-layout-list',
      description: 'Mẫu trình bày dùng phần giới thiệu và các tiêu đề nhỏ để dẫn người đọc qua từng nội dung. Bố cục của bài mới cần được điều chỉnh theo nguồn và yêu cầu viết.',
      items: ['Giới thiệu chủ đề và lợi ích cho người đọc', 'Chia nội dung theo từng ý chính', 'Giữ các đoạn giải thích tập trung vào một ý'],
    },
    seo: {
      title: 'SEO và từ khóa',
      icon: 'tabler-search',
      description: 'Phần minh họa thể hiện cách trình bày nhận xét SEO và từ khóa khi có kết quả phân tích. Các nhận xét hiện tại chưa được tạo từ model.',
      items: ['Chủ đề tập trung vào Laravel 11 và lập trình PHP', 'Tiêu đề nên diễn đạt rõ nội dung bài', 'Có thể bổ sung liên kết tham khảo phù hợp'],
    },
    images: {
      title: 'Hình ảnh và cách minh họa',
      icon: 'tabler-photo',
      description: 'Nguồn mẫu hiện tại chưa có ảnh. Khu vực này dành cho các nhận xét về cách sử dụng hình minh họa khi nối dữ liệu phân tích.',
      items: ['Ưu tiên hình giải thích đúng nội dung', 'Ảnh và chú thích cần gắn với đoạn liên quan', 'Chọn ảnh trong MediaLibrary khi biên tập bài mới'],
    },
    uniqueness: {
      title: 'Cách diễn đạt và tính độc đáo',
      icon: 'tabler-fingerprint',
      description: 'Đây là nhận xét minh họa về cách viết, chưa phải kết quả kiểm tra đạo văn hoặc so sánh với các bài trên Internet.',
      items: ['Giảm câu giới thiệu chung chung', 'Dùng góc giải thích phù hợp đối tượng đọc', 'Tránh lặp lại cùng một ý trong mở bài và kết bài'],
    },
  },
  score: {
    total: 78,
    label: 'Tốt',
    checks: [
      { text: 'Nội dung đầy đủ, rõ ràng', color: 'success', icon: 'tabler-circle-check' },
      { text: 'Cấu trúc hợp lý (H2, H3)', color: 'success', icon: 'tabler-circle-check' },
      { text: 'Văn phong dễ hiểu, tự nhiên', color: 'success', icon: 'tabler-circle-check' },
      { text: 'Có ví dụ minh họa', color: 'success', icon: 'tabler-circle-check' },
      { text: 'Tối ưu SEO tương đối tốt', color: 'warning', icon: 'tabler-alert-circle' },
      { text: 'Có thể cải thiện tính độc đáo', color: 'warning', icon: 'tabler-alert-circle' },
      { text: 'Nên thêm hình ảnh và liên kết tham khảo', color: 'warning', icon: 'tabler-alert-circle' },
    ],
    metrics: [
      { title: 'Nội dung', value: 82, color: 'primary', icon: 'tabler-file-text' },
      { title: 'Văn phong', value: 76, color: 'info', icon: 'tabler-feather' },
      { title: 'SEO', value: 70, color: 'success', icon: 'tabler-search' },
    ],
  },
  styleTone: 'Trung tính',
  readability: 'Dễ đọc',
  styleAnalysis: [
    { name: 'Tính chuyên môn', percent: 70, color: 'primary', desc: 'Cân bằng giữa kỹ thuật và dễ hiểu' },
    { name: 'Tính tự nhiên', percent: 85, color: 'primary', desc: 'Văn phong tự nhiên, mạch lạc' },
    { name: 'Tính thuyết phục', percent: 75, color: 'info', desc: 'Có lập luận, ví dụ cụ thể' },
    { name: 'Độ phức tạp', percent: 40, color: 'warning', desc: 'Dễ đọc, phù hợp nhiều đối tượng' },
    { name: 'Cảm xúc', percent: 60, color: 'primary', desc: 'Trung tính, tập trung vào thông tin' },
  ],
  promptText: 'Hãy viết lại bài viết này về các tính năng mới của Laravel 11 bằng văn phong tự nhiên, hấp dẫn, chuẩn SEO, có cấu trúc rõ ràng với các tiêu đề H2, H3. Bổ sung thêm ví dụ thực tế, số liệu (nếu có), hình ảnh minh họa và liên kết tham khảo chính thức. Đối tượng là lập trình viên PHP/Laravel, nội dung khoảng 1200 - 1500 từ, viết bằng tiếng Việt.',
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tính thống kê nguồn hiện tại để không dùng số đếm minh họa cố định.
 * =====================================================================
 * Input: chuỗi HTML từ editor; DOMParser có sẵn trong trình duyệt.
 * Output: số từ, đoạn có nội dung, ảnh, liên kết và phút đọc ước tính.
 * SIDE EFFECT: DOM tách rời, không thực thi script hoặc chèn HTML vào trang.
 * =====================================================================
 */
export function getSourceMetrics(html) {
  const document = new DOMParser().parseFromString(html, 'text/html')

  document.querySelectorAll('script, style, template, noscript').forEach(element => element.remove())

  // Giữ ranh giới từ giữa các block dù TinyMCE xuất HTML không có xuống dòng.
  document.querySelectorAll('p, h1, h2, h3, h4, h5, h6, li, td, th, blockquote, div, br').forEach(element => element.after(document.createTextNode(' ')))

  const text = (document.body.textContent ?? '').trim()
  const wordCount = text ? text.split(/\s+/u).length : 0

  return {
    wordCount,
    paragraphCount: Array.from(document.querySelectorAll('p')).filter(paragraph => paragraph.textContent?.trim()).length,
    imageCount: document.querySelectorAll('img').length,
    linkCount: document.querySelectorAll('a[href]').length,
    readingMinutes: wordCount ? Math.ceil(wordCount / 200) : 0,
  }
}
