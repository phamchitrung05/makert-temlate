# Đánh giá chất lượng bài AI — Task 2

**Trạng thái 2026-10-05:** đã có regression kỹ thuật, corpus v1 đóng băng 25 ca từ 24 nguồn và pilot model thật B/C. Artifacts, hashes, license/attribution, kết quả lỗi và gói chấm tại [QA chất lượng](qa/task2-quality/README.md). Chưa có điểm của hai người đọc, chưa chạy đủ nghiên cứu 20–30 nguồn đại diện và chưa quyết định rollout; không suy chất lượng văn phong từ việc qua gate kỹ thuật.

Ba bước không tự chứng minh bài hay hơn. Đánh giá phải trả lời: diễn đạt có tự nhiên và hữu ích hơn không, có giữ thông tin quan trọng không, công biên tập giảm bao nhiêu, với mức tăng thời gian/token nào.

Regression có 20 nguồn tổng hợp cố định tại `tests/Fixtures/AiContent/evaluation-sources.json` để kiểm source anchors/code/điều kiện/ngân sách. Đây là dữ liệu giả định offline. Corpus thật v1 đã có [manifest/hash](qa/task2-quality/corpus-v1/manifest.json), còn chờ người đọc xác nhận tính đại diện và lập facts chuẩn. Corpus chủ yếu docs kỹ thuật; Q18/Q21 dùng cùng một bài du lịch. Chưa đủ benchmark số liệu/gói trả phí, nguồn tiếng Việt và ảnh; không coi 25 ca này đã đáp ứng toàn bộ coverage dự kiến dưới đây.

## Ba phương án so sánh

| Nhánh | Cấu hình | Mục đích |
| --- | --- | --- |
| A | Một call và prompt cũ được lưu nguyên phiên bản trước Task 2 | Baseline hành vi người dùng đang chê |
| B | `AI_CONTENT_PIPELINE=single_step`, brief/profile và nguyên tắc prompt mới | Tách lợi ích prompt khỏi lợi ích ba call |
| C | `AI_CONTENT_PIPELINE=three_step`, Analyze + Plan → Write → Edit | Kiểm hiệu quả pipeline ba bước |

Switch `single_step` hiện tại đại diện B, không tự tái tạo prompt cũ A. A cần fixture prompt cũ có hash/version và harness đánh giá riêng; không rollback website hoặc ghi đè code đang triển khai để làm baseline. Nếu chưa thu được A, báo rõ chỉ so sánh B–C.

Giữ nguyên source snapshot/hash, output fields, ngôn ngữ, brief, model/provider, temperature và lựa chọn độ dài cho cùng ca. B/C dùng cùng approved profile snapshot; A chỉ nhận thông tin tương đương nếu baseline hỗ trợ, mọi khác biệt phải ghi lại. Không đổi nguồn URL giữa các lượt để tránh so hai bài khác nhau. Không Apply/Publish output đánh giá.

Chạy một lượt mỗi nhánh cho 25 nguồn cần A25 + B25 + C75 = **125 call** nếu chạy đủ cả ba nhánh; chỉ B–C là 100 call, chưa tính retry/lỗi. Quota/chi phí phải lập theo số call thực, không chỉ số run. Chưa tự chạy provider từ tài liệu này. Khi làm thử nghiệm thật, ưu tiên pilot 5 bài rồi quyết định có chạy đủ corpus; pilot không được dùng để khẳng định toàn bộ corpus đã đạt.

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

Trước khi xem kết quả, chốt tiêu chí nghiệm thu với owner: không tăng factual critical errors; coverage quan trọng không giảm; ưu tiên của human reviewer/công sửa có cải thiện đủ lớn để chấp nhận latency/cost. Không tự tuyên bố một mức phần trăm là chuẩn nếu chưa được chốt.

Báo riêng số run failed bởi gate/provider/input, số bài đạt accuracy, median/phân bố từng tiêu chí, paired preference theo nguồn, median công sửa và latency/tokens. Không gộp critical error thành điểm chất lượng trung bình hoặc chỉ chọn bài C hay nhất để báo cáo. Giữ cả output lỗi và bài cần sửa trong artifact riêng có quyền truy cập phù hợp.

Nếu C không tốt hơn B hoặc tăng lỗi/công sửa, điều chỉnh prompt/coverage/threshold theo nhóm nguồn và chạy lại tập pilot/corpus đã version. Version mới là thử nghiệm mới, không sửa kết quả cũ. AI evaluator nếu thêm sau chỉ là tín hiệu phụ; không thay chấm người và không chứng minh factual correctness.

**Còn chờ:** owner/người đọc xác nhận corpus và facts chuẩn, hoàn thiện coverage nguồn, phục hồi request baseline A nếu có, hai reviewer chấm độc lập và chạy nghiên cứu đầy đủ sau pilot. B/C có thể chấm riêng khi A chưa phục hồi. Harness và artifacts thật đã có; không dùng AI tự chấm hoặc test HTTP fake để thay nghiệm thu chất lượng.
