# Báo cáo chất lượng bài · corpus v2

**1 ca / 1 nguồn khác nhau.** Phiên chỉ có C: Analyze → Write → Edit. Baseline A chưa phục hồi.

Gate kỹ thuật chỉ ghi kết quả pipeline. Độ chính xác, tiếng Việt tự nhiên và công chỉnh sửa do hai người đọc chấm riêng; ô trống chưa phải điểm 0.

| Nhánh | Ready / tổng | Failed | Lượt gọi | Token tổng | Token trung vị | Thời gian trung vị |
| --- | --- | --- | --- | --- | --- | --- |
| C | 0 / 1 | 1 | 3 | 37.608 | 37.608 | 321,6 giây |

Chi phí tiền chưa có bảng giá xác nhận nên để trống. Token/thời gian bao gồm run bị chặn; dữ liệu usage thiếu được báo riêng, không thay bằng 0.

C: nhà cung cấp đã báo 37.608 token từ 3 / 3 call; 0 call chưa có usage. Trung vị token tính trên 1 / 1 run có tổng usage đầy đủ.

| Ca | Nhóm | C | Token | Thời gian (giây) |
| --- | --- | --- | --- | --- |
| Q33 | Bảng số liệu · giới hạn GitHub Actions theo gói | failed · AI_QUALITY_GROUNDING | 37.608 | 321,6 |

**Chấm người: pending_human.**

- reviewer-1: 0 / 1 bài đã hoàn thành.
- reviewer-2: 0 / 1 bài đã hoàn thành.

Chưa kết luận chất lượng diễn đạt, độ chính xác hoặc công sửa của C. Hai form chấm độc lập ở `review-v2/reviewer-1.html` và `review-v2/reviewer-2.html`; xem hướng dẫn ở README của bộ chấm.

Ảnh chỉ được kiểm metadata/chú thích từ nguồn; chưa có đánh giá pixel hoặc thử regenerate với MediaAsset được duyệt. Không tự tải hay gán ảnh nguồn vào bài. Các trích đoạn web mới không đại diện toàn bài dài.

Rollout: pending_human_reviews_and_owner_criteria. Thử nghiệm không Apply/Publish, không đổi Settings/default.
