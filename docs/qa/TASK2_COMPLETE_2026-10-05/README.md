# Task 2 — Hoàn tất kỹ thuật, 2026-10-05

**Kết quả:** Task 2 `DONE` trong phạm vi kỹ thuật. Chủ dự án đã chuyển hai
người đọc chấm chất lượng sang đợt riêng, chọn giữ Tiny Cloud trên domain
được phép và tự đăng nhập tab kiểm thử. Toàn FIX 1 vẫn `IN PROGRESS`.
Không có điểm người đọc hoặc kết luận rollout trong báo cáo này.

## Gate số liệu

Năm ca C Q26/Q27/Q28/Q30/Q32 trước đây bị chặn vì cách viết số tương đương:
ngày có số 0 đầu, số đếm bằng chữ, giờ 12/24, liệt kê thứ trong tuần và
thế kỷ theo số thứ tự/La Mã. `ArticleNumberNormalizer` xử lý riêng việc
đối chiếu token; không sửa HTML hoặc candidate được lưu.

Ngày/giờ phải hợp lệ và giữ giá trị; năm hai chữ số không tự suy thế kỷ.
Số đếm bằng chữ chỉ nhận theo đơn vị được hỗ trợ; tập thứ phải đủ và đúng,
không nhận sai/nhân đôi thứ. Dấu hàng nghìn chỉ được gộp trong ngữ cảnh số
đếm nguyên rõ ràng. Version `2.10` vẫn khác `2.1`; tiền, số thập phân và
số đứng ở ngữ cảnh khác không được dùng để che facts thiếu.

[Audit output C đã lưu](saved-C-gate-audit.json) kiểm đủ 19 candidates qua
blocking gate hiện tại, **0 call mới**. Q33 cũ vẫn không có final candidate.
Đây là kiểm tra kỹ thuật, không phải điểm văn phong/facts do người đọc chấm.

## Q33 — phiên model mới và audit offline riêng

[Corpus Q33](Q33-corpus/manifest.json) giữ source bytes/hash của corpus v2.
Chạy riêng C Analyze + Plan → Write → Edit với prompt 2.2 và snapshot
profile/model từ prototype; `--request-timeout=600`, tối đa ba request.
Flag timeout chỉ ảnh hưởng phiên QA mới, không đổi provider Settings.

| Bằng chứng | Kết quả |
| --- | --- |
| [Phiên model thật](Q33-C-600s/summary.json) | 3 calls, 37.608 tokens, 321.634 ms tổng cộng |
| Thời gian từng bước | Analyze 144.714 ms; Write 88.408 ms; Edit 88.491 ms |
| [Artifact gốc](Q33-C-600s/Q33-C.json) | `failed / AI_QUALITY_GROUNDING`, giữ nguyên |
| [Audit sau sửa gate](Q33-gate-audit.json) | Blocking gate pass, 0 calls thêm |
| [Report từ raw artifacts](Q33-report/report.json) | Giữ kết quả failed của phiên gốc; không viết lại thành ready |

Output model mới viết `50.000/10.000/1.000` thay cho số đếm nguyên
`50,000/10,000/1,000` của nguồn. Sau bổ sung đối chiếu ngữ cảnh số đếm và
biên bảng bị nối text, output qua gate khi kiểm offline. Artifact gốc có
SHA-256 `c0537db4ffd94889aa19d12ef4b9da479d0ad1a867e6ea661f64ddd1c3ae70e9`.

Phiên mới không timeout, nhưng mỗi bước đều dưới 200 giây nên không chứng
minh 600 giây là bắt buộc. Timeout 600 khác study cũ 200 giây/bước; không
gộp hai phiên thành cùng một thí nghiệm. Gate còn warning sáu số cần editor
rà soát và semantic grounding `undetermined`; không kết luận mọi facts đã đúng.

## Tiny Cloud thật, media và Apply

Browser dùng `http://localhost:8000`, Tiny Cloud thật và license/key hiện có.
QA mới dùng **SQLite**, actor đăng nhập ID 2. MySQL/cache/cancel/resume và
regenerate của FIX 1 mục 12.24 là bằng chứng kế thừa riêng.

