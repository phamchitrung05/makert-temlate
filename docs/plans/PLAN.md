# Kế hoạch tổng của dự án

**Cập nhật:** 07/10/2026. Đây là nơi theo dõi tiến độ hiện tại; mỗi task chỉ có một checklist đang dùng.

## Tài liệu cần đọc

| Tài liệu | Dùng để |
| --- | --- |
| [Mục lục docs](../README.md) | Tìm tài liệu theo nhóm và đường dẫn hiện tại |
| [PLAN.md](PLAN.md) | Xem phần đã xong, backlog và ưu tiên tiếp theo |
| [HE_THONG_TU_DANH_GIA_DINH_KY.md](HE_THONG_TU_DANH_GIA_DINH_KY.md) | Chấm từng bài AI trước duyệt, vòng tròn điểm và báo cáo tổng hợp định kỳ |
| [DEVELOPMENT_GUIDELINES.md](../architecture/DEVELOPMENT_GUIDELINES.md) | Quy ước comment, kiến trúc, dialog, Add/Edit và Media viewport |
| [PROJECT_STRUCTURE.md](../architecture/PROJECT_STRUCTURE.md) · [PROJECT_INVENTORY.md](../architecture/PROJECT_INVENTORY.md) | Tra vị trí code, API và các module hiện có |
| [ENVIRONMENT.md](../operations/ENVIRONMENT.md) | Cấu hình môi trường, queue và storage |

Các tài liệu API và QA trong `docs/` tiếp tục làm nguồn tham chiếu. Tiến độ và task đã tổng hợp vào hai kế hoạch đang dùng; các bản kế hoạch/bàn giao cũ đã được dọn khỏi project.

## Trạng thái hiện tại

| Phần | Trạng thái | Đã có |
| --- | --- | --- |
| Foundation | DONE | Dependencies, schema/migrations, môi trường local, health API, boundary public/admin |
| Authentication và quyền | IN PROGRESS — A-02 backend DONE | Admin Sanctum, customer OAuth, token; backend quản lý role/permission bằng API thật đã xong. Hai page Vue để trống, giao diện mới do chủ dự án bổ sung sau; danh sách customer tạm hoãn |
| Resource và taxonomy | DONE | Backend, admin CRUD, service/store và kiểm thử |
| Media và version | DONE | Media Library task 1–10: upload/security, usage, API/quyền, picker, Resource/Post/Version và QA |
| Blog Admin/Post | IN PROGRESS | CRUD, taxonomy, SEO metadata, editor, media, phân trang/tìm kiếm/lọc trạng thái; còn workflow/revision và các mục bên dưới |
| AI Content | DONE trong phạm vi kỹ thuật hiện tại | Pipeline C ba bước, nguồn URL/text/HTML/file, profile/brief, duyệt/từ chối, sửa/regenerate, Apply Post nháp và thumbnail từ AI Content |
| Ai Prompt/Writing Profiles | DONE trong phạm vi hiện tại | CRUD, profile mặc định, chọn profile, phân tích văn phong qua queue và quyền/validation |
| Settings | DONE phần đã triển khai | 10 tab, typed settings/version/quyền, AI defaults, thông tin hệ thống, logo/favicon public/admin và QA upload thật |
| Đánh giá 19 bài đã lưu | DONE — đánh giá AI | Điểm văn phong trung bình **4,49/5**; hai phiếu người đọc vẫn chờ |
| Chấm bài AI và báo cáo định kỳ | IN PROGRESS — tạm hoãn, G1 DONE | Kho chỉ giữ bản Post AI được duyệt/Apply. Kế hoạch mới: chấm từng candidate trước duyệt, vòng tròn điểm, cổng > 4/đủ nguồn/đạt dữ kiện và tổng hợp điểm định kỳ; code giai đoạn 2–6 còn TODO. [Kế hoạch riêng](HE_THONG_TU_DANH_GIA_DINH_KY.md) |
| Public, download và thương mại | TODO/IN PROGRESS theo phạm vi | Public UI được làm ở luồng riêng; tích hợp và nghiệp vụ phía sau còn backlog |

Chủ dự án chấp nhận mức **4,49/5** và hiện chưa yêu cầu chỉnh bài, prompt hoặc model thêm. Không mở lại các task kỹ thuật đã xong chỉ vì checklist cũ chưa được cập nhật.

Ngày 07/10/2026, chủ dự án yêu cầu để tính năng chấm bài và danh sách customer làm sau vì giai đoạn đầu chưa có khách hàng. Q-01 giai đoạn 1 đã hoàn tất; phần giai đoạn 2–6 và A-01 tạm hoãn. **A-02 đã xong phạm vi backend**: role CRUD, permission catalog và gán role admin. Theo yêu cầu mới, hai page Vue để khung trống để chủ dự án thêm giao diện riêng sau. Task backend tiếp theo đề xuất là **P-01 — workflow review/publish Post**, rồi P-02; chưa tự bắt đầu các task này.

## Mốc đã hoàn tất gần nhất

