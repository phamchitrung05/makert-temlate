# Bộ nguồn và đánh giá chất lượng Task 2

**Cập nhật 2026-10-05 — B đã được gỡ:** tạo bài và đánh giá mới chỉ chạy C
(Analyze + Plan → Write → Edit). `evaluate.php` mặc định C; mọi yêu cầu chạy
B bị chặn trước provider. Một ca tối đa ba call. Report/export còn đọc study
B/C cũ để giữ lịch sử; không ghi đè artifacts, hash, nhãn hoặc CSV đã lưu.
Phiếu mới của C có một ứng viên mỗi ca, không yêu cầu chọn giữa X/Y.

**Phạm vi nghiệm thu Task 2 cập nhật:** theo lựa chọn của chủ dự án ngày
2026-10-05, hai người đọc chấm facts/văn phong/công sửa ở một đợt riêng.
Nghiệm thu kỹ thuật và kết quả gate không thay thế đợt chấm đó. CSV cũ giữ
nguyên các ô chưa chấm; chưa có quyết định đạt chất lượng hoặc rollout.

Phiên hiện tại là [corpus v2](corpus-v2/manifest.json): **20 nguồn khác nhau**,
12 nguồn giữ nguyên từ v1 và 8 nguồn bổ sung. Có 6 nguồn tiếng Việt,
tin/du lịch/thông báo có điều kiện, một bảng và 18 metadata ảnh trên 8 nguồn.
[Study](study-2026-10-05-corpus-v2/summary.json) đã chạy 20 cặp B/C, **79 call**:
B ready 20/20, C ready 14/20. [Báo cáo chính](study-2026-10-05-corpus-v2/report-final/report.md)
giữ cả năm gate failures và một Writer timeout; tổng token C chưa xác định
vì một call thiếu usage. Không kết luận chất lượng văn phong từ tỷ lệ ready.

Mục 5 có [phiếu người 1](study-2026-10-05-corpus-v2/review-v2/reviewer-1.html),
[phiếu người 2](study-2026-10-05-corpus-v2/review-v2/reviewer-2.html) và
[hướng dẫn](study-2026-10-05-corpus-v2/review-v2/README.md).
Hai người chấm riêng trước khi xem báo cáo/nhãn thật; mỗi người hiện **0/39**
bài có output được chấm. Q33-C không có candidate nên giữ trống, các bài có
output bị gate chặn vẫn được đối chiếu. Không điền điểm/facts/phút sửa bằng AI.
Mở HTML với `review.js`/`review.css` cùng thư mục; tải CSV hoặc xem CSV để lưu UTF-8.

Nguồn cũ đã đóng băng tại [manifest v1](corpus-v1/manifest.json): 25 ca từ 24 nguồn độc lập. Q18/Q21 dùng hai yêu cầu khác nhau trên cùng bài HTML người dùng cung cấp. Các nguồn kỹ thuật là đơn vị nội dung chọn từ docs chính thức ở commit cố định; `selection`, URL, license/attribution, hash raw và hash snapshot được ghi trong manifest. Không mặc định đây là 25 bài đầy đủ hoặc corpus đã được người đọc duyệt.

Corpus v1 chủ yếu kỹ thuật; v2 bổ sung tin/du lịch tiếng Việt và bảng điều kiện
gói dịch vụ. V2 vẫn là nguồn được chọn/trích đoạn, chưa có benchmark độc lập
hoặc kiểm pixel ảnh/approved asset regenerate. Bộ facts chuẩn còn chờ người
đọc lập từ nguồn. Các giới hạn này được giữ trong manifest, không lấp bằng
facts hoặc điểm do AI tự tạo.

## Thử nghiệm

- [Pilot prompt 2.0](pilot-2026-10-05/summary.json): Q01/Q13, hai nhánh B/C, 4 call thực; C dừng ở Analyzer vì dẫn chứng chứa markup thay vì văn bản nguồn. Artifacts lỗi được giữ nguyên.
- [Pilot prompt 2.1](pilot-2026-10-05-prompt21/summary.json): cùng Q01/Q13, 8 call thực, B/C đều qua gate kỹ thuật. Prompt yêu cầu excerpt liền mạch từ một `source.blocks[].text`; không tự nới gate evidence.
- [Pilot mở rộng Q02/Q07/Q18](pilot-2026-10-05-prompt21-extra/summary.json) chạy cùng model/profile và prompt 2.1; 11 call, giữ đủ output thành công/lỗi.
- [Gói chấm năm ca](pilot-2026-10-05-five-cases/review/index.html) gộp hai đợt có cùng manifest và input profile/prompt hash; không gọi model thêm. Tổng **19 call/97.837 token**, B ready 4/5, C ready 2/5; 4 output bị chặn vì link nguồn (3) hoặc fact ID không có trong Analyze (1). Đây là kết quả kỹ thuật của pilot, chưa phải điểm văn phong hoặc nghiệm thu rollout.
- [Pilot prompt 2.2](pilot-2026-10-05-prompt22/summary.json): Q02/Q07/Q18, **12 call/93.809 token**, B ready 3/3, C ready 2/3. Q18-C giữ “hai tầng” thay “2 tầng” nên gate ban đầu chặn nhầm. Sau sửa đối chiếu số tầng 1–9, [audit outputs đã lưu](audit-2026-10-05-prompt22-runs.json) cho 6/6 qua gate, **0 call thêm**; trạng thái/artifacts của phiên model được giữ nguyên. Đây là kiểm lại gate, chưa phải điểm diễn đạt/facts do người đọc chấm. Connection local khác phiên 2.1; không dùng hai phiên để suy ra chất lượng văn phong tăng.

