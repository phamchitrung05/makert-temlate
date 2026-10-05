# Đánh giá chất lượng bài AI — Task 2

**Trạng thái 2026-10-05:** theo quyết định của người dùng, luồng B đã được gỡ khỏi tạo bài và chạy đánh giá mới. Tạo toàn bộ bài chỉ dùng C: Analyze + Plan → Write → Edit. Cấu hình/snapshot cũ không bật lại B, và lỗi C không fallback sang B. Phiên mới có ngân sách tối đa ba call mỗi ca; `evaluate.php` mặc định C và từ chối `--arms=B`/`B,C` trước khi gọi provider.

**Dữ liệu lịch sử được giữ nguyên:** 20 cặp B/C trên corpus v2 đã thực hiện 79 call; B ready 20/20, C ready 14/20. [Báo cáo đầy đủ](qa/task2-quality/study-2026-10-05-corpus-v2/report-final/report.md) giữ cả lỗi và usage thiếu. Report/export có thể đọc artifacts cũ nhưng không chạy lại B. Hai phiếu chấm lịch sử chưa có điểm người đọc; không suy chất lượng văn phong từ việc qua gate kỹ thuật.

Ba bước không tự chứng minh bài hay hơn. Đánh giá phải trả lời: diễn đạt có tự nhiên và hữu ích hơn không, có giữ thông tin quan trọng không, công biên tập giảm bao nhiêu, với mức tăng thời gian/token nào.

Regression có 20 nguồn tổng hợp cố định tại `tests/Fixtures/AiContent/evaluation-sources.json` để kiểm source anchors/code/điều kiện/ngân sách. Đây là dữ liệu giả định offline. Corpus thật v1 đã có [manifest/hash](qa/task2-quality/corpus-v1/manifest.json), còn chờ người đọc xác nhận tính đại diện và lập facts chuẩn. Corpus chủ yếu docs kỹ thuật; Q18/Q21 dùng cùng một bài du lịch. Chưa đủ benchmark số liệu/gói trả phí, nguồn tiếng Việt và ảnh; không coi 25 ca này đã đáp ứng toàn bộ coverage dự kiến dưới đây.

## Luồng hiện tại và tên nhánh trong báo cáo lịch sử

| Nhánh | Cấu hình | Mục đích |
| --- | --- | --- |
| A | Một call và prompt cũ được lưu nguyên phiên bản trước Task 2 | Baseline hành vi người dùng đang chê |
| B | Một call với brief/profile và prompt mới; đã gỡ khỏi thực thi | Chỉ đọc kết quả đã lưu trước khi gỡ |
| C | `three_step` bắt buộc, Analyze + Plan → Write → Edit | Luồng tạo toàn bộ bài và đánh giá mới |

`AI_CONTENT_PIPELINE` không còn được đọc. A chưa có baseline được phục hồi; B chỉ tồn tại trong artifacts lịch sử. Không dùng cấu hình hoặc snapshot cũ để khôi phục đường chạy B.

Phiên mới giữ nguyên source snapshot/hash, output fields, ngôn ngữ, brief, model/provider, temperature và lựa chọn độ dài cho cùng ca. Report kiểm profile/brief/source/hash và chỉ tổng hợp C. Các kiểm tra đầu vào giữa B/C chỉ áp dụng khi đọc study lịch sử. Không Apply/Publish output đánh giá.

Ngân sách mới: năm ca C tối đa **15 call**, corpus v2 20 ca tối đa **60 call**, corpus v1 25 ca tối đa **75 call**. Run bị chặn có thể dùng ít hơn; vẫn giữ lỗi/usage thiếu trong mẫu số. Phiếu C chỉ có một ứng viên mỗi ca, không hỏi ưu tiên X/Y hoặc tính tỷ lệ B/C; hai người đọc vẫn chấm độc lập. Không tự gọi provider từ tài liệu này hoặc dùng pilot để khẳng định toàn bộ corpus đã đạt.

## Coverage 25 ca và snapshot đã chuẩn bị