- [x] 06/10: chuẩn hóa Settings, tách ảnh content bằng URL khỏi Gallery riêng của Post, upload logo/favicon và áp dụng public/admin.
- [x] 07/10: hoàn thành mục 1–3–4 của audit FIX 1: chuẩn bị bộ nghiệm thu, QA Settings/API thật và quy tắc vận hành AI. Auto thiếu model thật trả 422; `.html/.htm` được hỗ trợ; temperature 0 có thể dùng trong phiên đánh giá mới; diagnostics giữ allowlist.
- [x] 07/10: chấm AI **19/19** bài có nội dung từ **20 nguồn**; Q33 gốc timeout nên không có bài để chấm. [Báo cáo điểm và dẫn chứng](../qa/FIX1_ACCEPTANCE_2026-10-07/ai-review/README.md).
- [x] 07/10: tổng hợp tiến độ/task, tạo kế hoạch tự đánh giá định kỳ và dọn 12 file Markdown cũ; gom quy ước Add/Edit và Media viewport vào tài liệu phát triển chung.
- [x] 07/10: chuyển 11 tài liệu vào các nhóm plans/architecture/api/operations/quality; QA giữ các đợt hiện có, thêm mục lục docs và cập nhật đường dẫn tham chiếu.
- [x] 07/10: cụ thể hóa kế hoạch Q-01 giai đoạn 1 thành G1-01–G1-06 sau khi rà hoàn tất/retry/regenerate/edit/cleanup; chức năng lưu bền vững vẫn TODO.
- [x] 07/10: hoàn tất Q-01 giai đoạn 1 và điều chỉnh theo yêu cầu: chỉ candidate được duyệt/Apply vào kho bản gốc/nguồn; các bản chưa chọn hết hạn cùng Content AI. Dùng lại migration local đã chạy; kiểm ba bản chỉ chọn bản 2, rollback, phục hồi và cleanup. [Kết quả kiểm thử](../qa/AI_ARTICLE_ARCHIVES_2026-10-07.md).
- [x] 07/10: hoàn tất A-02 backend theo phạm vi đã điều chỉnh; bảo vệ role/scope quyền, version, audit và cache. Vue Roles/Permissions để trống cho giao diện mới của chủ dự án. [Contract API](../api/ACCESS_MANAGEMENT_API.md) · [Kiểm chứng](../qa/ACCESS_MANAGEMENT_2026-10-07/README.md).
- [x] 07/10: cập nhật kế hoạch Q-01 theo thảo luận: chấm từng bài trước duyệt, vòng tròn điểm, ngưỡng > 4 kèm kiểm nguồn/dữ kiện; chỉ giữ điểm bản đã chọn và định kỳ tổng hợp. Đây là cập nhật tài liệu, chưa triển khai evaluator/cổng duyệt/giao diện điểm.

Bằng chứng FIX 1 ở [Acceptance/QA](../qa/FIX1_ACCEPTANCE_2026-10-07/README.md): backend 438 tests/2986 assertions; lượt scoped cuối 31/172; frontend scoped 7 files/51 tests và build đạt. Kiểm backend gần nhất sau bổ sung kho approved xem [QA giai đoạn 1](../qa/AI_ARTICLE_ARCHIVES_2026-10-07.md). Đây là các lượt có phần trùng nhau, không cộng thành tổng mới.

## Backlog đang dùng

Các ID dưới đây để theo dõi ổn định, không phải yêu cầu triển khai tất cả trong một đợt. Tính năng định kỳ có checklist chi tiết ở file riêng; không lặp checklist đó tại đây.

