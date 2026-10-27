# AI Task Queue Popup

**Cập nhật:** 09/10/2026

**Trạng thái:** DONE giai đoạn 2 — tracker dùng chung cho nhiều worker/model

## Mục tiêu

Cho phép người dùng thêm nhiều văn phong liên tiếp trong khi worker đang phân tích. Mỗi lần thêm tạo một analysis/job riêng; một worker xử lý tuần tự, còn giao diện hiển thị toàn bộ hàng đợi ở popup cố định góc phải dưới.

Worker và database queue đã hỗ trợ nhiều job. Phần cần bổ sung chủ yếu là cách theo dõi queue trong giao diện và bỏ trạng thái khóa toàn bộ form Add sau khi job đầu tiên đã được enqueue.

## Phạm vi triển khai

### 1. Giao diện Add văn phong

- Chỉ disable nút gửi trong lúc request tạo analysis đang chạy để chống bấm trùng.
- Khi API trả về `queued`, mở lại form ngay để thêm văn phong tiếp theo.
- Không khóa form trong suốt thời gian worker xử lý.
- Sau khi tạo thành công, thêm analysis ID vào trung tâm tác vụ AI.

### 2. API danh sách tác vụ

- Bổ sung endpoint danh sách analysis của user hiện tại, có phân trang và lọc `queued`, `running`, `processing`, `analyzing`, `ready`, `failed`, `cancelled`, `expired`.
- Chỉ trả metadata cần cho popup: ID, tên, trạng thái, thời gian tạo/bắt đầu/kết thúc, lỗi an toàn và thời điểm hết hạn.
- Endpoint summary không trả `reference_text`, snapshot kết nối hoặc secret; endpoint detail của đúng owner trả lại nguồn đã gửi để mở trang edit.
- Giữ quyền truy cập theo user/role hiện có; không để user xem analysis của người khác.

### 3. Popup `AI Task Queue`

- Gắn ở Admin layout để chuyển trang vẫn nhìn thấy.
- Vị trí mặc định: góc phải dưới, nằm phía trên nút `ScrollToTop`; có thể thu gọn thành nút hiển thị số tác vụ đang chờ/chạy.
- Nút tròn được giữ lại khi popup mở để người dùng có thể chuyển nhanh giữa trạng thái mở và ẩn.
- Badge trên nút tròn hiển thị số task còn `queued`; danh sách task tự cuộn trong phần chiều cao còn lại của popup và chạm footer.
- Hiển thị mỗi tác vụ: tên văn phong, trạng thái, thời gian và thông báo lỗi nếu có.
- Có nút mở kết quả khi `ready`, xem lỗi khi `failed` và hủy khi trạng thái còn cho phép.
- Polling ngắn trong lúc có tác vụ `queued`/`running`/`processing`/`analyzing`; dừng polling khi không còn tác vụ đang chạy, kể cả task `expired`.
- Tải lại trang vẫn khôi phục danh sách từ API, không phụ thuộc riêng vào state trong trình duyệt.

### 4. Chuẩn bị mở rộng

- Dùng kiểu dữ liệu có `task_type`/`source` để sau này hiển thị tạo bài, tạo ảnh và job AI khác trong cùng popup.
- Giai đoạn đầu chỉ nối `AiWritingProfileAnalysis`; không thay đổi cách worker chạy hoặc tăng số worker.

### 5. Task mở rộng: theo dõi worker/model dùng chung (DONE — 09/10/2026)

**Mục tiêu:** đưa tiến trình của các worker AI khác vào cùng popup mà không làm mất dữ liệu nghiệp vụ riêng của từng module.

**Phạm vi cần triển khai:**

- Tạo bảng theo dõi chung `ai_task_runs` với UUID, `task_type`, `source`, `model`, `provider`, `status`, `progress`, `user_id`, `job_id`, lỗi an toàn và các mốc thời gian.
- Tạo contract/service để mỗi Job đăng ký, cập nhật và kết thúc một task; `AiWritingProfileAnalysis` giữ quan hệ tương thích với bản ghi theo dõi chung.
- Bổ sung API list/detail/cancel dùng chung, có scope theo user, filter `task_type`/`status` và pagination.
- Mở rộng `useAiTaskQueue` và `AiTaskQueuePopup` để hiển thị nhiều loại task, nhãn model/source và hành động phù hợp theo loại task.
- Backfill các analysis hiện có sang bảng chung; không đưa secret, reference text hoặc payload nhạy cảm vào popup.
- Giữ các bảng kết quả riêng như `ai_writing_profile_analyses`, bảng bài viết hoặc bảng ảnh làm nguồn dữ liệu nghiệp vụ.

**Tiêu chí nghiệm thu:**