Các ID và nhóm ca dưới đây là coverage mục tiêu. Snapshot v1 đã freeze/hash trước pilot; đường dẫn từng ca bên dưới và manifest ghi phần nguồn được chọn, không mặc định là toàn bài. Q04 hiện là license thay vì điều kiện gói trả phí; Q05 là bối cảnh hiệu năng thay vì benchmark độc lập; Q24 chưa có fixture asset trong corpus. Những khoảng thiếu cần bổ sung vào version mới trước nghiên cứu đầy đủ. Không đổi hash nguồn của phiên đã chạy.

| ID | Nhóm nguồn | Điểm bắt buộc kiểm tra | Snapshot/hash |
| --- | --- | --- | --- |
| Q01 | Tin cập nhật rất ngắn | Không kéo thành mở bài/kết luận dài; giữ đủ thay đổi | [Snapshot v1](qa/task2-quality/corpus-v1/Q01.json) |
| Q02 | Release có version và giới hạn số | Đúng phiên bản, đơn vị và giới hạn | [Snapshot v1](qa/task2-quality/corpus-v1/Q02.json) |
| Q03 | Breaking change/migration | Giữ điều kiện tác động, cách chuyển đổi, cảnh báo | [Snapshot v1](qa/task2-quality/corpus-v1/Q03.json) |
| Q04 | Tính năng kèm gói trả phí/license | Không biến có điều kiện thành áp dụng cho mọi người | [Snapshot v1](qa/task2-quality/corpus-v1/Q04.json) |
| Q05 | Benchmark có bối cảnh | Giữ nguồn/số liệu/điều kiện, không nâng thành cam kết chung | [Snapshot v1](qa/task2-quality/corpus-v1/Q05.json) |
| Q06 | Tutorial CLI nhiều bước | Thứ tự thao tác và command/code chính xác | [Snapshot v1](qa/task2-quality/corpus-v1/Q06.json) |
| Q07 | Tutorial PHP/Laravel có cấu hình | Giữ key/value/namespace, thụt dòng và yêu cầu môi trường | [Snapshot v1](qa/task2-quality/corpus-v1/Q07.json) |
| Q08 | Tutorial JavaScript/TypeScript | Giữ syntax, API và phiên bản; giải thích tự nhiên | [Snapshot v1](qa/task2-quality/corpus-v1/Q08.json) |
| Q09 | Bài debug/async | Không biến giả thuyết thành nguyên nhân đã xác minh | [Snapshot v1](qa/task2-quality/corpus-v1/Q09.json) |
| Q10 | Tutorial có screenshot thật | Mô tả dựa nguồn; không giả là đã nhìn thấy pixel khi chỉ có URL/alt | [Snapshot v1](qa/task2-quality/corpus-v1/Q10.json) |
| Q11 | So sánh có bảng dữ liệu | Hàng/cột/số/đơn vị đúng; không bịa lựa chọn tốt nhất | [Snapshot v1](qa/task2-quality/corpus-v1/Q11.json) |
| Q12 | So sánh trade-off | Giữ cả ưu/nhược và điều kiện, không quảng cáo một chiều | [Snapshot v1](qa/task2-quality/corpus-v1/Q12.json) |
| Q13 | Giải thích cho người mới | Thuật ngữ được giải nghĩa, ví dụ giả định đánh dấu rõ | [Snapshot v1](qa/task2-quality/corpus-v1/Q13.json) |
| Q14 | Bài sâu cho người có kinh nghiệm | Không lược bỏ điều kiện kỹ thuật để làm câu dễ đọc | [Snapshot v1](qa/task2-quality/corpus-v1/Q14.json) |
| Q15 | FAQ/list ngắn | Bullet/heading phù hợp, không áp outline bài dài | [Snapshot v1](qa/task2-quality/corpus-v1/Q15.json) |
| Q16 | Bài dài có heading lồng nhau | Coverage đủ facts quan trọng, ít lặp và chuyển đoạn hợp lý | [Snapshot v1](qa/task2-quality/corpus-v1/Q16.json) |
| Q17 | Bài nhiều code/bảng, ít văn xuôi | Gate copy/language có thể skipped/undetermined đúng lý do | [Snapshot v1](qa/task2-quality/corpus-v1/Q17.json) |
| Q18 | HTML có nav/footer/ads/related posts | Extract đúng phần bài chính, không nhập boilerplate | [Snapshot v1](qa/task2-quality/corpus-v1/Q18.json) |
| Q19 | Bài thật nằm trong form/main/section | Không xóa mất bài vì container; giữ code whitespace | [Snapshot v1](qa/task2-quality/corpus-v1/Q19.json) |
| Q20 | Nguồn tiếng Anh → bài tiếng Việt | Tự nhiên, không dịch từng câu, facts/code giữ đúng | [Snapshot v1](qa/task2-quality/corpus-v1/Q20.json) |
| Q21 | Nguồn tiếng Việt → viết lại tiếng Việt | Không exact-copy; không đổi giọng thành quảng cáo sáo rỗng | [Snapshot v1](qa/task2-quality/corpus-v1/Q21.json) |
| Q22 | Việt/Anh trộn thuật ngữ | Không coi từ kỹ thuật/proper names là sai language | [Snapshot v1](qa/task2-quality/corpus-v1/Q22.json) |
| Q23 | Bài có quote và link nguồn | Attribution/quote/link đúng, quote không bị coi như copy prose | [Snapshot v1](qa/task2-quality/corpus-v1/Q23.json) |
| Q24 | Candidate có ảnh MediaLibrary rồi regenerate | Asset ID/URL/usage giữ, ảnh không bị mất, vị trí cần review | [Snapshot v1](qa/task2-quality/corpus-v1/Q24.json) |
| Q25 | Bài có thông tin chưa rõ và số tương tự | Không bịa facts; cảnh báo số/đơn vị/phiên bản không đủ bằng chứng | [Snapshot v1](qa/task2-quality/corpus-v1/Q25.json) |

