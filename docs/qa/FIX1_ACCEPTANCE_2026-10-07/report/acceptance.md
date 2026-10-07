# Điều kiện nghiệm thu AI Content

**Trạng thái:** criteria_not_met

| Điều kiện | Kết quả | Hiện tại | Yêu cầu |
| --- | --- | --- | --- |
| Tiêu chí được chủ dự án xác nhận | pending | "proposed" | "approved + approved_by" |
| Số ca đã freeze | passed | 20 | 20 |
| Số nguồn độc lập | passed | 20 | 20 |
| Coverage ngôn ngữ nguồn | passed | ["en","vi"] | ["vi","en"] |
| Khoảng trống coverage cần xác nhận phạm vi | failed | ["Nguồn web mới là trích đoạn được chọn, không mặc định là toàn bài.","Facts quan trọng cần hai người đọc xác nhận độc lập từ nguồn.","Ảnh nguồn chỉ là metadata/chú thích: chưa kiểm pixel hoặc regenerate với asset được duyệt.","Chưa có benchmark độc lập; bảng giới hạn dịch vụ không đại diện benchmark."] | [] |
| Tỷ lệ run C hoàn tất trong study gốc | failed | 0.7 | 1 |
| Hai người chấm độc lập hoàn thành mọi ca C | pending | {"reviewer-1":{"completed":0,"available":19,"reviewer":null},"reviewer-2":{"completed":0,"available":19,"reviewer":null}} | 40 |
| Bất đồng facts được giải quyết bằng dẫn chứng | pending | [] | [] |
| Số lỗi critical_errors lớn nhất mỗi bài | pending | null | 0 |
| Số lỗi major_errors lớn nhất mỗi bài | pending | null | 0 |
| Mọi bài đạt accuracy do người đọc xác nhận | pending | 0 | 40 |
| Điểm trung vị naturalness_1_5 | pending | null | 3 |
| Điểm trung vị usefulness_1_5 | pending | null | 3 |
| Điểm trung vị structure_1_5 | pending | null | 3 |
| Điểm trung vị repetition_1_5 | pending | null | 3 |
| Điểm trung vị brief_style_1_5 | pending | null | 3 |
| Phút chỉnh sửa trung vị | pending | null | "Ghi nhận, chưa chốt giới hạn" |

Điểm trống tiếp tục chờ người đọc. Kết quả qua gate không thay accuracy hoặc chất lượng văn phong. Ready for owner decision không tự Apply/Publish hoặc phê duyệt rollout. Không có baseline mới để kết luận công sửa đã giảm.