- Một popup hiển thị đồng thời ít nhất writing profile, tạo bài và tạo ảnh với trạng thái độc lập.
- Task mới vẫn xuất hiện sau refresh/chuyển route và task terminal dừng polling.
- User A không đọc hoặc hủy được task của User B.
- Hủy một task không làm thay đổi kết quả của task khác trong cùng queue.
- Test backend kiểm tra đăng ký/cập nhật/scope/idempotency; test frontend kiểm tra normalize, filter, polling và hành động theo `task_type`.

**Không thuộc task này:** thay đổi concurrency, thay provider AI, retry tự động hoặc xóa các bảng nghiệp vụ hiện có.

### 6. Edit và draft sau khi worker hoàn tất

- Khi analysis chuyển `ready`, worker tạo một profile `draft` với rules, evidence và hướng dẫn đã validate.
- Popup mở `/ai/prompt/edit?analysis=<UUID>&profile=<ID>`; trang dùng lại form Add, khôi phục tên, model, URL và nội dung nguồn từ analysis detail.
- Nút Edit trong Ai Prompt/List cũng điều hướng tới trang Edit (profile thủ công dùng `?profile=<ID>`), không mở dialog chỉnh sửa tại List.
- Profile nháp không xuất hiện trong options dùng để viết bài cho đến khi người dùng lưu; thao tác lưu chuyển profile sang `active`.

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

## Kết quả triển khai

- API `GET /api/admin/ai/writing-profiles/analyses` trả summary có pagination, filter status và scope theo user; không trả `reference_text`, connection snapshot, result hoặc secret.
- `AiTaskQueuePopup` được gắn ở Admin layout, giữ popup khi chuyển route, hỗ trợ thu gọn/ẩn, filter, mở kết quả ready, xem lỗi failed và hủy task active.
- `useAiTaskQueue` khôi phục danh sách qua API sau refresh, polling tuần tự khi còn `queued`/`analyzing` và dừng ở trạng thái terminal.
- Add văn phong chỉ khóa submit trong lúc POST; khi nhận `queued`, form được reset để thêm task tiếp theo và task mới được đưa vào popup.
- Worker tạo profile nháp idempotent khi analysis ready; list hiển thị chip `Bản nháp`, trang edit lưu bằng optimistic version.
- Khi queue nhận trạng thái `ready`, List tự tải lại để profile nháp mới xuất hiện ngay mà không cần F5.
- Kiểm chứng: backend `AiWritingProfilesApiTest` 11 tests/131 assertions; frontend queue/API/flow/list/dialogs 52 tests; ESLint scoped và Vite build đạt.
- Có command một lần `ai:sync-writing-profile-drafts` để bù các analysis ready cũ thiếu draft; luồng worker mới đã tạo draft trong cùng transaction.
- Bổ sung `ai_task_runs` làm projection lifecycle dùng chung; bảng nghiệp vụ vẫn là nguồn chuẩn và migration backfill analysis/import hiện có.
- Tách `AiTaskRunAdapter` và `AiTaskRunRegistry`: model/worker mới chỉ cần thêm adapter vào `config/ai/task-runs.php`, không phải sửa service/popup/API theo từng model.
- API dùng chung: `GET /api/admin/ai/tasks`, `GET /api/admin/ai/tasks/{taskRun}`, `POST /api/admin/ai/tasks/{taskRun}/cancel`, có scope owner/quyền module, filter và pagination.
- Worker hiện tại đồng bộ tracker sau khi queue, bắt đầu/kết thúc/hủy; projection chỉ chứa metadata allowlist và lỗi generic.
- Adapter import chuẩn hóa trạng thái legacy `completed`/`succeeded` thành `ready`, đồng thời reconcile run quá hạn thành `expired` để popup không poll vô hạn.
- Kiểm chứng giai đoạn 2: `AiTaskRunsApiTest` 2 tests/10 assertions; `AiTask2RunApiTest` 15 tests; `AiWritingProfilesApiTest` 11 tests; nhóm content/image 20 tests/295 assertions; frontend queue/API/flow/list/dialogs 41 tests; ESLint scoped và Vite build đạt.
- Cập nhật UI sau nghiệm thu: task mới không tự mở popup, chỉ cập nhật badge; event đến trước lúc popup mount được replay; row có icon theo mode; vùng list dùng hết chiều cao và chạm footer.
- Ai Content dùng cùng tracker với writing profile/image: sau POST thành công form reset ngay để nhập nguồn tiếp theo, task cũ được theo dõi độc lập.
- Quality grounding bổ sung đối chiếu số viết bằng chữ Anh/Việt trong ngữ cảnh nội dung; code/link và số liệu bị thay đổi thật vẫn bị chặn.