Q24 cần thêm fixture MediaAsset/file và HTML parent; chụp hash file và seed IDs trong môi trường đánh giá riêng. Các bài có nguồn sai/cũ vẫn chỉ đánh giá trung thành nguồn; nếu muốn chấm tính đúng ngoài nguồn phải cung cấp tài liệu xác minh riêng và ghi đó là research, không tính là năng lực tự có của pipeline.

Các ca nguồn quá budget, JSON sai, refusal/tool call, URL private, asset ID giả, evidence bịa, hủy/retry, stale candidate version thuộc **test kỹ thuật âm**. Ghi riêng pass/fail, không dùng một bài bị chặn vì input sai để lấy điểm văn phong 1/5.

Mỗi manifest entry nên lưu:

```json
{
  "case_id": "Q02",
  "source_snapshot_path": "CHUA_DIEN",
  "source_sha256": "CHUA_DIEN",
  "source_language": "en",
  "output_language": "vi",
  "source_origin_url": null,
  "captured_at": null,
  "requested_outputs": ["title", "content"],
  "writing_brief": {"article_type":"Tin cập nhật","audience":"Người đang dùng sản phẩm","length":"Theo lượng thông tin nguồn"},
  "writing_profile_snapshot": null,
  "important_facts": [{"id":"F1","claim":"CHUA_DIEN","source_block_ids":["S001"],"evidence":"CHUA_DIEN"}],
  "must_preserve_code_links_tables": [],
  "notes": "Chưa chuẩn bị nguồn và chưa đánh giá"
}
```

Facts chuẩn được người đọc lập từ nguồn, không chỉ lấy danh sách Analyzer làm đáp án. Nếu Analyzer bỏ một điều kiện, human coverage phải vẫn phát hiện được.

## Chấm mù và rubric

Ít nhất hai người đọc độc lập. Mỗi người nhìn source snapshot/brief và các output gắn ID ngẫu nhiên X/Y/Z; không thấy nhánh, model, số call, metadata hay prompt. Đổi thứ tự output cho từng nguồn. Chấm độc lập trước khi thảo luận; bất đồng fact được đối chiếu với evidence và ghi cách giải quyết.

**Độ chính xác là gate riêng**, không hòa vào điểm trung bình: sai điều kiện/gói/phiên bản/số/đơn vị/code, claim/benchmark/API/quote/trải nghiệm tự bịa hoặc bỏ fact quan trọng đều phải ghi lỗi và severity. Một lỗi critical có thể khiến bài không đạt dù văn phong 5/5. Số facts đúng không bù một claim gây hiểu nhầm.