B là một lượt với brief/profile; C là Analyze → Write → Edit. Model thực `gpt-6.1-sol`, profile QA id 3/version 4. Chi phí tiền để `null`: provider chưa cung cấp bảng giá được xác nhận; token/latency trong từng artifact là số thật nếu có. Không Apply/Publish output đánh giá, không sửa Settings/default.

**Chưa có kết luận về bài hay hơn.** Gói chấm HTML và CSV để hai người đọc chấm độc lập. Không mở `summary.json` hoặc `blind-key.json` trong lúc chấm. Fact errors/coverage là gate riêng; bốn tiêu chí diễn đạt cộng brief/style, phút sửa, số sửa facts/câu chữ và số đoạn thêm/xóa được ghi riêng. Ô trống là chưa chấm, không phải điểm 0.

## Baseline A

Đã lưu [config prompt tại Git HEAD trước Task 2](baseline-reference/ai-agent-at-HEAD.php.txt), commit `aaf019f0625d497dbd8b10226b25f7959ffb69b2`, SHA-256 `6d8d35b8775b28a143f7ad0cb6d2303923c99688ac0fad0d80753db300f8dac1`.

File này chưa phục hồi toàn bộ request cũ: Settings/system prompt/model/options tại thời điểm cũ chưa được xác nhận. Vì vậy A vẫn **unavailable**, không đổi tên B thành A hoặc rollback website để giả baseline. B/C có thể chấm riêng trong khi owner cung cấp bản cấu hình/run cũ nếu còn giữ.

## Lệnh chạy từ project root

Tổng hợp hai CSV đã được người đọc chấm, **0 model call**, dùng thư mục report
mới để giữ nguyên kết quả/bảng chấm cũ:

```powershell
php -d xdebug.mode=off scripts/ai-quality/report.php --study=docs/qa/task2-quality/study-2026-10-05-corpus-v2 --manifest=docs/qa/task2-quality/corpus-v2/manifest.json --reviewer-1=CSV_NGUOI_1 --reviewer-2=CSV_NGUOI_2 --output=THU_MUC_BAO_CAO_MOI
```

Hash bundle phải khớp nguồn/output/profile/nhãn. Thiếu/trùng nhãn, score sai,
hai người trùng danh tính hoặc phiếu completed thiếu dữ kiện sẽ báo lỗi.
Không tự sửa CSV hoặc đổi bộ đề người đọc đã bắt đầu chấm. [Audit các ca lỗi](study-2026-10-05-corpus-v2/audit-failed.json)
và [numeric diagnostics](study-2026-10-05-corpus-v2/numeric-diagnostics.json)
là đối chiếu output đã lưu, không phải điểm accuracy người đọc.

Đối chiếu bốn output bị chặn trong pilot 2.1, **0 call**, không sửa candidate:

```powershell
php -d xdebug.mode=off scripts/ai-quality/audit.php
```

Kết quả đã lưu tại [audit 2.2](audit-2026-10-05-prompt22.json): Q02-C/Q07-C
qua kiểm lại trên HTML/facts cũ; Q02-B vẫn bị chặn vì URL sai, Q18-C vẫn bị chặn
vì dùng source block `S029` làm fact ID. Đây là kiểm lại offline, không đổi
trạng thái/số call/điểm của phiên 2.1. Q02-C còn cho thấy Q1 → quý I từng bị
coi là mất số liệu; quy tắc quý chỉ chấp nhận I–IV tương ứng 1–4.

Kiểm lại sáu outputs 2.2 bằng gate hiện tại, **0 call**, giữ cả Q18-C đã bị chặn:

```powershell
php -d xdebug.mode=off scripts/ai-quality/audit.php --pilot=docs/qa/task2-quality/pilot-2026-10-05-prompt22 --case=Q02-B,Q02-C,Q07-B,Q07-C,Q18-B,Q18-C
```

