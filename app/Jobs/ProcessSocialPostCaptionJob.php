<?php

namespace App\Jobs;

use App\Models\AiImport;
use App\Services\Marketing\SocialPostAiWorkerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker queue tạo caption cho một generation Social Post.
 * =====================================================================
 * Job unique theo import/generation để double-click không gọi provider hai lần.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận AiImport UUID và generation.
 * - uniqueId(): tạo khóa queue ổn định.
 * - handle(): chạy worker caption.
 * - failed(): ghi lỗi terminal sau khi queue hết retry.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport operation social_caption.
 * - OUTPUT: caption ready hoặc failed trong AiImport/SocialPost.
 * - SIDE EFFECT: gọi provider/DB qua worker; không publish.
 * =====================================================================
 */
final class ProcessSocialPostCaptionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    /**
     * Khởi tạo job caption.
     *
     * Input: AiImport UUID và generation number.
     * Output: job payload không chứa secret.
     */
    public function __construct(public readonly string $importId, public readonly int $generationNo) {}

    /**
     * Tạo unique key cho caption component.
     *
     * Input: UUID/generation đã snapshot.
     * Output: key social-caption:<import>:<generation>; không side effect.
     */
    public function uniqueId(): string
    {
        return 'social-caption:'.$this->importId.':'.$this->generationNo;
    }

    /**
     * Chạy caption worker sau khi queue nhận job.
     *
     * Input: SocialPostAiWorkerService từ container.
     * Output: không trả giá trị; worker ghi lifecycle.
     */
    public function handle(SocialPostAiWorkerService $worker): void
    {
        $import = AiImport::query()->find($this->importId);
        if ($import && (int) $import->generation_no === $this->generationNo) {
            $worker->processCaption($import);
        }
    }

    /**
     * Ghi lỗi an toàn khi queue hết retry.
     *
     * Input: exception queue và import hiện tại.
     * Output: AiImport failed; không ném raw stack ra API.
     */
    public function failed(Throwable $exception): void
    {
        $import = AiImport::query()->find($this->importId);
        if ($import && (int) $import->generation_no === $this->generationNo) {
            app(SocialPostAiWorkerService::class)->fail($import, 'AI_SOCIAL_CAPTION_FAILED', $exception->getMessage());
        }
    }
}