| Tiêu chí | 1 — Kém | 3 — Dùng được sau sửa | 5 — Tốt |
| --- | --- | --- | --- |
| Tiếng Việt tự nhiên | Dịch sát, từ/câu gượng, nhịp đều máy móc | Có đoạn gượng nhưng hiểu rõ | Diễn đạt tự nhiên, nhịp hợp lý, ít cần sửa |
| Rõ ràng và hữu ích | Khó hiểu, thiếu bối cảnh/giải thích cần thiết | Ý chính hiểu được, phải thêm giải thích | Người đọc mục tiêu hiểu và biết dùng thông tin |
| Cấu trúc phù hợp | Mở/kết sáo rỗng, heading/bullet ép buộc | Trật tự dùng được nhưng có phần thừa | Cấu trúc theo loại bài/nguồn, chuyển ý tốt |
| Ít lặp và filler | Lặp câu/ý, kéo dài chỉ để đạt từ | Một số phần lặp có thể cắt | Mỗi đoạn có thông tin/công dụng, kết luận không lặp |
| Đúng brief/văn phong | Bỏ yêu cầu riêng, bắt chước facts bài mẫu | Phần lớn đúng nhưng còn lệch giọng/audience | Ưu tiên đúng brief, dùng profile linh hoạt |

Điểm 2/4 nằm giữa các mô tả. **Coverage** báo riêng `important_facts_preserved / important_facts_expected` với danh sách thiếu; kiểm code/link/table/quote là checklist bảo toàn, không chỉ cảm giác người chấm. Similarity backend là metric từ vựng; không dùng làm điểm “hay”, đặc biệt khi dịch khác ngôn ngữ.

Đo công biên tập bằng phút sửa đến mức có thể duyệt, số sửa fact quan trọng, số sửa diễn đạt và số đoạn xóa/thêm. Người chấm không nhìn chi phí trước khi chọn output tốt hơn; sau đó mới so lợi ích với latency/tokens/cost. Không gán thời gian biên tập bằng số ký tự diff.

## Mẫu ghi kết quả từng nguồn

| Case | Nhánh ẩn | Reviewer | Critical/major factual errors | Coverage | Naturalness | Useful | Structure | Low repetition | Brief/style | Phút sửa | Ghi chú |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Q01 | Chưa chạy | Chưa chấm | Chưa chấm | Chưa chấm | — | — | — | — | — | — | Chưa có output |

Lưu lỗi cụ thể bằng: source block/evidence → câu output → loại sai → severity → sửa cần thiết. Ví dụ minh họa cách ghi, **không phải kết quả đã chạy**: nguồn yêu cầu “gói trả phí”, output viết “mọi tài khoản đều dùng được” → `condition_changed`, critical.

Thông số kỹ thuật mỗi run ghi riêng:

```json
{
  "case_id": "Q01",
  "arm": "CHUA_CHAY",
  "run_id": null,
  "provider_model": null,
  "source_profile_prompt_schema_hashes": null,
  "status": "pending",
  "error_code": null,
  "latency_ms": null,
  "stage_usage": [],
  "total_tokens": null,
  "cost": null,
  "currency": null,
  "pricing_version": null,
  "output_artifact_path": null
}
```

Provider không trả usage thì ghi **không có dữ liệu**, không coi là 0. Nếu tính tiền, dùng bảng giá/model thực tại thời điểm chạy và lưu phiên bản/nguồn giá; không suy cost từ “ba bước gấp ba lần” vì chiều dài từng call khác nhau.

## Quyết định rollout

**Quyết định phạm vi ngày 2026-10-05:** chủ dự án chuyển hai người đọc chấm
sang đợt riêng sau khi hoàn tất code Task 2. Đây là việc còn chờ của đánh giá
chất lượng và rollout, không phải điều kiện giữ nghiệm thu kỹ thuật Task 2
mở. Các điểm facts/văn phong/phút sửa vẫn trống đến khi người đọc thực sự
chấm; gate, model call và regression không được dùng làm điểm thay thế.

