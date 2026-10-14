<?php

namespace App\Jobs\Resources;

use App\Services\Resources\TemplateVersionProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Queue worker xử lý package của Resource Template version
 * =====================================================================
 *
 * Job chỉ serialize scalar identity (version, media, checksum, token). Mọi
 * file/database state được đọc lại trong TemplateVersionProcessingService để
 * worker retry hoặc worker cũ không ghi đè package mới. Queue dùng unique lock
 * theo version/token, retry lỗi vận hành tối đa ba lần và không retry input lỗi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): giữ identity processing không chứa file/payload.
 * - uniqueId(): khóa duplicate job cùng version/token.
 * - backoff(): lịch retry lỗi vận hành.
 * - handle(): gọi service xử lý archive và artifact lifecycle.
 * - failed(): ghi failed terminal sau khi queue hết retry/timeout.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion id, package media id, checksum và processing token.
 * - OUTPUT: template ready/failed và AiTaskRun lifecycle.
 * - SIDE EFFECT: đọc/ghi private storage, database và tracker trong service.
 * - EXCEPTION/TRANSACTION: lỗi vận hành được ném lại cho queue; service consume lỗi input.
 * =====================================================================
 */
final class ProcessTemplateVersionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo job bằng processing identity scalar
     * =====================================================================
     * INPUT: version/media ids, checksum và token đã lưu trong DB.
     * OUTPUT: job serialize an toàn, không chứa ZIP hoặc HTML.
     * SIDE EFFECT: Không query hoặc ghi database.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(
        public readonly int $versionId,
        public readonly int $sourceMediaId,
        public readonly string $checksum,
        public readonly string $processingToken,
    ) {
        $this->timeout = (int) config('media-assets.template_job_timeout', 180);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo unique key cho cùng một lượt template processing
     * =====================================================================
     * INPUT: state scalar của job.
     * OUTPUT: key version/token dùng bởi queue unique lock.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    public function uniqueId(): string
    {
        return 'resource-template:'.$this->versionId.':'.$this->processingToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả lịch retry cho lỗi vận hành
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: list<int> giây chờ giữa ba attempt.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy một lượt xử lý template trong queue
     * =====================================================================
     * INPUT: TemplateVersionProcessingService từ container.
     * OUTPUT: void; service cập nhật template/artifact/tracker.
     * SIDE EFFECT: File processing và DB lifecycle trong service.
     * EXCEPTION/TRANSACTION: Lỗi vận hành truyền lên để queue retry.
     * =====================================================================
     */
    public function handle(TemplateVersionProcessingService $service): void
    {
        $jobId = null;
        if (is_object($this->job) && method_exists($this->job, 'getJobId')) {
            $jobId = $this->job->getJobId();
        }

        $service->process(
            $this->versionId,
            $this->sourceMediaId,
            $this->checksum,
            $this->processingToken,
            $jobId,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đánh dấu failed khi queue đã hết retry hoặc timeout
     * =====================================================================
     * INPUT: Throwable cuối cùng do queue cung cấp.
     * OUTPUT: void; template/tracker failed nếu token còn hiện hành.
     * SIDE EFFECT: ghi lifecycle và cleanup private artifact.
     * EXCEPTION/TRANSACTION: service che exception public; cleanup best-effort.
     * =====================================================================
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Resource template processing job exhausted retries.', [
            'version_id' => $this->versionId,
            'source_media_id' => $this->sourceMediaId,
            'processing_token' => $this->processingToken,
            'exception' => $exception,
        ]);
        app(TemplateVersionProcessingService::class)->cleanupArtifacts($this->versionId, $this->processingToken);
        app(TemplateVersionProcessingService::class)->markFailed(
            $this->versionId,
            $this->sourceMediaId,
            $this->checksum,
            $this->processingToken,
            $exception,
        );
    }
}