Đối chiếu số đếm chỉ nhận 1–9 bằng chữ ngay trước “tầng”, “nhóm”, “ngày”,
không lấy thành phần của số lớn/thập phân hoặc số có phần rưỡi thành số nhỏ.
Ngày hợp lệ bỏ số 0 đầu; ngày tiếng Anh/Việt, giờ AM/PM và thế kỷ La Mã được
đối chiếu theo cùng giá trị có ngữ cảnh. Năm đầy đủ, phút, AM/PM và danh sách
thứ trong tuần vẫn phải đúng. Năm hai chữ số chỉ chốt hậu tố, không tự suy
thế kỷ. Phiên bản `2.10` vẫn khác `2.1`; HTML được lưu không bị sửa.
[Audit C đã lưu](../TASK2_COMPLETE_2026-10-05/saved-C-gate-audit.json) kiểm
19 candidates, **0 call mới**, và giữ nguyên timeout lịch sử Q33. Semantic
grounding toàn diện vẫn cần người đọc.

Preflight chỉ kiểm hash/nguồn/ngân sách, không gọi model:

```powershell
php scripts/ai-quality/evaluate.php --case=Q01,Q02,Q07,Q13,Q18
```

Chạy một phiên mới với prototype UUID đã chọn model/profile. Một ca C tối đa ba call, năm ca tối đa 15; runner chặn trước call vượt budget, không retry riêng hoặc fallback sang B/deterministic:

```powershell
php -d xdebug.mode=off scripts/ai-quality/evaluate.php --prototype=UUID_RUN_LOCAL --case=Q01,Q02,Q07,Q13,Q18 --arms=C --current-prompts --max-calls=15 --output=THU_MUC_PHIEN_MOI --run
```

`--current-prompts` dùng prompt hiện tại nhưng giữ model/profile của prototype; thiếu flag này dùng prompt snapshot cũ. Mode luôn `three_step`, kể cả prototype cũ. Output mới phải chưa tồn tại. Manifest/hash và input prompt/profile snapshot được lưu cùng phiên; key/endpoint/header xác thực không được xuất ra artifacts.

`--request-timeout=5..600` ghi đè thời gian chờ HTTP **chỉ cho phiên QA mới**;
không đổi Settings của provider, không nhận cùng `--export-only`. Mặc định
giữ timeout của prototype. Giới hạn áp dụng riêng mỗi bước, không phải thời
gian dự kiến hoàn tất. Timeout thực được ghi ở `summary.json` và
`experiment-input.json`; khi so phiên phải nêu khác biệt này. Ca Q33 kiểm
lại ở thư mục mới với 600 giây/bước và tối đa ba call, không thay thế study
20 nguồn có timeout 200 giây/bước.

Nếu prototype local chưa có profile/pipeline Task 2, thêm
`--input-snapshot=docs/qa/task2-quality/pilot-2026-10-05-five-cases/experiment-input.json`
để đọc lại hai snapshot đã freeze. Flag này không nhận connection/key từ file;
model/connection vẫn lấy từ prototype. Ghi rõ khác biệt connection/Settings
khi chạy trên máy khác; không dùng hai phiên khác input để kết luận văn phong
tốt hơn. `--current-prompts` áp dụng sau snapshot để thử phiên prompt mới.

Hash JSON kiểm theo bytes LF lúc freeze, tương thích checkout CRLF của Git
trên Windows. Escaped newline trong source/code giữ nguyên; đổi nội dung vẫn
bị chặn trước model call và CLI trả exit 1. Không ghi lại corpus/artifacts cũ.

Render lại gói chấm đã có, **0 call**, giữ thứ tự X/Y và mọi CSV đã tồn tại:

```powershell
php scripts/ai-quality/evaluate.php --case=Q01,Q13 --output=docs/qa/task2-quality/pilot-2026-10-05-prompt21 --export-only
```

Không dùng `--run` với `--export-only`; export phải đúng các nhánh trong blind-key gốc. Muốn đổi nguồn, prompt hoặc corpus phải tạo phiên/version mới. Bộ freeze từ docs công khai có timeout, không ghi đè corpus đã có:

```powershell
php scripts/ai-quality/freeze.php --output=THU_MUC_CORPUS_MOI --html-source=DUONG_DAN_HTML_DUOC_CHON
```

Protocol/rubric và phần còn chờ ở [AI_ARTICLE_QUALITY_EVALUATION.md](../../AI_ARTICLE_QUALITY_EVALUATION.md). Các fixture kỹ thuật tổng hợp trong `tests/Fixtures` và ca MySQL dùng provider fake không được tính vào điểm văn phong hoặc token model thật.

Nghiệm thu kỹ thuật Task 2 đã chốt ở [báo cáo 2026-10-05](../TASK2_COMPLETE_2026-10-05/README.md).
Q33 có phiên C mới ba calls và audit offline sau sửa dấu hàng nghìn; raw
artifact mới vẫn giữ failed, study cũ không bị ghi lại. 19 candidates đã lưu
qua blocking gate hiện tại; đây không phải điểm người đọc hoặc một study mới
20/20 ready. Tiny Cloud/MediaLibrary/Apply đã kiểm bằng browser/API thật trên
localhost. Chấm người đọc tiếp tục ở đợt riêng theo quyết định chủ dự án.