Trước khi xem kết quả, chốt tiêu chí nghiệm thu với owner: không tăng factual critical errors; coverage quan trọng không giảm; ưu tiên của human reviewer/công sửa có cải thiện đủ lớn để chấp nhận latency/cost. Không tự tuyên bố một mức phần trăm là chuẩn nếu chưa được chốt.

Báo riêng số run failed bởi gate/provider/input, số bài đạt accuracy, median/phân bố từng tiêu chí, paired preference theo nguồn, median công sửa và latency/tokens. Không gộp critical error thành điểm chất lượng trung bình hoặc chỉ chọn bài C hay nhất để báo cáo. Giữ cả output lỗi và bài cần sửa trong artifact riêng có quyền truy cập phù hợp.

Nếu C chưa đạt các tiêu chí đã chốt hoặc tăng lỗi/công sửa, điều chỉnh prompt/coverage/threshold theo nhóm nguồn và chạy lại tập pilot/corpus đã version. Phiên mới chỉ chạy C; B đã gỡ và chỉ còn trong study lịch sử. Version mới là thử nghiệm mới, không sửa kết quả cũ. AI evaluator nếu thêm sau chỉ là tín hiệu phụ; không thay chấm người và không chứng minh factual correctness.

## Corpus v2 và công cụ chấm độc lập — 2026-10-05

[Corpus v2](qa/task2-quality/corpus-v2/manifest.json) giữ 12 nguồn v1 (11 phần
tài liệu kỹ thuật và một bài du lịch do người dùng cung cấp), thêm 8 nguồn web
chính thức. Tổng **20 nguồn độc lập**, gồm **6 nguồn tiếng Việt**: tin Metro,
chương trình Huế, lễ hội cà phê dự kiến, thống kê du lịch theo kỳ báo cáo, thông
báo bảo tàng lưu trữ và bài du thuyền. Bổ sung hai trích đoạn du lịch tiếng Anh
để viết tiếng Việt và bảng giới hạn thật của GitHub Actions. Các số liệu giá vé,
sự kiện và thống kê lịch sử giữ đúng năm nguồn, không dùng làm thông tin hiện hành.

Nguồn mới do `scripts/ai-quality/freeze-v2.php` chọn theo
`scripts/ai-quality/sources-v2.json`. Web opening chọn đoạn hoàn chỉnh tối đa
200 từ; ca bảng giữ nguyên bảng đầu và giải thích liền trước. Snapshot, hash
raw/excerpt, URL, thời điểm, phạm vi trích và attribution được ghi riêng. Không
sửa corpus v1. Metadata **18 ảnh ở 8 nguồn** có URL/alt/chú thích; không tải ảnh,
không coi là đã nhìn pixel hoặc đã kiểm regenerate với MediaAsset được duyệt.
Corpus vẫn thiếu benchmark độc lập và cần người đọc xác nhận tính đại diện.

`source-checklists.json` giữ toàn bộ source blocks để người đọc lập dữ kiện
quan trọng từ nguồn. Đây là dẫn chứng chờ duyệt, **không phải facts chuẩn lấy từ
Analyzer**. Mỗi người ghi claim/điều kiện kèm mã đoạn, số dữ kiện cần giữ và số
được giữ đúng; sự khác nhau giữa hai bộ facts được giữ để đối chiếu.

Phiên B/C lịch sử dùng cùng frozen source, model/provider, profile, brief và fields
title/content. Provider được resolve một lần rồi clone riêng từng nhánh; input
hash so sánh chỉ loại lựa chọn `single_step`/`three_step`. Profile/brief/source
hash phải khớp khi tổng hợp. Một lượt mỗi nhánh, B trước C; chưa đo biến thiên
nhiều lần hoặc tách ảnh hưởng cache/tải provider. 20 cặp cần tối đa **80 call**;
run bị chặn vẫn giữ trong mẫu số, token và thời gian.

