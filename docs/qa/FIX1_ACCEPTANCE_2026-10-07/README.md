# FIX 1 · Chuẩn bị chấm chất lượng và hoàn tất QA · 2026-10-07

Thực hiện các mục **1–3–4** theo yêu cầu chủ dự án. Chủ dự án xác nhận chưa có
phiếu chấm và muốn chuẩn bị để chấm sau. Bộ chấm đã sẵn sàng; nghiệm thu chất
lượng AI và quyết định rollout vẫn chờ người đọc/chủ dự án.

## Đánh giá sơ bộ của AI theo yêu cầu chủ dự án

Sau khi chuẩn bị bộ chấm, chủ dự án yêu cầu Codex đọc và chấm thay. Đã đối
chiếu **19/19 bài có nội dung** với source blocks/brief, ghi năm điểm văn phong
và dẫn chứng trong [bảng chấm AI](ai-review/README.md), kèm
[dữ liệu JSON](ai-review/review.json). Văn phong trung bình **4,49/5**;
Q18 **3,6/5** cần rút cảnh báo lặp. Trong phạm vi snapshot, chưa phát hiện lỗi
dữ kiện quan trọng; chưa xác minh tính đúng/hiện hành ngoài nguồn.

Đây là một đánh giá của AI, đã biết nhánh C, không phải chấm mù hoặc hai người
đọc độc lập. Danh sách facts là đề xuất của AI từ nguồn, chưa được người đọc
duyệt. Q33 vẫn chưa có bài; phút/số sửa thực tế giữ null. Hai phiếu người đọc,
bundle manifest và acceptance report giữ nguyên. Nghiệm thu vẫn chờ. Không
sửa bài gốc, chạy model hoặc đổi cấu hình trong lượt chấm này.

## Giao bộ chấm cho hai người

Giao mỗi người một bộ riêng, kèm `review.js` và `review.css` cùng thư mục:

- [Phiếu người thứ nhất](review/reviewer-1.html), [CSV trống](review/reviewer-1.csv).
- [Phiếu người thứ hai](review/reviewer-2.html), [CSV trống](review/reviewer-2.csv).
- [Hướng dẫn người chấm](review/README.md) và [rubric](../../quality/AI_ARTICLE_QUALITY_EVALUATION.md).

Mở file HTML trên máy bằng trình duyệt. Hai người dùng tên/mã riêng, đọc nguồn
và brief trước khi chấm; chấm độc lập trước khi trao đổi. Không giao báo cáo
tổng hợp, blind-key hoặc kết quả kiểm kỹ thuật trước khi họ chốt điểm.

Mỗi bài cần đối chiếu dữ kiện quan trọng với mã đoạn nguồn; ghi lỗi
critical/major/minor, dữ kiện giữ đúng/thiếu, code/bảng/link/quote/metadata ảnh;
chấm năm tiêu chí 1–5 và đo phút sửa thật. Chỉ chọn **Hoàn thành** khi đủ bằng
chứng. Tải CSV để lưu; có **Xem CSV để sao chép** nếu tải file bị chặn.
Ô trống là chưa chấm. Không dùng số token, gate hoặc nhận xét của AI để tự
điền điểm của người đọc.

Bộ mới chỉ có **C: Analyze → Write → Edit** từ study đã lưu, gồm 20 nguồn độc
lập và 19 bài có thể đọc. **Q33-C của study gốc bị timeout, không có bài cuối**:
giữ hàng này trống/disabled, không thay bằng output của phiên chạy lại. Những
bài có output nhưng từng bị gate chặn vẫn được giao để người đọc kiểm. Mỗi
người hiện hoàn thành **0/19** bài; danh tính và điểm trong phiếu người đọc
chưa được agent điền. Điểm AI chỉ nằm trong báo cáo riêng ở trên.

Hash gắn nguồn/output/experiment/nhãn với từng CSV. Bộ này khác bộ B/C lịch sử;
không trộn CSV giữa hai bộ. Không tạo mới B hoặc gọi model để xuất phiếu.

## Tổng hợp khi nhận được hai CSV

Thay hai đường dẫn CSV bằng file đã nhận, dùng thư mục báo cáo **mới**:

```powershell
php scripts/ai-quality/report.php --study=docs/qa/task2-quality/study-2026-10-05-corpus-v2 --manifest=docs/qa/task2-quality/corpus-v2/manifest.json --arms=C --review-output=docs/qa/FIX1_ACCEPTANCE_2026-10-07/review --reviewer-1="PATH_TO_REVIEWER_1.csv" --reviewer-2="PATH_TO_REVIEWER_2.csv" --criteria=scripts/ai-quality/acceptance-criteria.json --output=docs/qa/FIX1_ACCEPTANCE_2026-10-07/report-reviewed-01
```

