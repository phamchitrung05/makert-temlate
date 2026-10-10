# QA Q-01 G2/G3 — evaluator và cổng duyệt

Ngày: 09/10/2026

Phạm vi: evaluator theo candidate/generation/hash, enqueue sau `ready`, quality gate
Approve/Apply, chấm lại sau edit, vòng tròn điểm và snapshot score trong archive.

## Đã kiểm chứng

- `AiArticleQualityEvaluationTest`: 2 tests / 18 assertions đạt.
  - Evaluation tạo đúng hash, generation và idempotent; queue không chấm trùng.
  - Approve trả 409 khi chưa có điểm; evaluation `ready` với điểm 4,4, nguồn và dữ kiện đạt thì mở cổng.
  - Archive lifecycle giữ `score_total` của evaluation được chọn.
- `AiContentReviewApiTest`: 9 tests đạt sau khi nối gate; candidate legacy không có
  `generation_meta.generated_fields` vẫn giữ contract tương thích cũ.
- `AiArticleArchiveTest`: bộ kiểm archive chạy với evaluation fixture đạt; checkpoint,
  rollback, cleanup và archive immutable vẫn giữ hành vi hiện có.
- Frontend scoped: `aiContentList`, `aiContentReviewDialog`, `aiContentReview`,
  `aiAgentService`: 36 tests đạt.
- `npm run build`: đạt.
- PHP syntax (`php -l`) cho migration, model, service, job, controller và resource: đạt.

## Contract đã triển khai

- `ai_article_evaluations` lưu rubric/prompt/schema version, candidate/source hash,
  điểm từng tiêu chí, evidence, source references, eligibility, diagnostics/usage,
  attempt, lỗi và thời hạn.
- Worker `EvaluateAiArticleJob` chỉ nhận evaluation UUID; service claim queued →
  running, gọi ProviderRegistry, validate output và tự tính trung bình.
- Ngưỡng duyệt là strict `score_total > 4`, đồng thời cần source reference và
  `factual_status=pass`. Lỗi evaluator không ghi điểm 0.
- `POST /ai-agent/candidates/{uuid}/quality/rescore` yêu cầu đúng owner và
  `posts.manage`; PATCH candidate xếp evaluation theo hash mới.
- Cleanup/delete run xóa evaluation tạm; score đã chọn được ghi vào lifecycle archive
  trong transaction duyệt/Apply.

## Còn để G6

Chưa gọi provider thật hoặc bật lịch báo cáo/ ngân sách trên host. G6 cần chạy bộ bài
đã lưu với evaluator connection đã cấu hình, đối chiếu người đọc và kiểm worker/timeout/
budget trên môi trường triển khai.