`scripts/ai-quality/report.php` tạo **hai HTML/CSV riêng** ở `review-v2/`.
HTML chỉ hiện X/Y, bản nguồn, brief/văn phong và rubric; không lộ model, nhánh,
usage, prompt kỹ thuật hoặc trạng thái gate của ứng viên còn có bài. Chấm lỗi
critical/major/minor, coverage, năm tiêu chí 1–5, phút sửa thật, số sửa và
preference. Người đọc phải tự xác nhận đã đối chiếu nguồn; không tự điền 0/điểm
hoặc lấy output Analyzer làm đáp án.

Bản nháp trình duyệt tách theo mã phiên/người chấm. Tải CSV hoặc dùng **Xem CSV
để sao chép** khi trình duyệt chặn tải; lưu UTF-8. CSV gắn hash nguồn, output,
experiment/profile và nhãn; bảng của phiên khác hoặc nhãn lặp/thiếu phải báo
lỗi. Hai bảng hoàn thành phải mang danh tính khác nhau. `completed` thiếu dữ
kiện/điểm/phút sửa không được nhận; gate `pass` không thể che lỗi critical/major
hay thiếu dữ kiện quan trọng. Các ô trống giữ `null`, không biến thành điểm 0.

Báo cáo tổng hợp lỗi kỹ thuật, phân bố/median token/latency và từng cặp riêng;
facts, diễn đạt, công sửa và bất đồng của người đọc là phần riêng. Tổng hợp
lại chỉ đọc files, **0 model call**, không ghi đè CSV/artifacts/báo cáo trước.
Chưa có hai bảng chấm thật thì báo `pending_human`; chưa quyết định rollout.

**Kết quả corpus v2:** B gọi 20 lần, có đủ usage 58.305 token; C gọi 59 lần, có usage 403.038 token từ 58 call và một call Writer timeout chưa có usage. Tổng token C vẫn `null`. Trung vị latency B/C là 20,2/103,5 giây trên đủ 20 run mỗi nhánh; trung vị token B/C là 2.035/14.675 trên 20/19 run có tổng usage đầy đủ. Năm ca C bị gate số liệu chặn và một ca timeout vẫn thuộc mẫu số 20, không chạy lại hoặc sửa output để đổi kết quả. [Audit offline](qa/task2-quality/study-2026-10-05-corpus-v2/audit-failed.json) không gọi model thêm.

Mỗi reviewer hiện có **0/39 bài được chấm**; Q33-C chưa có candidate nên giữ phiếu trống/disabled. Bài có candidate nhưng bị gate chặn vẫn xuất cho người đọc đối chiếu. [Bộ chấm 1](qa/task2-quality/study-2026-10-05-corpus-v2/review-v2/reviewer-1.html) và [bộ chấm 2](qa/task2-quality/study-2026-10-05-corpus-v2/review-v2/reviewer-2.html) phải được chấm độc lập trước khi xem báo cáo/nhãn thật.

**Còn chờ:** owner/người đọc xác nhận corpus và facts chuẩn, bổ sung coverage còn thiếu nếu cần và hai reviewer chấm độc lập. Người dùng đã chọn C và yêu cầu gỡ B; việc này không đồng nghĩa C đã đạt nghiệm thu chất lượng. Phiên B/C chỉ còn lịch sử; chưa đo nhiều lần hoặc chốt ngưỡng nghiệm thu. Không dùng AI tự chấm hoặc test HTTP fake để thay nghiệm thu chất lượng.

**Hiệu chỉnh gate/prompt 2.2 (2026-10-05):** phân biệt link mục lục đầu nguồn
với link tham khảo trong body; link body phải giữ đúng href, link ngoài allowlist
nguồn bị chặn. Snapshot nguồn không bị sửa để làm output đạt. Source block,
fact và asset có namespace riêng; schema Writer/Editor giới hạn fact IDs vào
ledger Analyze đã validate. Q1–Q4 được đối chiếu với quý I–IV; số tầng 1–9 có
dạng chữ tương ứng nhưng không nhận thành phần của số lớn/thập phân hoặc tầng
rưỡi. Không đổi tùy ý số/phiên bản. Audit offline và phiên model mới ghi riêng
tại [bộ QA](qa/task2-quality/README.md); báo cáo triển khai ở FIX 1 mục 12.31.