Lệnh kiểm nguồn/hash, đủ hàng/nhãn, danh tính độc lập, phạm vi điểm và bằng chứng
facts. Sai bộ, thiếu dữ kiện ở hàng completed hoặc accuracy pass che lỗi lớn
đều bị từ chối. Lệnh chỉ đọc artifacts/CSV và ghi báo cáo mới, **0 model call**.

[Tiêu chí](../../../scripts/ai-quality/acceptance-criteria.json) hiện là
**đề xuất**, chưa có người xác nhận. Đề xuất: ít nhất 20 ca/20 nguồn, vi/en,
mọi run hoàn tất, mỗi bài không có lỗi critical/major, mọi đánh giá accuracy
pass, trung vị mỗi tiêu chí văn phong ít nhất 3/5. Giới hạn phút sửa chưa chốt,
giữ `null`. Chủ dự án cần xác nhận phạm vi corpus, giới hạn công sửa và tiêu chí
trước nghiệm thu; nếu chấp nhận khoảng trống coverage phải ghi rõ từng mục và
người xác nhận. Không tự đổi `status=approved` hoặc miễn coverage.

`acceptance.json/.md` trả passed/failed/pending cho từng điều kiện. Ngay cả khi
đủ bằng chứng, trạng thái cao nhất là `ready_for_owner_decision`; không tự
Apply/Publish hoặc phê duyệt rollout. Study gốc đang thiếu Q33 nên bộ hiện tại
phục vụ chấm/đối chiếu, chưa thể đạt đề xuất nghiệm thu toàn bộ 20 ca. Một
study C mới hoàn chỉnh phải có nguồn/output/bộ chấm mới, giữ nguyên study cũ.

## Đọc số liệu hiện có

- [Báo cáo gốc được lọc C](report/report.md): **14/20 ready**, 59 call lịch sử;
  đây là trạng thái ban đầu, không được sửa thành ready sau audit.
- [Kiểm lại output đã lưu với gate hiện tại](current-gates.json): **19/20 pass**,
  Q33 không có final candidate. Đây là kiểm kỹ thuật, không phải điểm accuracy
  hoặc văn phong và không thay kết quả study gốc.
- [Điều kiện nghiệm thu](report/acceptance.md): `criteria_not_met` theo đề xuất
  hiện tại vì ready/coverage; điểm người đọc vẫn pending. Không có kết luận
  chất lượng hoặc công sửa đã cải thiện. Chi phí tiền giữ null; một call thiếu
  usage vẫn được báo thiếu.

Không chạy model mới, sửa corpus/output lịch sử, đổi cấu hình AI đang dùng hoặc
ghi vào Post/profile trong đợt này.

## Quy tắc đã triển khai ở mục 4

| Hạng mục | Hành vi hiện tại | Phần còn chờ |
| --- | --- | --- |
| Temperature 0 | Harness nhận `--temperature=0`, validate số hữu hạn 0–2, chụp vào experiment mới; adapter gửi đúng 0 | So sánh chất lượng 0/0.2 bằng phiên C mới và chấm người; chưa đổi default 0.2 |
| Debug raw response | Giữ diagnostics allowlist; loại raw/header/key/prompt và secret lồng trong usage | Chỉ mở tính năng lưu raw nếu có yêu cầu/phạm vi riêng |
| File nguồn | Chỉ `.html/.htm`, lỗi giải thích rõ `.mhtml/.mht` chưa hỗ trợ | Parser MHTML ngoài đợt này |
| Auto model | Dùng default/fallback khả dụng; không âm thầm dùng deterministic; thiếu model/kết nối trả 422 trước queue | Chọn model cụ thể theo kết quả chất lượng/chi phí của chủ dự án |

Explicit deterministic vẫn có thể dùng trong fixture và khi được chọn rõ.
Default/fallback không tự đổi khi lựa chọn model cụ thể không hợp lệ. Không
thay model mặc định đang lưu hoặc sửa `.env`.

Preflight pilot cùng ba nguồn, chưa gọi model:

```powershell
php scripts/ai-quality/evaluate.php --manifest=docs/qa/task2-quality/corpus-v2/manifest.json --case=Q02,Q07,Q18 --arms=C --temperature=0
php scripts/ai-quality/evaluate.php --manifest=docs/qa/task2-quality/corpus-v2/manifest.json --case=Q02,Q07,Q18 --arms=C --temperature=0.2
```

