# Hai bộ chấm độc lập

Mỗi người mở một file reviewer-N.html, nhập tên/mã riêng rồi đọc nguồn và brief. Không xem summary, report hoặc blind-key trước khi chốt điểm/preference. Có thể mở trực tiếp file; giữ review.js/review.css cùng thư mục.

1. Lập dữ kiện quan trọng từ source blocks, ghi mã đoạn và điều kiện. Không lấy ledger Analyze làm đáp án.
2. Đọc X/Y, ghi lỗi critical/major/minor, coverage và checklist code/link/table/quote/ảnh. URL/alt/chú thích không chứng minh đã nhìn pixel ảnh.
3. Chấm 1–5 theo rubric ở docs/quality/AI_ARTICLE_QUALITY_EVALUATION.md. 1 kém, 3 dùng được sau sửa, 5 tốt; 2/4 là mức giữa.
4. Ghi phút sửa thực tế và số sửa; không suy công sửa từ tokens/latency/diff. Chọn bài ưu tiên sau khi đọc cả hai.
5. Chỉ đánh dấu Hoàn thành khi đủ dữ kiện và điểm. Tải CSV để giữ bản chắc chắn; bản nháp lưu riêng theo bộ chấm/trình duyệt khi hỗ trợ.

Hai người chấm trước khi trao đổi. CSV trống là chưa chấm, không phải 0; không chỉnh CSV của người kia. Sau đó đưa hai CSV vào scripts/ai-quality/report.php --reviewer-1=PATH --reviewer-2=PATH --output=FRESH_REPORT_DIR. Tool không gọi AI hoặc đổi cấu hình. Bất đồng fact cần đối chiếu evidence và owner quyết định rollout.
