# AI Task Queue Popup

**Cập nhật:** 08/10/2026

**Trạng thái:** TODO — plan bên lề, ưu tiên triển khai buổi tối

## Mục tiêu

Cho phép người dùng thêm nhiều văn phong liên tiếp trong khi worker đang phân tích. Mỗi lần thêm tạo một analysis/job riêng; một worker xử lý tuần tự, còn giao diện hiển thị toàn bộ hàng đợi ở popup cố định góc trái dưới.

Worker và database queue đã hỗ trợ nhiều job. Phần cần bổ sung chủ yếu là cách theo dõi queue trong giao diện và bỏ trạng thái khóa toàn bộ form Add sau khi job đầu tiên đã được enqueue.

## Phạm vi triển khai

### 1. Giao diện Add văn phong

- Chỉ disable nút gửi trong lúc request tạo analysis đang chạy để chống bấm trùng.
- Khi API trả về `queued`, mở lại form ngay để thêm văn phong tiếp theo.
- Không khóa form trong suốt thời gian worker xử lý.
- Sau khi tạo thành công, thêm analysis ID vào trung tâm tác vụ AI.

### 2. API danh sách tác vụ

- Bổ sung endpoint danh sách analysis của user hiện tại, có phân trang và lọc `queued`, `analyzing`, `ready`, `failed`, `cancelled`.
- Chỉ trả metadata cần cho popup: ID, tên, trạng thái, thời gian tạo/bắt đầu/kết thúc, lỗi an toàn và thời điểm hết hạn.
- Không trả `reference_text`, snapshot kết nối hoặc secret.
- Giữ quyền truy cập theo user/role hiện có; không để user xem analysis của người khác.

### 3. Popup `AI Task Queue`

- Gắn ở Admin layout để chuyển trang vẫn nhìn thấy.
- Vị trí mặc định: góc trái dưới; có thể thu gọn thành nút hiển thị số tác vụ đang chờ/chạy.
- Hiển thị mỗi tác vụ: tên văn phong, trạng thái, thời gian và thông báo lỗi nếu có.
- Có nút mở kết quả khi `ready`, xem lỗi khi `failed` và hủy khi trạng thái còn cho phép.
- Polling ngắn trong lúc có tác vụ `queued`/`analyzing`; dừng polling khi không còn tác vụ đang chạy.
- Tải lại trang vẫn khôi phục danh sách từ API, không phụ thuộc riêng vào state trong trình duyệt.

### 4. Chuẩn bị mở rộng

- Dùng kiểu dữ liệu có `task_type`/`source` để sau này hiển thị tạo bài, tạo ảnh và job AI khác trong cùng popup.
- Giai đoạn đầu chỉ nối `AiWritingProfileAnalysis`; không thay đổi cách worker chạy hoặc tăng số worker.

## Tiêu chí nghiệm thu

- Thêm liên tiếp ít nhất ba văn phong khi chỉ chạy một worker; cả ba đều được lưu `queued` và lần lượt chuyển sang trạng thái cuối.
- Add văn phong thứ hai không bị chặn khi văn phong thứ nhất đang `analyzing`.
- Popup hiển thị đúng thứ tự/thời gian và trạng thái sau khi refresh trang hoặc chuyển route.
- Tác vụ `ready`, `failed`, `cancelled` không tiếp tục bị polling.
- User A không xem được tác vụ của user B.
- Test backend cho list/filter/scope và test frontend cho service, polling, trạng thái rỗng/lỗi.

## Không thuộc plan này

- Thay đổi concurrency hoặc chia worker riêng cho từng văn phong.
- Chấm điểm bài AI, báo cáo định kỳ và cổng duyệt bài.
- Lưu vĩnh viễn các analysis văn phong đã hết hạn.
- Retry tự động job phân tích; nếu cần sẽ mở task riêng.

## Thứ tự làm nhanh

1. Thêm API list và test scope/pagination.
2. Sửa Add flow để chỉ khóa submit trong request.
3. Tạo service/composable lấy danh sách và polling trạng thái.
4. Tạo popup ở Admin layout, nối mở kết quả/hủy.
5. Chạy test frontend/backend và thử thực tế với ba văn phong cùng một worker.