Mỗi phiên dự kiến tối đa 9 call. Khi thực sự chạy, cần prototype đã chọn
model/profile, cùng frozen input, `--run --max-calls=9` và hai output directory
mới riêng; chỉ thay temperature. Đây là pilot tuning, không thay study toàn bộ.
Export lịch sử không nhận override temperature hoặc timeout.

## Browser QA mục 3

Laravel/Vue production build thật tại loopback, SQLite/storage/tài khoản riêng
trong `.zcode/fix1-settings-qa`. Không migrate DB ứng dụng, đổi `.env`, gọi AI
provider hoặc gửi SMTP thật. Tạo fixture bằng `scripts/qa/setup-settings.php`;
script từ chối ghi đè DB fixture đã tồn tại. Server dùng
`php -S 127.0.0.1:8017 -t public scripts/qa/settings-router.php`.

| Kiểm tra | Kết quả | Bằng chứng |
| --- | --- | --- |
| PNG logo/favicon qua file input, lưu và reload | API Laravel lưu file thật; tên website/ảnh/favicon đọc lại đúng; ảnh đã tải | [Desktop](branding-desktop.jpg) |
| Favicon PNG 64×32 | API thật trả 422 invalid dimensions; giữ file/draft để sửa, ảnh đã lưu giữ nguyên | [Validation](validation-real.jpg) |
| Xung đột version | Fault injection 409; draft giữ nguyên, save bị khóa, tải bản mới có xác nhận | [Conflict](conflict.jpg) |
| Lỗi tải | Fault injection 500; hiện lỗi và nút thử lại; đổi normal rồi retry tải đúng dữ liệu | [Lỗi tải](settings-failed.jpg) |
| Timeout Settings | Máy chủ trì hoãn 17 giây; frontend timeout 15 giây, thoát loading và retry được | [Timeout](settings-timeout.jpg) |
| Timeout AI defaults | API riêng chờ 17 giây; thoát loading, thông báo tiếng Việt, có tải lại và phục hồi khi API bình thường | [Timeout AI](ai-settings-timeout.jpg), [Phục hồi](ai-settings-recovered.jpg) |
| Chỉ `settings.view` | Có thông báo chỉ xem; field/upload/save bị khóa; AI tab giữ quyền riêng | [Viewer](settings-viewer.jpg) |
| Không có quyền Settings | Hiện thông báo từ chối; không có field hoặc nút save | [Denied](settings-denied.jpg) |
| Webhooks chưa hỗ trợ | Empty state nêu rõ capability, không có input giả | [Empty state](webhooks-empty.jpg) |
| Dark/mobile | Logo/favicon tải đúng, mobile 390 px xếp dọc, không tràn ngang sau layout ổn định | [Dark](branding-dark.jpg), [Mobile](branding-mobile.jpg) |

Đủ 10 tab đã được kiểm ở [QA Settings trước](../SETTINGS_2026-10-06/README.md).
Đợt này bổ sung các nhánh lỗi/quyền và upload/persistence thực. Chỉ timeout/lỗi
version/500 dùng fault injection; upload và validation dùng Laravel thật.
Read/write Settings tổng quát và AI defaults có giới hạn 15 giây, không tự
retry; mail test giữ budget riêng 120 giây. Test provider/sync không bị đổi
budget vì có thể chờ upstream. Không mở rộng Settings ở mục 2.

Phiếu HTML được kiểm bằng renderer/CSV/DOM tests. Trình duyệt trong ứng dụng
chặn giao thức file nên chưa có lượt preview trực tiếp file HTML bằng công cụ;
không vượt qua giới hạn này. Người chấm mở file trên máy khi bắt đầu.

## Kiểm chứng tự động

- Backend toàn bộ: **438 tests / 2986 assertions**, đạt; sau sửa giới hạn
  coverage/người xác nhận và hướng dẫn báo cáo, scoped **31 tests / 172
  assertions**, đạt. Các lượt có test trùng, không cộng thành tổng ca duy nhất.
- Frontend Settings/AI defaults/phiếu chấm: **7 files / 51 tests**, đạt.
- Pint, scoped ESLint, production build cuối **1 phút 16 giây** và kiểm diff đạt.

Logs phát triển ở `.zcode/fix1-settings-qa`; phiếu chấm và artifacts mới ở thư
mục này. Không commit thông tin tài khoản thật hoặc key. Server/tab QA tạm
được đóng khi kết thúc; fixture riêng giữ để tái hiện nếu cần.
