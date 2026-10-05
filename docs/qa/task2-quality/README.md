# Bộ nguồn và đánh giá chất lượng Task 2

Nguồn đã đóng băng tại [manifest](corpus-v1/manifest.json): 25 ca từ 24 nguồn độc lập. Q18/Q21 dùng hai yêu cầu khác nhau trên cùng bài HTML người dùng cung cấp. Các nguồn kỹ thuật là đơn vị nội dung chọn từ docs chính thức ở commit cố định; `selection`, URL, license/attribution, hash raw và hash snapshot được ghi trong manifest. Không mặc định đây là 25 bài đầy đủ hoặc corpus đã được người đọc duyệt.

Corpus chủ yếu kỹ thuật. Chưa đủ tin tức/du lịch tiếng Việt, benchmark có số liệu độc lập hay điều kiện gói trả phí; Q24 cần fixture parent/asset riêng. Bộ facts chuẩn còn chờ người đọc lập từ nguồn. Các giới hạn này được giữ trong manifest, không lấp bằng facts hoặc điểm do AI tự tạo.

## Thử nghiệm

- [Pilot prompt 2.0](pilot-2026-10-05/summary.json): Q01/Q13, hai nhánh B/C, 4 call thực; C dừng ở Analyzer vì dẫn chứng chứa markup thay vì văn bản nguồn. Artifacts lỗi được giữ nguyên.
- [Pilot prompt 2.1](pilot-2026-10-05-prompt21/summary.json): cùng Q01/Q13, 8 call thực, B/C đều qua gate kỹ thuật. Prompt yêu cầu excerpt liền mạch từ một `source.blocks[].text`; không tự nới gate evidence.
- [Pilot mở rộng Q02/Q07/Q18](pilot-2026-10-05-prompt21-extra/summary.json) chạy cùng model/profile và prompt 2.1; 11 call, giữ đủ output thành công/lỗi.
- [Gói chấm năm ca](pilot-2026-10-05-five-cases/review/index.html) gộp hai đợt có cùng manifest và input profile/prompt hash; không gọi model thêm. Tổng **19 call/97.837 token**, B ready 4/5, C ready 2/5; 4 output bị chặn vì link nguồn (3) hoặc fact ID không có trong Analyze (1). Đây là kết quả kỹ thuật của pilot, chưa phải điểm văn phong hoặc nghiệm thu rollout.

B là một lượt với brief/profile; C là Analyze → Write → Edit. Model thực `gpt-6.1-sol`, profile QA id 3/version 4. Chi phí tiền để `null`: provider chưa cung cấp bảng giá được xác nhận; token/latency trong từng artifact là số thật nếu có. Không Apply/Publish output đánh giá, không sửa Settings/default.

**Chưa có kết luận về bài hay hơn.** Gói chấm HTML và CSV để hai người đọc chấm độc lập. Không mở `summary.json` hoặc `blind-key.json` trong lúc chấm. Fact errors/coverage là gate riêng; bốn tiêu chí diễn đạt cộng brief/style, phút sửa, số sửa facts/câu chữ và số đoạn thêm/xóa được ghi riêng. Ô trống là chưa chấm, không phải điểm 0.

## Baseline A

Đã lưu [config prompt tại Git HEAD trước Task 2](baseline-reference/ai-agent-at-HEAD.php.txt), commit `aaf019f0625d497dbd8b10226b25f7959ffb69b2`, SHA-256 `6d8d35b8775b28a143f7ad0cb6d2303923c99688ac0fad0d80753db300f8dac1`.

File này chưa phục hồi toàn bộ request cũ: Settings/system prompt/model/options tại thời điểm cũ chưa được xác nhận. Vì vậy A vẫn **unavailable**, không đổi tên B thành A hoặc rollback website để giả baseline. B/C có thể chấm riêng trong khi owner cung cấp bản cấu hình/run cũ nếu còn giữ.

## Lệnh chạy từ project root

Preflight chỉ kiểm hash/nguồn/ngân sách, không gọi model:

```powershell
php scripts/ai-quality/evaluate.php --case=Q01,Q02,Q07,Q13,Q18
```

Chạy một phiên mới với prototype UUID đã chọn model/profile. Một ca B/C tối đa bốn call, năm ca tối đa 20; runner chặn trước call vượt budget, không retry riêng hoặc fallback sang deterministic:

```powershell
php -d extension=gd -d xdebug.mode=off scripts/ai-quality/evaluate.php --prototype=UUID_RUN_LOCAL --case=Q01,Q02,Q07,Q13,Q18 --current-prompts --max-calls=20 --output=THU_MUC_PHIEN_MOI --run
```

`--current-prompts` dùng prompt hiện tại nhưng giữ model/profile của prototype; thiếu flag này dùng prompt snapshot cũ. Output mới phải chưa tồn tại. Manifest/hash và input prompt/profile snapshot được lưu cùng phiên; key/endpoint/header xác thực không được xuất ra artifacts.

Render lại gói chấm đã có, **0 call**, giữ thứ tự X/Y và mọi CSV đã tồn tại:

```powershell
php scripts/ai-quality/evaluate.php --case=Q01,Q13 --output=docs/qa/task2-quality/pilot-2026-10-05-prompt21 --export-only
```

Không dùng `--run` với `--export-only`; export phải đúng các nhánh trong blind-key gốc. Muốn đổi nguồn, prompt hoặc corpus phải tạo phiên/version mới. Bộ freeze từ docs công khai có timeout, không ghi đè corpus đã có:

```powershell
php scripts/ai-quality/freeze.php --output=THU_MUC_CORPUS_MOI --html-source=DUONG_DAN_HTML_DUOC_CHON
```

Protocol/rubric và phần còn chờ ở [AI_ARTICLE_QUALITY_EVALUATION.md](../../AI_ARTICLE_QUALITY_EVALUATION.md). Các fixture kỹ thuật tổng hợp trong `tests/Fixtures` và ca MySQL dùng provider fake không được tính vào điểm văn phong hoặc token model thật.