| ID | Trạng thái | Việc còn lại / điều kiện bắt đầu |
| --- | --- | --- |
| Q-01 | IN PROGRESS — tạm hoãn, G1 DONE | G1-01–G1-06 đã đạt. Tiếp theo khi mở lại: G2 bộ chấm từng bài → G3 chấm ngay/cổng duyệt/vòng tròn điểm → G4 lịch tổng hợp/báo cáo → G5 Admin/cấu hình → G6 kiểm thử host. Kế hoạch đã cập nhật; code còn TODO. [Task chi tiết](HE_THONG_TU_DANH_GIA_DINH_KY.md) |
| Q-02 | TODO — chờ chấm sau | Hai người đọc chấm độc lập và kết luận nghiệm thu theo [bộ chấm đã chuẩn bị](../qa/FIX1_ACCEPTANCE_2026-10-07/review/README.md). Điểm AI không điền thay vào hai phiếu này |
| Q-03 | TODO — tạm hoãn | Nghiệm thu chất lượng nhận xét Ai Prompt; đối chiếu temperature 0/0.2 và hiệu chỉnh language/exact-copy/similarity theo loại bài/ngôn ngữ khi chủ dự án yêu cầu. Similarity không phải điểm chất lượng tổng thể |
| A-01 | TODO — tạm hoãn | Để làm sau khi có khách hàng theo yêu cầu ngày 07/10/2026. Admin xem danh sách customer bằng API thật: tên/email, trạng thái, ngày tạo/lần đăng nhập gần nhất; tìm kiếm, lọc trạng thái và phân trang |
| A-02 | DONE — backend | API role CRUD, permission catalog, danh sách/chi tiết/gán role admin, quyền/scope/version/audit đã triển khai và kiểm thử. Hai page Vue để trống; giao diện mới do chủ dự án bổ sung sau theo yêu cầu. CASL frontend tiếp tục tạm hoãn |
| P-01 | TODO | Review/publish workflow, `published_at` và quyền Post chi tiết |
| P-02 | TODO | Revision/history và khôi phục bản cũ của Post |
| P-03 | TODO — cần rà code trước | Rà sanitization HTML nhập tay và hoàn thiện phần còn thiếu; không mặc định toàn bộ input chưa được bảo vệ |
| P-04 | TODO | Lọc Post theo tác giả/ngày và trình diễn `post_type = gallery`; bộ ảnh Gallery có thứ tự đã lưu được |
| P-05 | TODO — nếu giữ tùy chọn | Chốt các Post options đang minh họa; bổ sung persistence/validation cho option được chọn |
| AI-01 | TODO — tạm hoãn | Kho draft phục vụ biên tập dài hạn. Kho snapshot cho Q-01 là yêu cầu riêng, không tự mở lại toàn bộ luồng biên tập này |
| AI-02 | TODO — tạm hoãn | Sinh thumbnail từ nội dung Post đã được người dùng biên tập. Thumbnail tạo trong AI Content và dialog prompt ảnh thủ công đã có |
| AI-03 | TODO | Ai Prompt: lịch sử phân tích phía server, quyền source preview riêng và mở rộng idempotency khi cần |
| AI-04 | TODO | AI Apply cho Resource/Sound, input/file theo từng domain; ghi usage/cost và phiên bản bảng giá. Thiếu usage/giá giữ trạng thái chưa biết |
| AI-05 | TODO — chỉ khi chọn SDK | Rà tương thích PHP/Laravel AI SDK trước khi thêm adapter; provider HTTP hiện tại đã có |
| S-01 | TODO | Webhook CRUD, delivery, retry và lịch sử. Tab hiện tại đã phản ánh chưa hỗ trợ |
| S-02 | TODO | Scheduler run history, heartbeat worker, GA/GSC, 2FA/CAPTCHA và kiểm SMTP production |
| H-01 | TODO — tạm hoãn đợt hiện tại | Host/VPS: worker/process manager, scheduler, storage, timeout/lease với DB/queue thật và nhiều worker; Reverb/Echo có fallback. Staging kiểm provider text/image bằng key thật, có quyền tạo ảnh; local/fixture đã kiểm không thay nghiệm thu staging |
| M-01 | TODO — mở rộng khi chọn phạm vi | MediaFolder, Trash/restore và các filter/option nâng cao chưa được nối đầy đủ; không mở lại Media Library task 1–10 |
| W-01 | TODO — phối hợp luồng public | Tích hợp visibility/SEO/canonical/OG, sitemap/robots/structured data và trang pháp lý với public website |
| D-01 | TODO | Free download: auth, URL ký có hạn, lịch sử, rate limit và counter; chốt verify email, license và retention thông tin truy cập |
| C-01 | TODO — sau free download | Commerce/payment: variants/license snapshot, chốt cart hoặc direct checkout, provider test mode, currency/tax/giá, order/invoice, refund/chargeback, entitlement, webhook signature/idempotency và paid download sau xác nhận |
| O-01 | TODO — trước production | Backup/restore, giám sát/audit, quyền truy cập, accessibility, tải và kiểm SEO phù hợp phạm vi phát hành |

Giai đoạn sau khi có người dùng thật, cân nhắc license key/activation, review/wishlist/collection/follow, support/knowledge base, search nâng cao, newsletter/analytics và multi-vendor/KYC/payout. Chưa chọn triển khai các mở rộng này.

## Cách cập nhật kế hoạch

- Dùng `TODO`, `IN PROGRESS`, `BLOCKED`, `DONE`; ghi thêm “tạm hoãn” hoặc phụ thuộc bên cạnh trạng thái khi cần.
- Khi bắt đầu task, ghi phạm vi và đổi trạng thái. Khi xong, đánh dấu `[x]`, ghi kết quả kiểm chứng và phần chưa xong; không đánh dấu cả nhóm DONE nếu còn tiêu chí nghiệm thu.
- Với tính năng định kỳ, cập nhật checklist tại [file riêng](HE_THONG_TU_DANH_GIA_DINH_KY.md), rồi cập nhật hàng Q-01 ở đây. Mốc dài/bằng chứng đặt trong `docs/qa/`, tránh kéo dài roadmap.
- Giữ nguyên thay đổi local của chủ dự án. Laravel quyết định dữ liệu/quyền; frontend dùng JavaScript, Vue Composition API và `<script setup>`. Quy tắc code đầy đủ nằm ở [DEVELOPMENT_GUIDELINES.md](../architecture/DEVELOPMENT_GUIDELINES.md).
- Task mới cập nhật vào kế hoạch đang dùng. Nếu vướng phụ thuộc, ghi rõ nguyên nhân, người phụ trách và bước tiếp theo.
