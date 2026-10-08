# Chấm từng bài AI trước khi duyệt và báo cáo chất lượng định kỳ

**Cập nhật:** 07/10/2026

**Trạng thái:** IN PROGRESS — Giai đoạn 1 DONE, migration đã chạy trên database local. Ngày 07/10/2026 đã cập nhật kế hoạch theo thảo luận mới: chấm từng bài trước khi duyệt, vòng tròn điểm trong Content AI và báo cáo tổng hợp định kỳ. Giai đoạn 2–6 còn TODO; lần cập nhật này chưa triển khai các chức năng đó.

Kho bài gốc đã triển khai. Phần code evaluator, cổng duyệt, giao diện điểm và báo cáo vẫn tạm hoãn theo yêu cầu trước đó; cập nhật kế hoạch không đồng nghĩa đã bật chấm AI hoặc lịch chạy. Tiến độ các task khác xem kế hoạch tổng.

**Theo dõi tổng:** [PLAN.md — Q-01](PLAN.md#backlog-đang-dùng).

## 1. Mục tiêu và phạm vi

Trong Danh sách Content AI, mỗi bản bài Post có nội dung mới do AI tạo sẽ được chấm ngay sau khi tạo xong để chủ dự án cân nhắc duyệt. Bên phải mỗi bài có vòng tròn điểm; bấm vào để xem tiêu chí, nhận xét và dẫn chứng. AI đánh giá nội dung; backend kiểm kết quả, tính điểm tổng và quyết định bài có đủ điều kiện để người dùng duyệt hay không.

Luồng mới: **AI tạo từng bản → chấm tự động → hiện điểm/cảnh báo → kiểm điều kiện duyệt → người dùng chọn một bản → lưu bài/nguồn/điểm bản đã chọn → định kỳ tổng hợp và viết báo cáo**.

Quy tắc duyệt theo thảo luận: **điểm tổng trên 4/5, đủ nguồn để đối chiếu và qua kiểm tra dữ kiện**. Bài chưa chấm, đang chấm, chấm lỗi, thiếu nguồn hoặc không đạt điều kiện chưa được duyệt/Apply vào Post. Đạt điều kiện chỉ mở quyền thao tác; người dùng vẫn quyết định duyệt. Điểm đúng bằng 4,00 chưa đạt.

Ví dụ tạo ba bản 1–2–3: cả ba bản được chấm tạm để so sánh; duyệt bản 2 thì chỉ bản 2 cùng kết quả chấm được giữ lâu dài. Bản 1 và 3, dù điểm cao nhưng không được chọn, vẫn hết hạn theo retention Content AI mặc định 2 ngày. Bài không tạo được nội dung không nhận điểm 0 giả.

Báo cáo định kỳ chủ yếu dùng điểm đã có của các bài được duyệt, không mặc định gọi AI chấm lại toàn bộ. Các bài này đã qua cổng duyệt nên báo cáo không lấy danh sách bài điểm thấp/thiếu nguồn chưa duyệt làm nội dung chính. Chấm lại chỉ dành cho kiểm tra mẫu, thay bộ tiêu chí/evaluator hoặc yêu cầu kiểm tra cụ thể.

Mức tham chiếu hiện tại: [19 bài được AI chấm, trung bình 4,49/5](../qa/FIX1_ACCEPTANCE_2026-10-07/ai-review/README.md). Bộ này dùng để thử bộ chấm mới; giữ nguyên điểm lịch sử. Mốc 4,49 không phải điểm mặc định hoặc ngưỡng duyệt. Ngưỡng mới theo thảo luận là **trên 4/5**; điểm AI không điền thay vào hai phiếu người đọc.

Phạm vi đầu tiên là bài Post do AI Content tạo; image run và lượt chỉ đổi title/SEO không bị tính thành bài content mới. Báo cáo là tài liệu nội bộ trong Admin. Hệ thống không tự sửa bài, prompt, profile, model hoặc Publish theo điểm.

## 2. Nền tảng hiện có và phần cần bổ sung

| Hiện có | Cần làm thêm |
| --- | --- |
| [AiImport](../../app/Models/AiImport.php), nguồn `source_meta.article_source`, result và checkpoint tạm | Chấm từng candidate khi có nội dung; vòng tròn điểm và chi tiết trong Content AI |
| [AiArticleArchive](../../app/Models/AiArticleArchive.php) giữ bài gốc/nguồn/ngữ cảnh đã duyệt; [AiProvenance](../../app/Models/AiProvenance.php) giữ metadata/hash của field đã Apply | Giữ thêm kết quả chấm đúng bản được chọn, cùng transaction duyệt/Apply |
| [Cleanup](../../app/Console/Commands/CleanupAiImportsCommand.php), retention mặc định [2 ngày](../../config/ai-import.php) | Dọn điểm/dẫn chứng tạm của candidate chưa duyệt; bảo toàn bài và điểm của bản đã duyệt |
| [ProjectScheduleRegistry](../../app/Services/Settings/ProjectScheduleRegistry.php), queue, Settings và provider/model catalog | Job chấm ngay, lịch tổng hợp báo cáo, cấu hình evaluator và ngân sách riêng |
| [Rubric chất lượng](../quality/AI_ARTICLE_QUALITY_EVALUATION.md), gate kỹ thuật và bộ đánh giá offline | Tiêu chí/version dùng chung, cổng duyệt phía backend, kiểm chứng kết quả AI và báo cáo |

Kho `ai_article_archives` và service đã triển khai ở giai đoạn 1; API và màn hình `AI Approved` đọc các snapshot đã duyệt, còn nơi lưu điểm tạm, bảng chấm/báo cáo và cổng duyệt vẫn là thiết kế cần triển khai. Archive dùng identity đa model `target_type` + `applied_target_id`; Post là target đầu tiên có workflow duyệt, các target khác dùng chung kho khi adapter/review tương ứng hoàn tất. Không sửa ngược migration đã chạy; nếu cần schema mới cho điểm thì thêm migration khi triển khai. Kho snapshot chất lượng không đồng nghĩa triển khai toàn bộ kho draft biên tập dài hạn đang tạm hoãn.

## 3. Dữ liệu cần lưu

### 3.1. Snapshot bài/nguồn — đã có `ai_article_archives` (contract v1)

- Định danh run/session, parent/attempt, người tạo và thời điểm của candidate đã approved; liên kết Post nếu có. Dữ liệu vẫn đọc được khi run/Post bị dọn hoặc liên kết không còn.
- Title/content bản AI hoàn tất **trước khi người dùng sửa**, hash nội dung và phiên bản. Regenerate tạo candidate/checkpoint tạm mới; chỉ bản được duyệt mới chuyển vào kho, ghi lặp không tạo bản trùng. Ví dụ ba bản 1–2–3, duyệt bản 2: kho chỉ giữ bản 2.
- Nguồn đã dùng: URL/metadata, snapshot text/HTML chuẩn hóa, source blocks/IDs, hash nguồn; giữ ngữ cảnh đủ để đối chiếu về sau mà không cần URL còn sống.
- Brief, yêu cầu output, profile snapshot, phiên bản prompt/schema, provider/model của writer và thời điểm tạo; chỉ giữ metadata cần cho đánh giá, không lưu secret/header/raw response không cần thiết.
- Trạng thái duyệt/Apply và lý do. Candidate lỗi/timeout/cancel/từ chối không tạo archive; không gán điểm văn phong 0 cho trường hợp chưa có bài.
- Nếu bản đã biên tập được dùng để chấm/duyệt, phần mở rộng phải giữ snapshot/hash bản đó riêng với kết quả tại lúc duyệt; không ghi đè bản AI gốc. Không lưu toàn bộ các bản chưa được chọn lâu dài.

Ưu tiên lưu DB/JSON để triển khai đơn giản; file nguồn lớn có thể dùng private storage cùng hash và quản lý vòng đời riêng. Snapshot chỉ được ghi khi approve/Apply thành công, cùng transaction với Post/provenance/decision. Nếu ghi snapshot thất bại, toàn bộ quyết định phải rollback và checkpoint tạm còn trong danh sách Content AI để thử lại; queue best-effort không đủ bảo đảm lưu bài.

### 3.2. Điểm từng candidate và kết quả được giữ lâu dài — dự kiến `ai_article_evaluations`

- Điểm tạm gắn run/candidate/generation và hash của bản thực sự được chấm, cùng hash nguồn/ngữ cảnh và phiên bản rubric/evaluator. Cùng bộ này chỉ có một kết quả thành công; retry kỹ thuật không tạo điểm trùng.
- Năm điểm 1–5: tự nhiên, rõ ràng/hữu ích, cấu trúc, ít lặp, đúng yêu cầu/văn phong; mỗi điểm có giải thích và dẫn chứng. Backend tính điểm tổng theo quy tắc đã version hóa; không nhận điểm tổng AI tự khai làm đáp án.
- Kết quả nguồn/dữ kiện riêng: đủ/chưa đủ căn cứ, trạng thái đạt/không đạt/chưa xác định, severity critical/major/minor, claim và trích đoạn bài/source block liên quan. Điểm cao không che lỗi dữ kiện.
- Trạng thái chưa chấm/đang chấm/đã chấm/chấm lỗi/chưa đủ căn cứ; kết luận đủ điều kiện duyệt và lý do chặn. Thiếu kết quả giữ null, không biến thành 0 hoặc tự cho đạt.
- Provider/model của evaluator, thời điểm, schema/version, attempt, mã lỗi an toàn, usage/chi phí có căn cứ. Thiếu usage/bảng giá hoặc số liệu sửa thực tế giữ null và lý do.
- Candidate chưa được chọn: toàn bộ điểm chi tiết/dẫn chứng tạm hết hạn cùng candidate sau 2 ngày mặc định; không vì đã chấm mà lưu bài chưa duyệt lâu dài.
- Khi duyệt/Apply thành công: giữ kết quả đúng hash của bản đã chọn và liên kết với archive; việc này cùng transaction với Post/provenance/decision. Cleanup hoặc xóa run không được xóa điểm đã giữ của archive. Lỗi giữ điểm rollback duyệt, không mất điểm tạm để thử lại.
- Sửa nội dung trước khi duyệt làm điểm cũ mất hiệu lực với bản hiện tại và cần chấm lại. Điểm của bản AI gốc vẫn gắn bản gốc; điểm bản sửa được phân biệt rõ, không ghi đè original hoặc dùng điểm cũ để duyệt bài đã đổi.

### 3.3. Báo cáo — dự kiến `ai_quality_reports`

- Khoảng thời gian, múi giờ, phạm vi/tiêu chí chọn bài, các ID/hash/phiên bản đánh giá dùng làm đầu vào.
- Tổng bài Post có nội dung AI mới được duyệt trong kỳ, số có điểm hợp lệ và số chưa đủ dữ liệu lịch sử. Bản legacy thiếu original/điểm giữ nhãn riêng, không được tự gán trên 4 hoặc đưa vào trung bình.
- Trung bình, trung vị, phân bố điểm tại lúc duyệt và năm tiêu chí; xu hướng giữa các kỳ đủ điều kiện so sánh. Phân biệt điểm bản AI gốc với điểm bản đã biên tập, không gộp thành cùng một chỉ số chất lượng writer. Nhóm theo văn phong, loại bài, model hoặc nguồn khi đủ số bài và metadata.
- Ví dụ bài tốt và phần còn có thể cải thiện trong các bài đã duyệt, kèm link về bài/điểm/dẫn chứng. Không mặc định có bài dưới ngưỡng hoặc thiếu nguồn đã được duyệt.
- Nếu kiểm tra lại phát hiện lỗi lọt cổng duyệt hoặc điểm mới không đạt: ghi cảnh báo kiểm tra, phân biệt kết quả cũ và mới; không tự sửa/xóa/gỡ đăng bài.
- Nội dung phân tích, nhận xét và đề xuất; version/model tạo phần diễn giải, usage/chi phí, thời điểm và trạng thái complete/partial/failed.
- Giữ lịch sử báo cáo; chạy lại tạo revision có nguồn đầu vào rõ ràng, không âm thầm thay báo cáo đã xem.

**Thống kê bài bị loại là phần mở rộng:** nếu cần tổng bài tạo, tỷ lệ đạt điều kiện hoặc tỷ lệ bị loại theo lý do, phải ghi bộ đếm tổng hợp trước khi candidate bị dọn. Chỉ giữ số đếm và nhóm metadata cần thiết, không giữ bài/nguồn/điểm chi tiết của bản bị loại lâu dài. Chống đếm trùng retry và phân biệt candidate không đạt với candidate đạt nhưng người dùng không chọn. Chưa có bộ đếm thì báo “chưa có dữ liệu”, không suy từ kho approved hoặc gán tỷ lệ 0%.

## 4. Chấm từng bài, cổng duyệt và vòng tròn điểm

### 4.1. Đầu vào và bộ tiêu chí

Evaluator nhận **bài cần chấm + nguồn đã dùng + văn phong snapshot + brief/yêu cầu + bộ tiêu chí**. Bộ tiêu chí quản lý tập trung, có phiên bản; người dùng không phải nhập lại cho từng bài. Mỗi bài đối chiếu với nguồn/văn phong tại lúc tạo, không dùng 19 bài lịch sử làm nội dung chuẩn để bắt chước.

| Tiêu chí | Cách chấm |
| --- | --- |
| Diễn đạt tự nhiên | Câu dễ đọc, từ phù hợp, không dịch sát hoặc sáo rỗng |
| Rõ ràng và hữu ích | Đủ giải thích cho người đọc mục tiêu; không bịa thêm thông tin hoặc kéo dài để tăng điểm |
| Cấu trúc phù hợp | Thứ tự ý, mở/kết, heading/bullet phù hợp loại bài và lượng thông tin nguồn |
| Ít lặp/nội dung thừa | Mỗi đoạn có mục đích, không lặp ý hoặc filler |
| Đúng yêu cầu và văn phong | Giọng điệu, xưng hô, nhịp câu, cấu trúc và điều cần tránh theo brief/profile đã chọn |

Thang 1–5: 1 kém, 3 dùng được sau sửa, 5 tốt; 2/4 nằm giữa. Đề xuất bản đầu dùng trung bình cộng năm tiêu chí với trọng số bằng nhau; cần chốt công thức/version trước khi triển khai. Độ trung thành với nguồn kiểm riêng: số liệu, tên, ngày, điều kiện, code, trích dẫn, thông tin quan trọng bị bỏ và claim không đủ căn cứ.

Không lấy kết quả Analyze hoặc lời tự nhận xét của writer làm đáp án. Nội dung bài/nguồn là dữ liệu không tin cậy; evaluator không làm theo lệnh nằm trong đó. Backend kiểm schema, điểm trong khoảng, đủ năm tiêu chí và dẫn chứng có thật trước khi nhận kết quả. Kiểm định dạng không tự chứng minh AI đã chấm đúng; cần thử đối chiếu bộ 19 bài và kiểm tra mẫu.

Chấm theo snapshot nguồn đã lưu. Đây là đánh giá của AI, không thay nghiệm thu của con người hoặc xác minh trực tuyến mọi sự thật. Không khẳng định đã chạy code/xem ảnh nếu chưa có công cụ và dữ liệu cho phép làm việc đó.

### 4.2. Điều kiện duyệt do backend kiểm

- Kết quả chấm hoàn tất, hợp lệ và khớp hash bản đang duyệt cùng nguồn/ngữ cảnh; kết quả pending, lỗi hoặc cũ không mở cổng duyệt.
- Điểm tổng **> 4/5**, xét giá trị trước khi làm tròn. Điểm đúng 4,00 không đạt; giao diện phải thể hiện rõ ngưỡng để tránh hiểu nhầm do làm tròn.
- Đủ nguồn để đối chiếu và kiểm tra dữ kiện đạt. Lỗi critical/major phải chặn; lỗi dữ kiện còn chưa xử lý hoặc trạng thái “chưa đủ căn cứ/chưa xác định” không được coi là đạt. Các góp ý diễn đạt nhỏ vẫn hiển thị để người dùng cân nhắc.
- Backend kiểm trên mọi đường duyệt/Apply vào Post, không chỉ khóa nút phía giao diện. Đạt điểm không tự duyệt hoặc tự Publish; không thêm cơ chế bỏ qua cổng duyệt trong MVP.
- Regenerate tạo bản và lượt chấm riêng. Sửa title/content hoặc ngữ cảnh ảnh hưởng đánh giá phải chấm lại; kết quả đến muộn của generation/hash cũ không ghi điểm lên bản mới.

### 4.3. Vòng tròn điểm trong Danh sách Content AI

- Đặt bên phải mỗi bài; hiện điểm tổng trên 5, ví dụ `4,4/5`, kèm nhãn đạt/chưa đạt khi có kết quả.
- Chưa chấm/đang chấm/chấm lỗi/chưa đủ căn cứ có trạng thái riêng; không hiện số điểm giả. Màu chỉ hỗ trợ, không thay chữ giải thích trạng thái.
- Bấm vòng tròn mở năm điểm thành phần, lý do/dẫn chứng, lỗi nguồn, thời điểm và phiên bản bộ chấm; cảnh báo dữ kiện vẫn rõ dù điểm tổng cao.
- Nút Duyệt/Apply chỉ khả dụng khi đủ điều kiện, kèm lý do chặn dễ hiểu. Chấm bất đồng bộ, không giữ màn hình tạo bài chờ AI; người dùng có thể đọc bài trong khi đang chấm.
- Sau sửa/regenerate, vòng tròn hiện trạng thái chấm bản mới. Chi tiết phân biệt điểm bản AI gốc, bản đã sửa và lần kiểm tra lại.

### 4.4. AI và backend chịu trách nhiệm phần nào

| Thành phần | Trách nhiệm |
| --- | --- |
| AI evaluator | Chấm năm tiêu chí, đối chiếu nguồn/văn phong, trả nhận xét và dẫn chứng |
| Backend | Điều phối job, kiểm schema/hash/evidence, tính điểm tổng, kiểm ngưỡng và cổng duyệt, lưu/dọn dữ liệu, tính thống kê và quản lý ngân sách |
| AI viết báo cáo | Diễn giải số liệu/dẫn chứng backend đã tổng hợp; không tự tạo số đếm hoặc nguyên nhân không có căn cứ |
| Người dùng | Xem điểm/nhận xét và chọn duyệt; AI không thay quyết định này |

Evaluator có cấu hình provider/model riêng với writer; ưu tiên tách vai trò viết và chấm, không tự chọn một model production trong kế hoạch này. Chỉ so sánh kỳ khi rubric/evaluator/phạm vi đủ tương đương; đổi cấu hình hoặc chủ đề phải ghi rõ. Điểm cao ở tập bài đã được chọn không chứng minh mọi bài AI tạo đều tốt.

## 5. Chấm ngay và lịch tổng hợp báo cáo

- Chấm từng candidate ngay sau khi lưu nội dung/checkpoint thành công, qua queue riêng với timeout và retry hữu hạn. Lỗi evaluator không biến thành lỗi tạo bài hoặc điểm 0; bài vẫn đọc được nhưng chưa đủ điều kiện duyệt.
- Hết ngân sách chấm hoặc thiếu cấu hình: ghi trạng thái/lý do, chưa mở Duyệt/Apply; cho chạy lại có quyền khi đã giải quyết. Không lấy mẫu bỏ qua chấm những bài cần duyệt.
- Khi người dùng duyệt/Apply: giữ archive và kết quả chấm đúng phiên bản đã chọn. Bản chưa được duyệt cùng kết quả tạm vẫn hết hạn sau 2 ngày mặc định.
- Báo cáo định kỳ đề xuất hàng tuần, múi giờ `Asia/Bangkok`; có chạy thủ công và lựa chọn hàng tháng. Mặc định tổng hợp điểm đã lưu của các bài được duyệt, không chấm lại toàn bộ.
- Kiểm tra mẫu hoặc chấm lại khi đổi rubric/evaluator phải có phạm vi, ngân sách và phiên bản riêng. Giữ điểm tại lúc duyệt; điểm mới thấp hoặc phát hiện lỗi chỉ tạo cảnh báo kiểm tra, không sửa ngược quyết định cũ.
- Kỳ dùng khoảng thời gian cố định `[từ, đến)`, quy đổi UTC để query. Bài đến sau mốc chốt được xử lý ở kỳ tiếp theo hoặc revision có ghi chú.
- Khóa chống chạy chồng và unique key trong DB ngăn chấm trùng khi nhiều worker. Retry hữu hạn/backoff theo lỗi, checkpoint tiến độ, tiếp tục phần chưa xong sau restart; không gọi lại bài đã chấm thành công.
- Tách ngân sách chấm trước duyệt, chấm lại/kiểm tra mẫu và AI viết báo cáo; gồm retry. Báo cáo ghi phạm vi dữ liệu, số bài có điểm hợp lệ và mẫu kiểm tra nếu có; kết quả thiếu dữ liệu phải hiện rõ.
- Lưu attempt/usage có thật; timeout có thể đã phát sinh phí nên đánh dấu chi phí chưa biết. Cần có trần request/token dự phòng khi chưa xác định được giá, thay vì hiểu null là miễn phí.
- Khi triển khai, cấu hình evaluator/ngân sách trước khi bật chấm ngay và cổng duyệt. Mặc định lịch báo cáo/chấm lại tắt cho đến khi cấu hình lịch và ngân sách; theo dõi lần chạy cuối/lần tới, hàng đợi và lỗi.
- Trên host, worker và Laravel scheduler chạy bằng cơ chế quản lý process của môi trường triển khai. Dùng scheduler của app; không tạo automation Codex để vận hành website.

## 6. Checklist triển khai

### Giai đoạn 1 — lưu bền vững (`DONE`, 07/10/2026)

**Kết quả cần đạt:** mỗi bài Post AI được duyệt có bản gốc và nguồn độc lập với run tạm; candidate chưa duyệt vẫn hết hạn cùng danh sách Content AI. Đợt này làm backend lưu trữ/phục hồi và kiểm thử; phần chấm, lịch chạy và màn hình báo cáo được triển khai ở các giai đoạn tiếp theo.

Khảo sát ngày 07/10/2026: [ProcessAiImportJob](../../app/Jobs/ProcessAiImportJob.php) ghi `ready` và checkpoint tạm trong `ai_imports`; [pipeline](../../app/Services/Ai/Content/Pipelines/ArticleGenerationPipeline.php) giữ checkpoint theo hash sau từng bước. [Controller](../../app/Http/Controllers/Admin/AiImportController.php) tạo run mới khi regenerate nhưng retry dùng lại UUID; sửa candidate thay `result_json.draft` nhưng không thay checkpoint gốc. Vì vậy chỉ approve/Apply mới chuyển bản đã chọn vào kho bền vững.

Thứ tự triển khai:

| ID | Phần việc | Đầu ra / tiêu chí |
| --- | --- | --- |
| G1-01 | Chốt dữ liệu và loại bản | Chỉ Post AI được duyệt; phân biệt content AI mới, field kế thừa từ bản người sửa, deterministic/nguồn thuần và legacy thiếu original. Ghi rõ nhóm output để bài chỉ đổi title/SEO không bị tính là bài content mới |
| G1-02 | Migration/model/service | Đã thêm `AiArticleArchive` và `AiArticleArchiveService`; schema snapshot JSON, hash canonical, version và index. Khóa `(run_id, generation_no)`; explicit retry tăng số lần tạo dưới lock, retry kỹ thuật của worker giữ cùng số. Không FK cascade theo AiImport/Post |
| G1-03 | Lưu khi duyệt | Checkpoint sanitize trước `ready`; chỉ archive và Post/provenance/decision cùng transaction khi approve/Apply. Không giữ transaction qua HTTP gọi AI. Trùng khóa với cùng hash trả bản đã lưu; khác hash báo conflict, không ghi đè |
| G1-04 | Lifecycle và phục hồi | Candidate failed/cancelled/expired/rejected không tạo archive. Giữ metadata duyệt/Apply tách khỏi payload gốc; phân biệt lỗi lưu archive với lỗi tạo bài. Phục hồi checkpoint tạm chỉ hoàn tất `ready`, không tự archive hay gọi lại AI |
| G1-05 | Cleanup và dữ liệu cũ | Candidate chưa chọn/từ chối/lỗi bị xóa cùng checkpoint theo retention. Với run đã duyệt v1, khóa/đối chiếu archive trước khi dọn; lỗi giữ bản đã chọn và ghi skipped/lý do. Legacy không đoán original. Image run giữ lifecycle riêng; kho approved chưa có lịch tự xóa trong giai đoạn 1 |
| G1-06 | Kiểm thử và nghiệm thu | DB/storage cô lập, provider fake và kiểm regression; ghi bằng chứng vào QA, cập nhật checklist. Đủ dữ liệu để giai đoạn 2 đọc bài/nguồn mà không phụ thuộc AiImport |

Checklist thực hiện:

- [x] G1-01: contract v1, Post text, origin từng field và `has_generated_content`; title/SEO-only, inherited, deterministic và source-only tách rõ.
- [x] G1-02: migration mới, model/service, `(run_id, generation_no)`, payload/source/content hash và index; không FK/cascade theo run/Post/user.
- [x] G1-03: checkpoint hoàn tất commit trước `ready`; archive chỉ tạo trong transaction approve/Apply cùng Post/provenance/decision. Ghi lặp giữ bản đầu, hash khác chặn conflict.
- [x] G1-04: failed/cancelled/expired/rejected không có archive; retry/worker cũ; decision/Apply metadata và phục hồi checkpoint có kiểm hash; lỗi archive rollback duyệt.
- [x] G1-05: cleanup/delete candidate chưa duyệt không tạo archive; archive approved vẫn độc lập; có command giới hạn/dry-run/approved opt-in; không lấy candidate đã sửa giả bản AI gốc.
- [x] G1-06: 33 test kho; toàn bộ backend **472 test/3.314 assertions đạt**. Kiểm đúng ví dụ ba candidate trong một session chỉ duyệt bản 2; source/original, rollback, retry, cleanup, legacy và bảo mật. [Kết quả kiểm thử và vận hành](../qa/AI_ARTICLE_ARCHIVES_2026-10-07.md).

**Kết quả đã bàn giao:** [AiArticleArchiveService](../../app/Services/Ai/Content/Archives/AiArticleArchiveService.php) và [AiArticleSnapshot](../../app/Services/Ai/Content/Archives/AiArticleSnapshot.php). Worker giữ checkpoint tạm. Theo luồng mới, chấm trước duyệt đọc bài/nguồn/ngữ cảnh của candidate tạm; báo cáo hoặc kiểm tra lại đọc `source_snapshot_json`, `draft_snapshot_json`, `context_snapshot_json` và hash của archive đã approved mà không cần AiImport còn tồn tại. Trace giữ task/hash/prompt/schema/usage của checkpoint thực sự dùng, không giữ raw response hoặc sample evidence của profile.

**Kích hoạt local:** dùng lại migration đã chạy (batch 15), không thêm migration cho thay đổi chính sách. Kiểm hash các cột cũ xác nhận 4 Post, 20 run và 5 provenance giữ nguyên. Kho mới hiện 0 bản; bắt đầu nhận candidate được duyệt/Apply. Dry-run theo bộ lọc approved kiểm kê **1 run legacy**, không ghi dữ liệu. Bộ 19 bài/điểm QA lịch sử giữ nguyên và là tập khác với các run local này.

**Phục hồi:** `php artisan ai-articles:archive --run-id=<UUID> --dry-run`, bỏ `--dry-run` khi phục hồi checkpoint của candidate đã approved. Có `--include-legacy` và `--limit=1..1000`; candidate chưa duyệt không được ghi. Legacy chưa chứng minh được original giữ `draft_snapshot_json=null`, `content_origin=legacy_unverified`. Với run v1 thiếu checkpoint/archive hợp lệ, command báo thiếu original hoặc sai hash và giữ run; không tự gọi AI để lấp lịch sử. Chưa có màn hình kho hoặc lịch tự xóa archive; evaluator/lịch/báo cáo/Admin tiếp tục ở giai đoạn 2–5.

**Dữ liệu tối thiểu:** run/session/parent, số lần tạo, actor, trạng thái và thời điểm; source blocks/HTML đã chuẩn hóa cùng hash; title/content/excerpt/SEO gốc theo nhóm output; brief, profile, language, phiên bản prompt/schema, provider/model và diagnostics allowlist. Dùng [ArticleInputHasher](../../app/Services/Ai/Content/Data/ArticleInputHasher.php) cho hash canonical; không sao chép toàn bộ `ai_connection`, API key/header/raw response. Redact thông tin xác thực trong URL/metadata; nguồn riêng tư được giữ trong DB/private storage, không tự đưa ra public.

**Lỗi lưu và dữ liệu cũ:** lỗi checkpoint có exception/mã riêng và chưa báo ready; lỗi archive khi duyệt rollback Post/quyết định, giữ checkpoint để thử lại. Cleanup chỉ chặn xóa bản approved v1 thiếu/hỏng original; candidate chưa chọn được dọn bình thường. Duyệt candidate legacy vẫn tạo Post, archive giữ metadata/content null với nhãn `legacy_unverified`. Command kiểm kê/backfill chỉ chọn approved và legacy cần opt-in; không nằm trong migration, không lấy bản đã sửa giả output AI ban đầu. Bộ 19 bài/điểm lịch sử giữ nguyên.

**Kiểm thử bắt buộc:** duyệt lưu đủ nguồn/bài; ba candidate chỉ chọn bản 2 thì chỉ bản 2 được giữ lâu dài; worker lặp không ghi đè edit; hai writer không ghi đè checkpoint; retry bỏ checkpoint chưa chọn và worker cũ không tác động generation mới; sửa/Apply hoặc ảnh đến sau không đổi original; cleanup/xóa run/Post không mất archive đã duyệt; lỗi archive/audit rollback Post/quyết định; unapproved/rejected/failed/cancelled/expired không tạo kho; output kế thừa/deterministic được phân loại đúng; legacy không giả original và snapshot không lộ secret.

Giai đoạn 1 đã đạt các kiểm thử này và truy được bản gốc sau cleanup trong DB cô lập; migration local cũng đã chạy. Chấm AI, thử model thật, bật scheduler và triển khai lên host còn thuộc các giai đoạn tiếp theo.

**Ranh giới với kế hoạch mới:** bằng chứng G1 xác nhận cơ chế lưu bài, chưa xác nhận cổng điểm hay lưu kết quả chấm. Hành vi duyệt legacy ghi trên đây là hành vi hiện có; khi triển khai cổng mới, không tự cho legacy thiếu nguồn/điểm đạt điều kiện. Post đã duyệt trước đó không bị hủy; lịch sử thiếu dữ liệu giữ nhãn riêng.

### Giai đoạn 2 — bộ chấm từng bài (`TODO`, ưu tiên tiếp theo của Q-01)

- [ ] Chốt rubric/version, công thức điểm tổng, prompt/schema có dẫn chứng và các trạng thái đủ nguồn/đạt dữ kiện. Ngưỡng duyệt đã chọn là **> 4/5**.
- [ ] Schema/model/service lưu điểm tạm theo candidate/generation/hash; migration mới nếu cần, không thay migration G1 đã chạy. Kết quả được duyệt tồn tại độc lập với run tạm.
- [ ] Service/job chấm nguồn + bài + brief/profile; cấu hình evaluator riêng qua provider/catalog hiện có; timeout, retry hữu hạn, khóa chống trùng và ngân sách.
- [ ] Validator điểm/evidence/source references; backend tính tổng và kết luận đủ điều kiện. Lưu attempt/usage/chi phí an toàn; lỗi chấm không tạo điểm giả.
- [ ] Thử trên bộ bài đã lưu, đối chiếu với [lượt chấm AI hiện tại](../qa/FIX1_ACCEPTANCE_2026-10-07/ai-review/README.md); ghi chênh lệch có lý do, không sửa điểm lịch sử. Chuẩn bị đối chiếu người đọc khi có phiếu.

### Giai đoạn 3 — chấm ngay, cổng duyệt và vòng tròn điểm (`TODO`, sau giai đoạn 2)

- [ ] Enqueue chấm khi nội dung/checkpoint đã lưu thành công; phân biệt retry kỹ thuật, regenerate và bản được sửa; bỏ kết quả đến muộn sai hash/generation.
- [ ] Cổng backend cho mọi đường duyệt/Apply vào Post: kết quả hợp lệ/đúng phiên bản, điểm > 4, đủ nguồn và đạt dữ kiện; chưa đủ điều kiện trả lý do rõ ràng.
- [ ] Vòng tròn điểm bên phải mỗi bài trong Danh sách Content AI; trạng thái chờ/đang chấm/lỗi/thiếu căn cứ; bấm mở điểm thành phần, nhận xét, dẫn chứng và version.
- [ ] Chấm lại bản đã sửa trước duyệt, giữ điểm/bản AI gốc riêng; snapshot/hash bản đã sửa được duyệt gắn đúng kết quả của nó.
- [ ] Giữ điểm được chọn cùng transaction duyệt/Apply và archive; lỗi rollback. Cleanup xóa điểm tạm chưa chọn sau 2 ngày, không xóa bài/điểm đã lưu lâu dài.
- [ ] Quyền xem/chấm lại/duyệt và trạng thái nút Duyệt/Apply; kiểm cả API gọi trực tiếp. Kiểm thử ba bản đều có điểm tạm, chỉ bản 2 được chọn tồn tại sau cleanup.
- [ ] Ghi QA chấm trước duyệt: ranh giới điểm 4,00, lỗi/thiếu nguồn, điểm cũ sau edit, evaluator lỗi, rollback và không tự duyệt.

### Giai đoạn 4 — lịch tổng hợp, số liệu và bài phân tích (`TODO`, sau giai đoạn 3)

- [ ] Command điều phối + ProjectScheduleRegistry; lịch tuần/tháng, múi giờ, period/cutoff, khóa chống chồng và phục hồi sau downtime.
- [ ] Tổng hợp điểm đã lưu của các bài được duyệt; backend tính số đếm, trung bình, trung vị, phân bố và điều kiện so sánh. Tách điểm gốc/điểm bản biên tập và legacy thiếu dữ liệu.
- [ ] Chấm lại/kiểm tra mẫu chỉ khi có cấu hình hoặc yêu cầu; giới hạn số bài/token/chi phí riêng, giữ phiên bản điểm tại lúc duyệt và cảnh báo sai lệch.
- [ ] AI diễn giải từ số liệu/dẫn chứng, kiểm schema và link; có bản thống kê đọc được nếu bước viết báo cáo lỗi.
- [ ] Lưu report/revision/status/usage; partial/zero-article phải diễn đạt đúng, không tạo kết luận khi không có dữ liệu.
- [ ] Nếu bật thống kê tỷ lệ tạo/đạt/bị loại: bộ đếm tổng hợp có khóa chống trùng trước cleanup, không giữ nội dung bản bị loại và không suy mẫu số từ kho approved. Đây là phần mở rộng, không chặn MVP báo cáo bài đã duyệt.

### Giai đoạn 5 — báo cáo Admin và cấu hình (`TODO`, sau giai đoạn 4)

- [ ] Màn hình Báo cáo chất lượng AI: danh sách kỳ, chi tiết phân tích, thống kê, lỗi và link bài/nguồn/điểm.
- [ ] Xem điểm được giữ của từng bản đã duyệt và lịch sử kiểm tra lại; phân biệt bản AI gốc/bản đã biên tập, điểm tại lúc duyệt/điểm mới và đánh giá AI/phiếu người đọc.
- [ ] Quyền xem/quản lý/chạy/cấu hình; kiểm scope theo người dùng/domain, bảo vệ nguồn riêng tư và sanitize preview HTML.
- [ ] Settings evaluator/bộ tiêu chí có version/lịch/retention/budget; ngưỡng duyệt hiện > 4/5, đổi chính sách phải có version rõ. Hiển thị last run/next run và trạng thái thật. Thông báo trong Admin; email/webhook là phạm vi bổ sung nếu được chọn sau.
- [ ] Chạy báo cáo/chấm lại thủ công có quyền, hiện phạm vi/ước tính số bài trước khi enqueue; phản hồi async và lý do lỗi dễ hiểu.

### Giai đoạn 6 — kiểm thử và host (`TODO`, trước bật lịch)

- [ ] Kiểm archive/điểm đã duyệt tồn tại sau cleanup, điểm tạm bị dọn, rollback, regenerate/version và run không có bài; không lộ secret.
- [ ] Kiểm điểm đúng 4,00 bị chặn; trên 4 nhưng lỗi dữ kiện/thiếu nguồn vẫn bị chặn; API trực tiếp không vượt cổng và sửa bài không dùng điểm cũ.
- [ ] Kiểm schema sai, nguồn thiếu, prompt trong nguồn, evidence không tồn tại, permission/scope, legacy và evaluator lỗi; không gán điểm 0 hoặc tự cho đạt.
- [ ] Kiểm hai worker, job trùng, restart/retry/timeout, budget hết và chi phí unknown; không chấm trùng bản đã thành công.
- [ ] Kiểm ranh giới kỳ/múi giờ, kỳ rỗng, mẫu/coverage, số liệu chính xác, partial report và điều kiện so sánh.
- [ ] Kiểm vòng tròn điểm/chi tiết và Admin responsive/quyền/lỗi/loading; dùng fixture/offline trước khi chạy provider thật theo ngân sách đã chọn.
- [ ] Trên host: kiểm scheduler + worker + queue/DB/storage thật, phục hồi sau dừng process, backup/restore dữ liệu chấm và giám sát lỗi.
- [ ] Ghi báo cáo kiểm chứng vào `docs/qa/`; cập nhật checklist ở đây và Q-01 trong PLAN khi đạt tiêu chí.

## 7. Quyết định đã thống nhất và cấu hình còn cần chốt

| Quyết định | Trạng thái / lựa chọn |
| --- | --- |
| Thời điểm chấm | Đã thống nhất: từng candidate có bài AI mới được chấm trước duyệt |
| Hiển thị | Đã thống nhất: vòng tròn điểm bên phải từng bài trong Danh sách Content AI, mở chi tiết khi bấm |
| Điều kiện duyệt | Đã thống nhất: điểm tổng > 4/5, đủ nguồn và qua kiểm tra dữ kiện; backend kiểm, người dùng quyết định |
| Lưu bài/điểm | Đã thống nhất: chỉ bản được duyệt giữ lâu dài; bản chưa chọn và điểm tạm hết hạn sau 2 ngày mặc định |
| Công thức điểm | Đề xuất: trung bình cộng năm tiêu chí, trọng số bằng nhau; chốt cùng rubric/version trước khi triển khai |
| Báo cáo định kỳ | Đã thống nhất: tổng hợp điểm các bài đã duyệt; đề xuất tuần/tháng, giờ cụ thể còn cần chọn |
| Chấm lại | Chỉ kiểm tra mẫu/đổi rubric hoặc evaluator/yêu cầu riêng; cỡ mẫu và lịch kiểm tra còn cần chọn |
| Evaluator/ngân sách/retention kho | Chọn model đã kiểm chứng, trần bài/token/chi phí và thời hạn giữ archive/báo cáo khi triển khai |
| Thống kê bài bị loại | Mở rộng tùy chọn bằng bộ đếm tổng hợp, không lưu dài hạn bài bị loại; không nằm trong dữ liệu archive hiện có |

Đây là yêu cầu của kế hoạch mới, chưa phải cấu hình đã bật trên hệ thống. Điểm 4,49 của 19 bài lịch sử giữ nguyên; không dùng làm ngưỡng hoặc điểm tự điền.

## 8. Điều kiện hoàn thành

- Mỗi candidate có nội dung AI mới được chấm trước duyệt và có vòng tròn điểm bên phải; xem được năm tiêu chí, nhận xét, lỗi nguồn và dẫn chứng.
- Mọi đường duyệt/Apply kiểm điểm > 4, đủ nguồn, đạt dữ kiện và đúng hash/phiên bản; chưa chấm/lỗi/điểm cũ không được duyệt.
- Chỉ bài được chọn cùng nguồn/ngữ cảnh/điểm được lưu lâu dài, tồn tại sau cleanup; bản chưa chọn và điểm tạm được dọn sau 2 ngày mặc định.
- Sửa/regenerate không dùng nhầm điểm; bản AI gốc và kết quả lịch sử không bị ghi đè. Legacy thiếu dữ liệu không được giả điểm.
- Báo cáo định kỳ dùng điểm đã lưu; chấm lại có phạm vi/version riêng. Lịch chạy trên host phục hồi được sau lỗi/downtime và không chấm trùng.
- Chủ dự án xem được báo cáo theo kỳ với số liệu tính chính xác, dẫn chứng và link từng bài; thiếu dữ liệu/coverage/usage hiển thị rõ.
- Có cấu hình quyền, lịch, retention và ngân sách; không lộ dữ liệu riêng hoặc secret.
- Kiểm thử có bằng chứng; hệ thống không tự sửa nội dung, prompt/model hoặc đăng bài theo điểm.
