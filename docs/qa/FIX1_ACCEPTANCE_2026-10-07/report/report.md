# Báo cáo chất lượng bài · corpus v2

**20 ca / 20 nguồn khác nhau.** Phiên chỉ có C: Analyze → Write → Edit. Baseline A chưa phục hồi.

Gate kỹ thuật chỉ ghi kết quả pipeline. Độ chính xác, tiếng Việt tự nhiên và công chỉnh sửa do hai người đọc chấm riêng; ô trống chưa phải điểm 0.

| Nhánh | Ready / tổng | Failed | Lượt gọi | Token tổng | Token trung vị | Thời gian trung vị |
| --- | --- | --- | --- | --- | --- | --- |
| C | 14 / 20 | 6 | 59 | — | 14.675 | 103,5 giây |

Chi phí tiền chưa có bảng giá xác nhận nên để trống. Token/thời gian bao gồm run bị chặn; dữ liệu usage thiếu được báo riêng, không thay bằng 0.

C: nhà cung cấp đã báo 403.038 token từ 58 / 59 call; 1 call chưa có usage. Trung vị token tính trên 19 / 20 run có tổng usage đầy đủ.

| Ca | Nhóm | C | Token | Thời gian (giây) |
| --- | --- | --- | --- | --- |
| Q01 | Tin thay đổi rất ngắn | ready | 10.357 | 51,5 |
| Q02 | Release và phiên bản | ready | 16.315 | 91,3 |
| Q03 | Migration/breaking changes | ready | 11.935 | 60,8 |
| Q06 | Tutorial CLI | ready | 14.351 | 73,1 |
| Q07 | Cấu hình PHP/Laravel | ready | 21.158 | 87,5 |
| Q08 | JavaScript/TypeScript | ready | 45.657 | 301,0 |
| Q09 | Async/watchers | ready | 47.887 | 295,9 |
| Q12 | So sánh trade-off | ready | 21.443 | 171,9 |
| Q13 | Giải thích cho người mới | ready | 22.343 | 182,8 |
| Q14 | Chuyên sâu | ready | 23.112 | 173,3 |
| Q17 | Nhiều code/ít prose | ready | 13.010 | 75,4 |
| Q18 | HTML có navigation/footer/ads | ready | 46.142 | 383,7 |
| Q26 | Tin tiếng Việt · Metro khai trương và khánh thành | failed · AI_QUALITY_GROUNDING | 14.675 | 96,0 |
| Q27 | Tin tiếng Việt · Năm Du lịch Huế 2025 | failed · AI_QUALITY_GROUNDING | 14.294 | 91,0 |
| Q28 | Tin tiếng Việt · Lễ hội cà phê dự kiến | failed · AI_QUALITY_GROUNDING | 15.199 | 102,6 |
| Q29 | Thống kê du lịch tiếng Việt · kỳ báo cáo 2024 | ready | 13.312 | 97,1 |
| Q30 | Thông báo tiếng Việt · điều kiện vé tham quan năm 2010 | failed · AI_QUALITY_GROUNDING | 14.582 | 105,1 |
| Q31 | Du lịch · Phong Nha · Anh sang Việt | ready | 14.259 | 106,9 |
| Q32 | Du lịch văn hóa · Hội An · Anh sang Việt | failed · AI_QUALITY_GROUNDING | 13.345 | 104,4 |
| Q33 | Bảng số liệu · giới hạn GitHub Actions theo gói | failed · AI_PROVIDER_TIMEOUT | — | 304,7 |

**Chấm người: pending_human.**

- reviewer-1: 0 / 19 bài đã hoàn thành.
- reviewer-2: 0 / 19 bài đã hoàn thành.

Chưa kết luận chất lượng diễn đạt, độ chính xác hoặc công sửa của C. Hai form reviewer-1.html và reviewer-2.html ở thư mục bộ chấm đi kèm; xem hướng dẫn ở README của bộ chấm.

Ảnh chỉ được kiểm metadata/chú thích từ nguồn; chưa có đánh giá pixel hoặc thử regenerate với MediaAsset được duyệt. Không tự tải hay gán ảnh nguồn vào bài. Các trích đoạn web mới không đại diện toàn bài dài.

Rollout: pending_human_reviews_and_owner_criteria. Thử nghiệm không Apply/Publish, không đổi Settings/default.
