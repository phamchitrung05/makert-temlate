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
 * CHỨC NĂNG FILE: Worker queue tạo prompt ảnh sản phẩm cho Social Post.
 * =====================================================================
 * Prompt ảnh là structured text riêng; binary image chạy ở job kế tiếp.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận AiImport UUID và generation.
 * - uniqueId(): tạo khóa queue ổn định.
 * - handle(): chạy worker image prompt.
 * - failed(): ghi lỗi terminal sau retry.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport operation social_image_prompt.
 * - OUTPUT: image prompt ready và binary image job queued.
 * - SIDE EFFECT: gọi provider/DB qua worker; không publish.
 * =====================================================================
 */
final class ProcessSocialPostImagePromptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    /**
     * Khởi tạo job image prompt.
     *
     * Input: AiImport UUID và generation number.
     * Output: job payload không chứa secret.
     */
    public function __construct(public readonly string $importId, public readonly int $generationNo) {}

    /**
     * Tạo unique key cho image prompt component.
     *
     * Input: UUID/generation đã snapshot.
     * Output: key social-image-prompt:<import>:<generation>; không side effect.
     */
    public function uniqueId(): string
    {
        return 'social-image-prompt:'.$this->importId.':'.$this->generationNo;
    }

    /**
     * Chạy image prompt worker sau khi queue nhận job.
     *
     * Input: SocialPostAiWorkerService từ container.
     * Output: không trả giá trị; worker ghi lifecycle.
     */
    public function handle(SocialPostAiWorkerService $worker): void
    {
        $import = AiImport::query()->find($this->importId);
        if ($import && (int) $import->generation_no === $this->generationNo) {
            $worker->processImagePrompt($import);
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
        if ($import) {
            app(SocialPostAiWorkerService::class)->fail($import, 'AI_SOCIAL_IMAGE_PROMPT_FAILED', $exception->getMessage());
        }
    }
}
