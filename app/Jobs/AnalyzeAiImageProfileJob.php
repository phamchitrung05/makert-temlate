<?php

namespace App\Jobs;

use App\Models\AiImageProfile;
use App\Services\Ai\Images\ImageProfileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AnalyzeAiImageProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $profileId, int $requestTimeout = 30)
    {
        $this->timeout = max(60, $requestTimeout + 45);
    }

    public function handle(ImageProfileService $service): void
    {
        try {
            $service->process($this->profileId);
        } catch (\Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        AiImageProfile::whereKey($this->profileId)->whereIn('status', ['queued', 'analyzing'])->update([
            'status' => 'failed', 'error_message' => 'Không thể phân tích ảnh. Hãy kiểm tra model Vision và kết nối, rồi tạo tác vụ mới.', 'updated_at' => now(),
        ]);
    }
}
