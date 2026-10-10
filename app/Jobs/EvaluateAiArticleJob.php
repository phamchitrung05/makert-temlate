<?php

namespace App\Jobs;

use App\Services\Ai\Content\Quality\ArticleQualityEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker chấm một candidate bài AI trước khi duyệt.
 * =====================================================================
 * Job chỉ serialize UUID evaluation; source/draft/provider snapshot được đọc
 * lại trong service để chống chấm nhầm generation và tránh đưa payload lớn vào queue.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận UUID evaluation.
 * - handle(): gọi evaluator service.
 * - failed(): ghi lỗi terminal bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID ai_article_evaluations.
 * - OUTPUT: evaluation ready/failed; không tự approve hoặc Apply Post.
 * =====================================================================
 */
final class EvaluateAiArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $evaluationId)
    {
        $this->tries = max(1, (int) config('ai.quality.max_attempts', 2));
        $this->timeout = max(60, (int) config('ai.quality.request_timeout', 45) + 30);
    }

    /**
     * Chạy evaluator với lock claim do service quản lý.
     *
     * Input: ArticleQualityEvaluationService.
     * Output: void; service ghi evaluation lifecycle.
     * Side effect: có thể gọi provider AI và ghi điểm.
     */
    public function handle(ArticleQualityEvaluationService $service): void
    {
        $service->evaluate($this->evaluationId);
    }

    /**
     * Ghi lỗi sau khi queue hết lượt retry.
     *
     * Input: throwable cuối cùng.
     * Output: void; evaluation chuyển failed nếu còn active.
     * Side effect: ghi mã lỗi bounded, không lưu raw provider response.
     */
    public function failed(?Throwable $exception): void
    {
        app(ArticleQualityEvaluationService::class)->fail(
            $this->evaluationId,
            $exception ?? new \RuntimeException('Evaluator queue failed.'),
        );
    }
}
