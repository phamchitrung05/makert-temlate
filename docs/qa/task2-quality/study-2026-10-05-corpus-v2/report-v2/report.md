# Báo cáo so sánh chất lượng bài · corpus v2

**20 ca / 20 nguồn khác nhau; 20 cặp B/C cùng nguồn, model, brief và văn phong.** B = một lượt; C = Analyze → Write → Edit. Baseline A chưa phục hồi.

Gate kỹ thuật chỉ ghi kết quả pipeline. Độ chính xác, tiếng Việt tự nhiên và công chỉnh sửa do hai người đọc chấm riêng; ô trống chưa phải điểm 0.

| Nhánh | Ready / tổng | Failed | Lượt gọi | Token tổng | Token trung vị | Thời gian trung vị |
| --- | --- | --- | --- | --- | --- | --- |
| B | 20 / 20 | 0 | 20 | 58.305 | 2.035 | 20,2 giây |
| C | 14 / 20 | 6 | 59 | — | 14.675 | 103,5 giây |

Chi phí tiền chưa có bảng giá xác nhận nên để trống. Token/thời gian bao gồm run bị chặn; dữ liệu usage thiếu được báo riêng, không thay bằng 0.

| Ca | Nhóm | B | C | Token B / C | Thời gian B / C (giây) |
| --- | --- | --- | --- | --- | --- |
| Q01 | Tin thay đổi rất ngắn | ready | ready | 1.386 / 10.357 | 7,6 / 51,5 |
| Q02 | Release và phiên bản | ready | ready | 2.244 / 16.315 | 16,6 / 91,3 |
| Q03 | Migration/breaking changes | ready | ready | 1.826 / 11.935 | 11,9 / 60,8 |
| Q06 | Tutorial CLI | ready | ready | 2.257 / 14.351 | 17,4 / 73,1 |
| Q07 | Cấu hình PHP/Laravel | ready | ready | 3.464 / 21.158 | 13,1 / 87,5 |
| Q08 | JavaScript/TypeScript | ready | ready | 6.452 / 45.657 | 71,0 / 301,0 |
| Q09 | Async/watchers | ready | ready | 8.363 / 47.887 | 87,9 / 295,9 |
| Q12 | So sánh trade-off | ready | ready | 2.698 / 21.443 | 30,4 / 171,9 |
| Q13 | Giải thích cho người mới | ready | ready | 2.942 / 22.343 | 38,7 / 182,8 |
| Q14 | Chuyên sâu | ready | ready | 2.892 / 23.112 | 42,8 / 173,3 |
| Q17 | Nhiều code/ít prose | ready | ready | 1.740 / 13.010 | 15,2 / 75,4 |
| Q18 | HTML có navigation/footer/ads | ready | ready | 5.010 / 46.142 | 75,6 / 383,7 |
| Q26 | Tin tiếng Việt · Metro khai trương và khánh thành | ready | failed · AI_QUALITY_GROUNDING | 1.793 / 14.675 | 20,9 / 96,0 |
| Q27 | Tin tiếng Việt · Năm Du lịch Huế 2025 | ready | failed · AI_QUALITY_GROUNDING | 1.636 / 14.294 | 19,8 / 91,0 |
| Q28 | Tin tiếng Việt · Lễ hội cà phê dự kiến | ready | failed · AI_QUALITY_GROUNDING | 1.666 / 15.199 | 18,9 / 102,6 |
| Q29 | Thống kê du lịch tiếng Việt · kỳ báo cáo 2024 | ready | ready | 1.521 / 13.312 | 13,8 / 97,1 |
| Q30 | Thông báo tiếng Việt · điều kiện vé tham quan năm 2010 | ready | failed · AI_QUALITY_GROUNDING | 1.702 / 14.582 | 20,7 / 105,1 |
| Q31 | Du lịch · Phong Nha · Anh sang Việt | ready | ready | 1.691 / 14.259 | 24,8 / 106,9 |
| Q32 | Du lịch văn hóa · Hội An · Anh sang Việt | ready | failed · AI_QUALITY_GROUNDING | 1.604 / 13.345 | 19,3 / 104,4 |
| Q33 | Bảng số liệu · giới hạn GitHub Actions theo gói | ready | failed · AI_PROVIDER_TIMEOUT | 5.418 / — | 88,7 / 304,7 |

**Chấm người: pending_human.**

- reviewer-1: 0 / 39 bài đã hoàn thành.
- reviewer-2: 0 / 39 bài đã hoàn thành.

Chưa kết luận nhánh nào viết hay hơn, ít lỗi factual hơn hoặc ít phút sửa hơn. Hai form chấm độc lập ở `review-v2/reviewer-1.html` và `review-v2/reviewer-2.html`; xem hướng dẫn ở README của bộ chấm.

Ảnh chỉ được kiểm metadata/chú thích từ nguồn; chưa có đánh giá pixel hoặc thử regenerate với MediaAsset được duyệt. Không tự tải hay gán ảnh nguồn vào bài. Các trích đoạn web mới không đại diện toàn bài dài.

Rollout: pending_human_reviews_and_owner_criteria. Thử nghiệm không Apply/Publish, không đổi Settings/default.
