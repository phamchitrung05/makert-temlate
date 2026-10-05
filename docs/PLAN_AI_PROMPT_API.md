# Plan nối API Ai Prompt — Task 2 / FIX 1

**Cập nhật:** 2026-10-05. **Trạng thái:** List/Add và reset sau lưu đã có; CRUD mọi mẫu, mẫu mặc định và select/brief ở các form tạo bài đã triển khai theo mục 0.7. Browser localhost phân tích bài thật trả 13 dẫn chứng và lưu mẫu mới thành công. Báo cáo tại [FIX 1 mục 12.24](fix_1.md#1224-hoàn-thiện-triển-khai-và-nghiệm-thu-localhost--2026-10-05) và [QA localhost](qa/TASK2_LOCALHOST_2026-10-05.md). Chất lượng nhận xét vẫn cần người dùng đọc/duyệt; phần chưa API giữ nhãn minh họa.

**Mục tiêu hiện tại:** Ai Prompt có List hiển thị prompt văn phong đã lưu bằng datatable và Add dùng giao diện phân tích/duyệt/lưu hiện tại. List nối API database với tìm kiếm, phân trang và mở prompt; Add giữ bố cục hai cột và theme project.

Plan này nối tiếp [Task 2, mục 12.18 của FIX 1](fix_1.md#1218-plan-nối-api-ai-prompt--2026-10-04). Hợp đồng **đang có** tại [API mẫu văn phong](AI_WRITING_PROFILES_API.md). Những endpoint/field ghi **bổ sung** dưới đây là thiết kế cần triển khai, chưa có trong code.

## 0. Phạm vi thay thế theo yêu cầu mới của người dùng

Người dùng đã chốt: **hoàn tất nối API cho Ai Prompt; phần chưa có API tạm giữ dữ liệu tĩnh; trang danh sách văn phong để sau**. Mục này thay thế phạm vi triển khai của mục 1–8 bên dưới. Các mục 1–8 giữ lại thiết kế ban đầu để tham khảo cho đợt mở rộng, không phải danh sách công việc phải triển khai trong đợt hiện tại.

Yêu cầu nối tiếp **tạo List/Add** đã triển khai tại mục 0.5, thay quyết định để trang danh sách sang đợt sau. Đợt hoàn thiện Task 2 bổ sung CRUD mọi mẫu tại mục 0.7; giới hạn API/minh họa và lịch sử server của Add vẫn áp dụng.

### 0.1 Nối API sẵn có, không mở rộng backend

Không bổ sung ba endpoint WritingProfiles source-preview/history/source, field `source_hash` public hoặc migration/idempotency cho `client_request_id`. Backend API, schema và bảng hiện có giữ nguyên. Client không gửi các field mới này.

| Chức năng hiện tại | Cách triển khai | Trạng thái phạm vi |
| --- | --- | --- |
| Tên văn phong | Nhập tay, bắt buộc, tối đa 160 ký tự; gửi `name` khi phân tích/lưu | Nối thật |
| Catalog/model | GET `/settings/ai`; chọn model có khả năng sinh văn bản hoặc để mặc định | Nối thật |
| Dán nội dung | Tiptap → văn bản giữ ranh giới đoạn → `reference_text` | Xử lý cục bộ, phân tích bằng API |
| File `.txt` | Đọc UTF-8 cục bộ, giới hạn 5 MB và văn bản cuối 30–100000 ký tự | Không cần quyền `posts.manage` |
| URL/file `.html/.htm` | POST `/ai-agent/source-preview` hiện có với target Post; chuyển `content_html` thành văn bản riêng để xem/sửa | Cần thêm `posts.manage` |
| Phân tích | POST `/ai/writing-profiles/analyses`; nhận UUID | Nối thật, một request model |
| Tiến trình/kết quả | GET `/ai/writing-profiles/analyses/{uuid}` tuần tự với backoff; hiển thị summary/rules/evidence/hướng dẫn thật | Nối thật |
| Hủy | POST `/ai/writing-profiles/analyses/{uuid}/cancel` | Nối thật |
| Tiếp tục analysis | UUID gần nhất trong session hoặc UUID nhập thủ công → GET detail | Nối thật; không tự POST |
| Duyệt/lưu mẫu | POST `/ai/writing-profiles` chỉ khi người dùng bấm lưu | Nối thật |
| Sửa mẫu vừa lưu | PUT `/ai/writing-profiles/{id}` với `version`; GET profile khi tải lại version | Nối thật, phạm vi mẫu hiện tại |
| Kiểm tra POST lưu chưa rõ kết quả | GET profile nếu đã biết ID; nếu chưa biết ID, GET list tối đa 100 mẫu theo tên rồi đối chiếu exact name + analysis ID | Chỉ GET, giữ bản sửa; không phải trang danh sách |
| Mẫu mặc định | PUT `/settings/ai/settings` chỉ field `default_writing_profile_id`; báo lỗi riêng nếu mẫu đã lưu nhưng default lỗi | Nối thật |
| Copy/tải báo cáo | Clipboard/Markdown UTF-8 từ kết quả `ready` và bản đã sửa | Thao tác cục bộ thật, không cần API export |
| Lịch sử phân tích dạng danh sách | Chưa có GET list analyses | Giữ minh họa tĩnh và nhãn rõ; không giả danh sách server |
| Điểm, phần trăm văn phong, SEO, ảnh, tính độc đáo và các nhận xét ngoài schema | Backend chưa trả dữ liệu đánh giá tương ứng | Giữ tĩnh, gắn nhãn minh họa ở từng khối/tab |
| Trang List văn phong, search/pagination/xem prompt | GET list đã có; yêu cầu List/Add nối tiếp tại mục 0.5 | Đã triển khai UI bằng dữ liệu thật |
| Chỉnh sửa/xóa mọi mẫu từ danh sách | API CRUD, optimistic version và xác nhận xóa | Đã triển khai tại 0.7 |
| Đọc lại bài mẫu từ analysis | GET detail không trả `reference_text`; chưa có GET source | Để sau; không tự khôi phục hoặc lưu bài vào browser storage |

Tài khoản chỉ có `ai_settings.manage` vẫn dán nội dung/đọc TXT, phân tích, xem/sửa/lưu mẫu hiện tại và đặt mặc định. Nút lấy URL/HTML chỉ bật khi có quyền `posts.manage`; giao diện giải thích giới hạn và cho dùng nguồn dán/TXT. Không nới quyền backend hiện có để phục vụ preview.

### 0.2 Dữ liệu thật và minh họa trên cùng trang

- Kết quả API thật: `summary`, các `rules`, `evidence`, `style_instructions`, trạng thái/model/thời gian và thông báo lỗi. `summary` là tóm tắt **văn phong**, không suy diễn thành tóm tắt nội dung bài.
- Thống kê nguồn cục bộ: số từ/đoạn/thời gian đọc; số ảnh/link đọc được từ HTML nguồn khi có. Văn bản TXT không chứng minh nội dung có hay không có ảnh. Đây là số đếm nguồn, không phải điểm AI đánh giá.
- Phần minh họa: điểm chất lượng, % chuyên môn/tự nhiên/cảm xúc, SEO/ảnh/tính độc đáo, nội dung nhận xét ngoài schema và danh sách lịch sử. Giữ fixture nhưng gắn nhãn **Dữ liệu minh họa — chưa có API**; không gửi những giá trị đó trong payload lưu mẫu hoặc tải báo cáo thật.
- Mẫu `ready` vẫn chưa được lưu. Form duyệt sửa tên/mô tả/quy tắc/hướng dẫn/trạng thái; evidence được trình bày và chỉ gửi phần đã duyệt theo contract backend.

### 0.3 Nguồn, resume và tránh gửi lại ngoài ý muốn

Nguồn URL/file giữ văn bản chuẩn riêng, không đưa HTML đầy đủ qua Tiptap. Đổi tab/URL/File làm mất trạng thái đã đọc; response preview cũ không được ghi đè lựa chọn mới. Dán HTML được chuyển thành text trước khi gửi. Nguồn đã gửi giữ snapshot trong bộ nhớ của lượt phân tích; đổi nguồn sau đó không làm thay dữ liệu của analysis và phải phân tích lại nếu muốn lưu theo nguồn mới.

SessionStorage chỉ nhớ metadata UUID/hạn lưu, ID profile vừa lưu và cờ/tên của POST lưu chưa rõ kết quả theo tài khoản/analysis. Không lưu bài nguồn, form/result lớn, token hoặc dữ liệu kết nối. Sau reload có thể GET lại trạng thái/kết quả bằng UUID nhưng **không khôi phục bài tham khảo**, vì API hiện không trả nguồn. Nhập UUID là resume một analysis đã có, không phải tìm kiếm lịch sử; mở UUID khác yêu cầu xác nhận bỏ form đang sửa chưa lưu.

POST analysis/profile không tự retry. Khi mất kết nối trước khi biết UUID/ID, UI giữ bản đang nhập và báo kết quả chưa xác định; không tự tạo analysis/profile thứ hai. Người dùng tự kiểm tra hoặc chủ động bắt đầu lượt mới. Đây là bảo vệ ở client, không tuyên bố server đã có idempotency. GET lỗi mạng tạm dừng polling và cho tiếp tục; dispose dừng timer/abort GET, không tự hủy job backend.

Với POST profile chưa rõ kết quả, nút **Kiểm tra mẫu đã lưu** chỉ dùng GET. Đã nhớ ID thì đọc profile theo ID; chưa có ID thì tìm một trang giới hạn `per_page=100` bằng tên đã gửi, lọc tên khớp chính xác và `analysis_metadata.analysis_id` của analysis hiện tại. Chỉ khi xác định một mẫu duy nhất và kết quả không còn trang chưa kiểm tra mới ghi nhận ID/version; giữ nguyên bản đang sửa. Không tìm thấy, có nhiều mẫu hoặc còn trang khác thì giữ khóa tạo mẫu, không tự POST hoặc tự đặt mặc định. Đây là khôi phục kết quả lưu cho mẫu hiện tại, không triển khai trang/dialog danh sách văn phong.

PUT version cũ trả 409: giữ bản sửa và cho tải lại bản mới nhất theo thao tác rõ ràng; không lấy version mới rồi ghi đè tự động. Nếu POST profile đã thành công mà PUT default thất bại, thông báo mẫu đã được lưu và lỗi cập nhật mặc định riêng.

### 0.4 Kiến trúc và kiểm chứng mốc nối API ban đầu

Tầng `services/aiWritingProfiles.js` gọi API hiện có; `composables/ai/prompt` tách nguồn/catalog, lifecycle analysis và form/profile hiện tại. Page phối hợp state; các component nguồn/kết quả/form chỉ dùng props, models và emits. Dùng Vue 3 Composition API, `<script setup>` và comment tiếng Việt với khung `===`, inventory, INPUT/OUTPUT theo project. Không xây composable HTTP lịch sử hoặc CRUD/list dialog toàn bộ khi phần đó đang để sau.

Regression đạt **38 file / 238 tests**, gồm **32 tests Ai Prompt** (API 4, flow 20, UI 8). Scoped ESLint cho page/components/service/utils và ba file test đạt; production build đạt **31.40 giây**, còn warning asset `section-title-icon.png` đã có trước. Browser localhost xác nhận catalog thật, validation nguồn rỗng, nhập tên/dán bài 24 từ, tab URL/file, lịch sử minh họa/ô UUID và responsive 390/1440 không tràn ngang sau khi layout ổn định; console không có lỗi. Báo cáo: [QA nối API](qa/AI_PROMPT_API_2026-10-04.md).

Lifecycle/kết quả/lưu/version/default được kiểm bằng HTTP mock; browser không gửi POST gọi model hoặc mutation profile. Không gọi model trả phí; chưa nghiệm thu chất lượng văn phong với nguồn/model thật. Các kiểm chứng này hoàn tất phạm vi nối API hiện tại, không đánh dấu toàn bộ Task 2/FIX 1 hoàn thành.

- [x] Chốt phạm vi dùng API hiện có; phần chưa API giữ minh họa rõ nhãn.
- [x] Chốt trang/dialog danh sách văn phong để sau.
- [x] Hoàn thiện nối API hiện có và kiểm chứng đợt hiện tại theo báo cáo 12.19.
- [ ] Đợt sau: lịch sử server, đọc lại nguồn, preview riêng quyền AI và idempotency server nếu được triển khai.
- [x] Đợt nối tiếp: trang List/Add, tìm kiếm/phân trang/xem prompt đã triển khai tại 0.5.
- [x] Đợt nối tiếp: sửa/xóa mọi mẫu từ danh sách và select/brief ở form tạo bài đã triển khai tại 0.7.

### 0.5 Yêu cầu nối tiếp: Ai Prompt List/Add — đã triển khai

- **List** `/admin/ai/prompt/list`: datatable đọc profile đã lưu qua GET `/api/admin/ai/writing-profiles`, tìm tên tối đa 160 ký tự, phân trang 15/25/50/100 dòng. Hiển thị tên/mô tả, nguồn, bật/tắt, version, ngày cập nhật; nút Prompt mở toàn bộ hướng dẫn dưới dòng tương ứng, render text được escape.
- **Add** `/admin/ai/prompt/add`: giao diện phân tích hiện tại chuyển vào `AiPromptCreate`, giữ đầy đủ API/state/nhắc form chưa lưu. Có nút Danh sách; List có nút Thêm văn phong. Menu dọc/ngang cùng List/Add; URL cũ redirect về List.
- **Tải dữ liệu**: `useAiPromptList` đọc `data` và `meta.pagination.total`, debounce 300 ms, reset page khi tìm/đổi số dòng, xử lý danh sách thu nhỏ, lỗi/rỗng/tải lại và abort/sequence guard khi query đổi hoặc dispose. Không bật sort khi API chưa hỗ trợ sort.
- **Kiểm chứng**: 39 file/250 tests frontend đạt, gồm 44 tests Ai Prompt; ESLint scoped/build đạt, browser kiểm profile thật, tìm/clear/số dòng, điều hướng và responsive. Báo cáo [QA List/Add](qa/AI_PROMPT_LIST_2026-10-04.md), FIX 1 mục 12.21.

Không thêm endpoint hoặc migration. Giao diện sửa/xóa mọi mẫu từ List và GET lịch sử analysis vẫn để đợt tiếp theo.

### 0.6 Sửa Add giữ dữ liệu sau lưu — đã triển khai

Lưu thành công và cập nhật mặc định thành công nếu được yêu cầu thì Add dọn nguồn/tên/model, kết quả, form duyệt và UUID resume; giữ profile database và cấu hình mặc định. Nút **Văn phong mới** cho phép chủ động bắt đầu lại, xác nhận nếu bỏ bản duyệt chưa lưu. Lưu lỗi/POST chưa xác định giữ bản đang nhập; mặc định lỗi sau khi profile đã lưu hiển thị lỗi riêng và giữ bản đã lưu. GET resume 403/404/410 không giữ Add ở trạng thái queued; lỗi mạng khi tác vụ đang chạy vẫn giữ khóa để kiểm tra.

Regression frontend **39 file / 258 tests** đạt, gồm **52 tests Ai Prompt** (API 5, flow 23, UI Add 13, list 11). Scoped ESLint và build **33.19 giây** đạt. Browser kiểm reset/reload sau lỗi quyền bằng API thật; lưu thành công, lỗi và tạo hai mẫu liên tiếp dùng HTTP giả. Báo cáo [QA tạo tiếp văn phong](qa/AI_PROMPT_NEW_PROFILE_2026-10-04.md), FIX 1 mục 12.22. Không đổi backend hoặc gọi model trả phí.

> **Thiết kế ban đầu ở mục 1–8 dưới đây đã được thay phạm vi bởi mục 0.** Các chữ “bổ sung”, “bỏ mock”, GET lịch sử/source và CRUD/list đầy đủ trong các mục này là phương án tương lai, không phải trạng thái code hoặc cam kết của đợt nối API hiện tại.

### 0.7 Hoàn thiện quản lý văn phong — 2026-10-05

List đã nối tạo thủ công, GET detail, PUT theo version, bật/tắt, DELETE có xác nhận và cập nhật mặc định. Dùng lại form duyệt trong dialog quản lý; không bắt gọi AI để tạo mẫu thủ công. Lỗi 409 giữ bản sửa, tải bản mới cần xác nhận bỏ thay đổi; POST chưa rõ kết quả không tự gửi lần hai. Nếu profile đã lưu nhưng cập nhật default thất bại, giữ ID/version đã lưu và cho thử lại thao tác default riêng.

Mẫu bật xuất hiện trong select của AI Content và dialog Post; regenerate giữ snapshot cũ trừ khi chọn override. Lịch sử/điểm/SEO ở Add chưa có endpoint tiếp tục ghi nhãn minh họa. Bằng chứng và giới hạn model thật xem [QA Task 2 localhost](qa/TASK2_LOCALHOST_2026-10-05.md).

## 1. Hiện trạng và phạm vi

| Phần | Hiện trạng | Việc cần làm |
| --- | --- | --- |
| Trang và theme | `pages/ai/prompt/index.vue` và ba component đã custom; chạy bằng fixture | Thay fixture bằng state/API thật, giữ thiết kế hiện tại |
| Tên văn phong | Đã có ô nhập ở đầu card nguồn, phía trên ba tab URL/dán nội dung/file; hiện chỉ giữ state cục bộ | Validate tên bắt buộc, tối đa 160 ký tự và nối field `name` khi phân tích/lưu |
| Phân tích bài mẫu | Có POST queue, GET detail/polling và POST cancel | Nối form, trạng thái, kết quả và xử lý mất kết nối |
| Lưu/quản lý mẫu | Có CRUD, version, bật/tắt, options và default setting | Thêm form duyệt/lưu và danh sách mẫu đã lưu |
| URL/file | SourceCard mới có input; chưa đọc nguồn | API preview riêng theo quyền Ai Prompt; dùng extractor chung |
| Lịch sử phân tích | Chưa có GET list; detail không trả bài tham khảo | Bổ sung list summary và đọc lại nguồn có chủ đích |
| Điểm/biểu đồ | Điểm chất lượng, % văn phong, SEO và chủ đề là mock | Hiển thị đúng schema văn phong, bỏ số liệu không có dữ liệu |

Trong đợt này, phân tích một bài mẫu là **một lượt gửi model**. Luồng Analyze + Plan → Write → Edit thuộc tác vụ tạo bài và giữ nguyên. CRUD, preview nguồn, polling, copy và tải báo cáo không gửi model.

Không mở rộng thành SEO agent, chấm điểm chất lượng bài, kiểm đạo văn hoặc phân tích ảnh. Select văn phong ở form tạo bài được ghi riêng ở mục 8 để nối sau khi Ai Prompt hoạt động.

## 2. Luồng sử dụng sau khi nối

1. Mở Ai Prompt: nguồn rỗng, trạng thái chưa phân tích; tải catalog model và dữ liệu quản lý cần thiết bằng API. Không tự nạp bài mẫu/nhận xét minh họa.
2. Người dùng nhập **Tên văn phong** ở đầu card nguồn, phía trên ba tab URL/dán nội dung/file. Tên bắt buộc khi phân tích và khi lưu, tối đa 160 ký tự; không lấy tự động từ tiêu đề bài và không để AI tự đặt tên. Sau đó nhập bài tham khảo và chọn model text từ catalog hoặc để **Dùng model mặc định**.
3. Với URL/file, bấm **Lấy nội dung/Đọc file** để backend làm sạch nguồn. Xem và sửa phần văn bản sẽ gửi phân tích trước khi chạy AI.
4. Bấm **Phân tích với AI**: POST tạo analysis, nhận UUID rồi GET polling. Hai nút phân tích trên trang dùng cùng handler và khóa gửi trùng.
5. Khi `ready`, hiển thị tóm tắt văn phong, quy tắc, dẫn chứng và hướng dẫn tái sử dụng. Đây là kết quả chưa lưu thành mẫu.
6. Người dùng sửa tên/mô tả/quy tắc/hướng dẫn, bấm **Lưu mẫu văn phong**. POST profile với `analysis_id`; thành công mới thêm vào danh sách mẫu đã lưu.
7. Tại **Mẫu đã lưu**, xem/sửa/bật/tắt/xóa/đặt mặc định. Tại **Lịch sử phân tích**, mở lại tác vụ và kết quả của mình còn thời hạn.

Ô tên hiện đã có ở giao diện nhưng chưa gọi API. Khi nối, tên người dùng nhập được gửi thành `name` trong POST analysis; form duyệt kế thừa tên đó và cho người dùng sửa trước khi gửi `name` trong POST profile. Đổi tên trước khi lưu không đổi tên snapshot của analysis đã chạy.

Ví dụ: nhập tên “Giải thích dễ hiểu”, dán bài giải thích phần mềm viết tự nhiên → AI nhận xét “mở đầu bằng tình huống thực tế, đoạn ngắn, giải thích thuật ngữ trước ví dụ” kèm trích đoạn → người dùng bỏ quy tắc không phù hợp, sửa tên nếu muốn → lưu. Khi dùng mẫu để tạo bài khác, hệ thống dùng cách diễn đạt đã duyệt; không mang tên sản phẩm/số liệu của bài mẫu vào bài mới.

## 3. API dùng lại và API cần bổ sung

Các đường dẫn trong bảng tính từ `/api/admin`. Dùng `$api` hiện có để gửi admin bearer token; client không gọi provider hoặc giữ API key.

| Thao tác | Method / endpoint | Trạng thái |
| --- | --- | --- |
| Catalog model và Settings | GET `/settings/ai` | Có; dùng `aiProviderSettingsService` |
| Phân tích bài tham khảo | POST `/ai/writing-profiles/analyses` | Có; bổ sung chống request trùng |
| Tiến trình/kết quả | GET `/ai/writing-profiles/analyses/{uuid}` | Có; bổ sung `source_hash` public để đối chiếu nguồn |
| Hủy phân tích | POST `/ai/writing-profiles/analyses/{uuid}/cancel` | Có |
| Danh sách mẫu | GET `/ai/writing-profiles` | Có; `page`, `per_page`, `search` |
| Đọc/tạo/sửa/xóa mẫu | GET/POST/PUT/DELETE `/ai/writing-profiles[/{id}]` | Có; PUT/DELETE cần `version` |
| Options mẫu đang bật | GET `/ai/writing-profiles/options` | Có; chứa ID mẫu mặc định |
| Đặt/gỡ mẫu mặc định | PUT `/settings/ai/settings` | Có; chỉ gửi `default_writing_profile_id` |
| Preview nguồn Ai Prompt | POST `/ai/writing-profiles/source-preview` | **Bổ sung** |
| Lịch sử phân tích | GET `/ai/writing-profiles/analyses` | **Bổ sung** |
| Đọc lại bài tham khảo | GET `/ai/writing-profiles/analyses/{uuid}/source` | **Bổ sung** |

Response theo `BaseResponse`; danh sách giữ cả `data` và `meta.pagination`, không unwrap làm mất phân trang. DELETE thành công trả 204, không chờ JSON.

### 3.1 Preview nguồn đúng quyền

Endpoint `/ai-agent/source-preview` hiện dùng `AiImportRequest`, kiểm quyền target mặc định `posts.manage`. Ai Prompt chỉ yêu cầu `ai_settings.manage`; không nối trực tiếp khiến người chỉ quản lý AI bị 403.

Bổ sung endpoint preview trong nhóm WritingProfiles với FormRequest chỉ nhận dữ liệu nguồn và quyền `ai_settings.manage`. Tái sử dụng `ArticleSourceFetcher`/`ArticleSourceExtractor`, giữ kiểm URL/redirect/SSRF, MIME/encoding/budget; không nới quyền tạo Post hoặc sao chép pipeline generation.

- Gửi đúng một nguồn `url`, `text`, `html` hoặc `html_file`; không gửi model, profile hay taxonomy.
- `.html/.htm`: `FormData` field `html_file`, tối đa 5 MB; browser tự tạo Content-Type/boundary.
- `.txt`: đọc UTF-8 có giới hạn ở client rồi gửi field `text`; không gửi `.txt` vào `html_file`. HTML encoding khác dùng lựa chọn encoding backend đã hỗ trợ.
- Trả `reference_text` đã làm sạch, `source_hash` của chính văn bản đó và metadata nguồn như title/source URL, số ảnh/link. Chuẩn hóa/trim giống bước queue hiện tại rồi SHA-256 trên UTF-8 để preview và analysis cùng nội dung có cùng hash; lấy hash do server trả, không đoán từ HTML editor. Có thể trả HTML đã sanitize cho preview cấu trúc khi cần; không thực thi nguồn gốc.
- Văn bản phân tích cuối phải 30–100000 ký tự. Nguồn quá lớn báo lỗi để người dùng chọn lại; không cắt thầm.
- Chuyển HTML thành text phải giữ ranh giới heading/đoạn/list và xuống dòng code; tránh nối hai đoạn thành một từ.

### 3.2 Lịch sử và mở lại nguồn

GET list chỉ query `created_by` của actor, phân trang, tìm tên, lọc status, sắp mới nhất với tie-break ID. Summary chỉ trả UUID/tên/status/provider/model/mốc thời gian/hạn lưu; không trả `reference_text`, result lớn hoặc snapshot kết nối.

GET detail dùng để mở kết quả/polling. GET `/{uuid}/source` chỉ chạy khi người dùng mở lại bài tham khảo, kiểm owner và hạn lưu như detail; trả `reference_text` cùng `source_hash`. Không nhét nguồn vào mọi lần poll. Sau hết hạn trả 410; sau cleanup có thể 404. Profile đã được duyệt vẫn tồn tại khi analysis bị cleanup.

Giữ thời hạn analysis hiện có, mặc định 2 ngày, hiển thị `expires_at` từ server. Lịch sử ở đây là lịch sử tạm trong thời hạn lưu; không hứa lưu tất cả phân tích vĩnh viễn. Danh sách mẫu đã lưu là danh sách khác.

### 3.3 Tránh tạo hai analysis khi POST mất kết nối

Backend hiện luôn tạo row và dispatch mỗi POST. Bổ sung `client_request_id` UUID cho mỗi lần người dùng chủ động phân tích, request hash của payload và unique `(created_by, client_request_id)`; cho phép client cũ không gửi field mới.

- Cùng actor/key/payload: trả lại analysis cũ, không dispatch hoặc trừ quota thêm; kiểm trường hợp này trước quota/model resolution mới.
- Cùng key nhưng payload khác: 409; không ghi đè nguồn đã queue.
- Tạo/dispatch sau commit, xử lý race bằng unique constraint/transaction. Idempotency được bảo đảm trong thời hạn lưu analysis; không dùng lại key của tác vụ đã hết hạn/cleanup.
- Client tắt retry tự động cho mutation. Khi POST timeout chưa nhận UUID, giữ request key và payload của lần đó; người dùng có thể bấm tiếp tục gửi **cùng key**, không tạo key mới âm thầm.
- “Phân tích lại” sau terminal là yêu cầu mới có UUID request mới, chạy khi người dùng bấm rõ ràng.

## 4. Ánh xạ giao diện sang dữ liệu thật

Schema AI hiện có chỉ trả:

```json
{
  "summary": "Tóm tắt đặc điểm văn phong...",
  "rules": {
    "tone": "Giải thích gần gũi, trực tiếp",
    "opening": "Ưu tiên tình huống thực tế nếu phù hợp",
    "sentence_rhythm": "Câu ngắn xen câu giải thích",
    "structure_patterns": ["Giải thích → ví dụ"],
    "avoid": ["Lặp ý để kéo dài bài"],
    "uncertainties": ["Một bài mẫu chưa đủ kết luận cách kết bài cố định"]
  },
  "evidence": [{"feature":"tone","excerpt":"Trích đoạn có thật trong bài đã gửi","explanation":"Vì sao đoạn này cho thấy giọng điệu đó"}],
  "style_instructions": "Hướng dẫn văn phong có thể tái sử dụng..."
}
```

Ví dụ trên minh họa cấu trúc; không dùng làm kết quả mặc định.

| Khối hiện tại | Sau khi nối |
| --- | --- |
| Tổng quan nguồn | Số từ/đoạn/thời gian đọc ước tính từ nguồn thực; số ảnh/link từ metadata nguồn, ghi rõ xuất xứ |
| Chủ đề chính | Thay bằng loại nguồn/tên mẫu; API chưa có phân tích chủ đề |
| Điểm chất lượng/điểm SEO | Thay bằng trạng thái, model và thời điểm phân tích; không dựng điểm 0–100 |
| % chuyên môn/tự nhiên/cảm xúc | Thay bằng nhận xét text trong `rules`; không chuyển nhận xét thành phần trăm |
| Tab nội dung | Đổi thành Tổng quan văn phong, dùng `summary` |
| Tab văn phong/cấu trúc | Nhóm các rule tương ứng; field thiếu hiển thị chưa xác định, không thêm nhận xét mock |
| Tab SEO/ảnh/tính độc đáo | Thay bằng Dẫn chứng và Điểm chưa chắc chắn/Điều cần tránh; đánh giá ảnh/SEO nằm ngoài schema này |
| Prompt gợi ý | Đổi thành Hướng dẫn văn phong; textarea cho sửa `style_instructions`, copy giá trị đang xem/sửa |

Rules có 16 key allowlisted: tone, pronouns, emotion, opening, sentence_rhythm, paragraph_rhythm, transitions, vocabulary, technical_terms, structure_patterns, headings, bullets, examples, ending, avoid, uncertainties. Hiển thị nhãn tiếng Việt; ba key `structure_patterns/avoid/uncertainties` dùng danh sách, các key còn lại dùng text.

Ảnh/link không có trong `reference_text` vẫn có thể được đếm trong bản nguồn HTML; đây là thống kê nguồn, không phải AI đánh giá ảnh hoặc kiểm chứng liên kết. Khi nguồn chưa được tải lại từ lịch sử, hiển thị chưa có thống kê, không lấy số đếm của bài khác.

## 5. Trạng thái, nguồn và lỗi

- State giao diện: `idle`, `previewing`, `submitting`, `queued`, `analyzing`, `ready`, `saving`, `failed`, `cancelled`, `expired`; `submitting/saving` là state client, không gửi như status backend.
- Poll tuần tự với backoff dự kiến 2 → 3 → 5 → 8 giây; một timer, không chồng GET. Chỉ GET có retry hữu hạn; dừng timer/abort request khi terminal hoặc dispose. Không coi lỗi mạng là `failed` của model.
- Lưu UUID/request key và hạn lưu theo tài khoản trong sessionStorage để resume sau reload; không lưu token mới, bài mẫu hay kết quả lớn vào localStorage. Với POST chưa rõ kết quả, không replay nếu mất payload cũ: mở lịch sử để tìm tác vụ trước khi phân tích mới.
- Nếu cần giữ unsaved draft khi rời trang, dùng cảnh báo thay đổi chưa lưu của project; không tự lưu profile. GET source cho phép khôi phục bản bài tham khảo đã gửi khi đã có UUID.
- Giữ `submittedReferenceText/hash` riêng với nguồn đang chỉnh. Đổi URL/file/editor không làm thay analysis cũ. Kết quả phải gắn nhãn bài đã phân tích; chỉ hiện nút lưu với analysis đúng snapshot. Muốn phân tích nguồn mới phải tạo request mới.
- Tiptap hiện không hỗ trợ toàn bộ ảnh/link/table: không lấy HTML đã đi qua editor làm bản nguồn gốc URL/file. Preview URL/file giữ `reference_text` chuẩn riêng, có vùng sửa text; paste editor chuyển thành text giữ ranh giới đoạn trước POST.
- Dùng request sequence/UUID guard để response cũ không ghi đè kết quả hoặc form đang mở. Tải lại list/default không làm mất form người dùng đang sửa.
- Cancel theo API; response model về muộn không ghi đè cancelled. Nút hủy không bảo đảm thu hồi lượt API đã gửi.
- 422: map lỗi theo field, gồm lỗi quota hiện trả ở `reference_text`; 429: giữ nguồn và thời gian chờ throttle; 403: báo thiếu quyền; 409: giữ thay đổi để đối chiếu; 410/404: dừng resume và báo hết hạn/không còn tác vụ. Lỗi queue/provider hiển thị từ code/message an toàn của server.

## 6. Duyệt, lưu và quản lý mẫu

Form lưu gồm **Tên văn phong do người dùng nhập** (bắt buộc, ≤160; có thể sửa tên đã gửi khi phân tích), mô tả (≤2000), quy tắc và hướng dẫn (≤10000), trạng thái bật. Tên được gửi trong field `name` của profile, không lấy tiêu đề bài hoặc tên AI tự đặt. Dẫn chứng hiển thị cạnh quy tắc, giữ trích đoạn nguyên văn; cho bỏ dẫn chứng hoặc sửa giải thích. Không để thao tác sửa quy tắc làm dẫn chứng trở thành lời xác nhận sai.

Với mẫu đã lưu mà analysis nguồn đã cleanup, backend chỉ cho giữ/bỏ evidence đã duyệt, không sửa excerpt hoặc explanation. Form phải phản ánh giới hạn này hoặc xử lý 422 bằng hướng dẫn giữ/bỏ dẫn chứng; tên/rules/hướng dẫn vẫn sửa được. Không hứa khôi phục bài mẫu đã bị cleanup.

Payload tạo từ kết quả phân tích:

```json
{
  "name": "Giải thích dễ hiểu",
  "description": "Dùng cho bài hướng dẫn người mới",
  "analysis_id": "uuid-ready-analysis",
  "rules_json": {"tone":"Giải thích trực tiếp","opening":"Tình huống phù hợp nguồn","sentence_rhythm":"Câu gọn, xen giải thích"},
  "evidence_json": [],
  "style_instructions": "Diễn đạt rõ ràng, giải thích thuật ngữ trước ví dụ...",
  "is_enabled": true
}
```

UUID thay bằng ID thật còn hạn; nếu giữ dẫn chứng thì gửi `result.evidence` đã được duyệt. Analysis ready không tự tạo profile. Tạo thủ công không gửi `analysis_id`, không tự thêm dẫn chứng chưa có nguồn để đối chiếu.

Danh sách mẫu dùng server pagination/search; hiển thị tên, mô tả, version, bật/tắt và badge mặc định. PUT/DELETE gửi version mới nhất đã xem; 409 mở thông tin xung đột, giữ form để so sánh/tải lại, không retry ghi đè tự động. PUT không gửi `analysis_id`.

Đặt mặc định gửi partial Settings payload `{ "default_writing_profile_id": id }`; gỡ mặc định gửi null. Mẫu phải đang bật. Backend đã gỡ default khi tắt/xóa; UI tải lại options/default sau thao tác. Nếu lưu mẫu thành công nhưng đặt mặc định lỗi, báo riêng hai kết quả; không báo mẫu chưa được lưu.

Copy và **Tải báo cáo** dùng dữ liệu thực ở client, không cần API export: Markdown UTF-8 gồm tên mẫu, model, ngày phân tích, summary/rules/evidence/hướng dẫn và ghi rõ đã sửa/chưa lưu. Không tự kèm toàn bài tham khảo. Disable khi chưa có kết quả.

## 7. Phân chia code theo project

Giữ Vue 3 Composition API, `<script setup>` và conventions JS hiện tại; đặt các composable cùng chức năng trong folder `composables/ai/prompt`. Page chỉ phối hợp state/component, không chứa toàn bộ HTTP/polling/CRUD. Không thêm Pinia nếu state chỉ dùng tại trang này.

| File/folder dự kiến | Trách nhiệm |
| --- | --- |
| `services/aiWritingProfiles.js` | HTTP preview/analysis/history/source/CRUD/options; chuẩn hóa envelope, pagination và lỗi |
| `composables/ai/prompt/useAiPromptSource.js` | Nguồn, file/encoding/preview, canonical text và thống kê |
| `composables/ai/prompt/useAiPromptAnalysis.js` | Submit/idempotency, polling/cancel/resume và snapshot nguồn |
| `composables/ai/prompt/useAiPromptProfiles.js` | List/form/save/version/default; dùng lại Settings service |
| `composables/ai/prompt/useAiPromptHistory.js` | List phân tích phân trang, mở detail/source; không giữ bài mẫu trong summary |
| `pages/ai/prompt/index.vue` | Wiring các composable/component và thông báo chung |
| `views/ai/prompt/AiPromptSourceCard.vue` | Props source/loading/errors; emits sửa/preview/analyze/reset/clear, không tự gọi API |
| `views/ai/prompt/AiPromptAnalysisCard.vue` | Props kết quả/snapshot; các tab rule/dẫn chứng; emits yêu cầu sửa |
| `views/ai/prompt/AiPromptInsights.vue` | Props metrics/lifecycle/form; hiển thị hướng dẫn và emits copy/save/export |
| `views/ai/prompt/AiPromptProfileForm.vue` | Form duyệt/sửa tên/mô tả/rules/hướng dẫn với lỗi field |
| `views/ai/prompt/AiPromptProfilesDialog.vue` | Mẫu đã lưu, search/pagination và actions qua emits |
| `views/ai/prompt/AiPromptHistoryDialog.vue` | Lịch sử riêng, filters/pagination và mở lại tác vụ |
| `utils/aiWritingProfile.js` | Hàm thuần: text normalization, nhãn rule, mapping result→form, export report |

Backend theo Laravel: FormRequest/Controller/Resource ở tầng HTTP; nghiệp vụ nguồn/chống trùng trong `Services/Ai/WritingProfiles`, job/model hiện có. Bổ sung migration cho request key/hash/index; không tạo bảng profile/analysis thứ hai. Các endpoint tĩnh nằm trước route `/{profile}` và vẫn có ràng buộc UUID/numeric.

Mọi file ứng dụng tạo/sửa phải cập nhật comment tiếng Việt có khung `===`, danh sách hàm và INPUT/OUTPUT theo convention đã chốt. Khi nối thật, gỡ import fixture khỏi runtime; giữ fixture cho test/demo riêng nếu cần.

## 8. Thứ tự triển khai và nghiệm thu

| Đợt | Công việc | Điều kiện hoàn tất |
| --- | --- | --- |
| 1 — Contract backend | Preview đúng quyền, history/source, source_hash, request idempotency; migration và tài liệu API | Admin chỉ có `ai_settings.manage` dùng được; source/analysis của người khác bị chặn; replay không queue hai lần |
| 2 — Nguồn và phân tích | Service/composable, model selector, URL/paste/file preview, submit/poll/cancel/resume; bỏ report mock | Chạy đến ready với kết quả thật từ API; đổi nguồn/response cũ không ghép sai evidence |
| 3 — Duyệt và quản lý | Form save, CRUD/version/default, history, copy/export | Lưu rồi reload thấy mẫu đúng; 409 giữ bản sửa; history mở đúng bài/kết quả còn hạn |
| 4 — Kiểm thử và bàn giao | Feature/frontend tests cần thiết, lint/build, browser QA desktop/mobile/light/dark | Có báo cáo phần đã nối/endpoint mới/giới hạn; không chỉ test bằng số liệu mock |

Kiểm thử tập trung vào quyền preview/owner/expiry, request trùng và payload conflict, canonical text/evidence, polling dispose/resume/cancel race, GET mất mạng không tự tạo analysis, version409, pagination, lưu không gọi AI và đặt default partial không đụng setting khác. Dùng provider fake để regression không phát sinh chi phí; thử model thật theo bước nghiệm thu thực tế có chủ đích, ghi rõ nếu chưa chạy.

Browser QA xác nhận đủ ba nguồn, loading/error/empty/ready, thao tác sửa rồi lưu, reload/resume, list/history, clipboard/download và responsive/theme. Không chạy build/test ứng dụng chỉ để lập tài liệu plan.

**Phần nối tiếp của Task 2:** thêm select văn phong trong Ai Content/CreateWithAiDialog và default profile trong AI & Content Settings bằng `/options`; gửi `writing_profile_id`, brief/yêu cầu riêng vào session API đã có. Null/vắng ID hiện nghĩa là dùng website default, không phải “bỏ mọi văn phong”. Nhãn select phải phản ánh đúng contract; muốn một lựa chọn “không dùng mẫu kể cả default” cần chốt field backend riêng trước. Không để AI tự chọn profile. Mẫu đã lưu sẽ sẵn sàng cho bước này, nhưng Ai Prompt được nghiệm thu độc lập trước.

**Checklist theo dõi**

- [ ] Đợt 1: backend bổ sung và hợp đồng mới.
- [ ] Đợt 2: nguồn/phân tích/polling từ API thật.
- [ ] Đợt 3: duyệt/lưu/quản lý/default/lịch sử/export.
- [ ] Đợt 4: regression và browser QA.
- [ ] Nối tiếp: select văn phong/brief ở form tạo bài và Settings.

Plan hoàn tất khi tài liệu được ghi vào FIX 1; các checkbox chỉ được đánh dấu sau khi triển khai và kiểm chứng tương ứng.