Đã sửa lỗi menu/cửa sổ TinyMCE bị overlay dialog che: split UI đặt menu cùng
editor; portal cửa sổ Tiny có z-index 2500; dialog cha nhận `editorDialog`
để nhường focus tạm thời. Source Code nhận được focus, nhập được marker QA
và Cancel trả focus về editor; marker không được lưu.
Dialog mô tả ảnh/chú thích có placeholder cho cả hai field, theo chuẩn form.

| Luồng UI/API thật | Kết quả |
| --- | --- |
| Post editor | Upload PNG qua MediaLibrary, chèn ảnh, nhập alt/caption, cut/paste di chuyển figure, Delete/Undo, lưu draft/reopen và sửa caption |
| [Post proof](TinyMCE-post-proof.json) | Post #3 draft, `published_at=null`, asset #22 giữ URL/alt/caption/usage |
| AI Content editor | Mở form/loading, chọn asset #22, nhập caption, lưu nội dung và mở lại |
| [Apply proof](TinyMCE-apply-proof.json) | UI Apply tạo Post #4 draft, asset #22/file/`post.content_images` sort 0 được giữ; không publish |

AI Content dùng [fixture QA có nhãn](TinyMCE-candidate-fixture.json), replay
output Q33 đã lưu để kiểm editor/API thật: **0 calls AI thêm**, không dựng
checkpoint hoặc thay artifact Q33 thành ready. Fixture không được tính là
phiên model thành công mới. Local taxonomy hiện rỗng: Apply giữ [] với origin
manual; không nhận là đã chọn ID taxonomy có dữ liệu trong phiên mới.

Không gọi regenerate mới từ fixture editor. Bảo toàn ảnh qua regenerate
được kiểm bởi backend/frontend regression và browser/API kế thừa 12.24.
Hai Post #3/#4 được giữ ở trạng thái nháp để chủ dự án xem lại.
Ảnh [danh sách đã Apply](AI-Content-applied-list.png) ghi trạng thái cuối.

![Tiny Cloud trong dialog AI Content sau Apply](TinyMCE-applied-editor.png)

Ảnh ghi sau Apply: candidate đã khóa chỉnh sửa theo contract. Header/footer
giữ nguyên khi cuộn content và ảnh/chú thích vẫn hiển thị trong TinyMCE.

## Kiểm thử và integrity

| Kiểm tra cuối | Kết quả |
| --- | --- |
| Backend toàn bộ | 374 tests / 2.530 assertions, 116,98 giây |
| Frontend toàn bộ | 43 files / 302 tests, 13,66 giây |
| Regression số liệu | 36 tests / 93 assertions |
| Pint, ESLint và Stylelint phần sửa | Đạt |
| Production build | Đạt, 1 phút 36 giây |
| [Integrity lịch sử](historical-integrity.json) | 62 files đã kiểm, 0 thay đổi hash |

Logs local ở `storage/app/qa/task2-complete-*-final.log`,
`task2-complete-eslint.log`, `task2-complete-stylelint.log` và
`task2-complete-pint-final.log`, `task2-numbers-final.log`; kết quả tóm tắt
tại [verification.json](verification.json).
Build giữ cảnh báo asset/chunk/config đã có trước; không có lỗi build.
Kiểm whitespace phần code/plan đạt. HTML nguồn và bộ review đã freeze giữ
khoảng trắng gốc để không thay hash của nguồn hoặc phiếu chấm.
`.gitattributes` giữ nguyên bytes của corpus/study/pilot mới và gói Q33;
trước commit đã đối chiếu 62 files lịch sử trong cả workspace và Git index.

Study B/C lịch sử vẫn 79 calls, C ready 14/20; hai CSV vẫn 0/39 bài đã chấm.
Nguồn/study/phiếu chấm gốc không bị sửa. Audit gate mới không đổi tỷ lệ ready
hoặc bổ sung điểm người đọc vào study cũ. Các phiên đánh giá mới chỉ chạy C.

## Phần tiếp theo

Chấm người đọc là đợt riêng theo [protocol](../../quality/AI_ARTICLE_QUALITY_EVALUATION.md).
Theo FIX 1, công việc triển khai kế tiếp là workflow `ai_content_drafts` lưu
dài hạn và API/UI duyệt/từ chối/so sánh nguồn, sau đó thumbnail generate,
Reverb/VPS và chín tab Settings ngoài AI.
