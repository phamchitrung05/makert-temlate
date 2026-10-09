<?php

namespace App\Jobs;

use App\Exceptions\AiArticleArchiveException;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\Archives\AiArticleArchiveService;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Services\Ai\Runs\AiRunBudget;
use App\Services\Ai\Runs\AiTaskRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker queue xử lý pipeline AI import có retry có kiểm soát.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận import UUID, timeout, budget và generation.
 * - handle(): giữ process lock và chạy pipeline worker.
 * - syncTaskRun(): ghi projection tracker sau mọi nhánh worker.
 * - process(): claim source, gọi pipeline và commit result/lifecycle.
 * - failed(): ghi terminal failure sau queue hết attempts.
 * - markArchiveFailure(): ghi lỗi archive giữ checkpoint phục hồi.
 * - queueOptionalImage(): tạo child image task sau content ready.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID AiImport được dispatch từ controller/command.
 * - OUTPUT: trạng thái ready/failed/cancelled và result JSON.
 * - SIDE EFFECT: gọi outbound provider, fetch nguồn và tạo thumbnail asset.
 * - EXCEPTION/TRANSACTION: queue retry tối đa 3 lần, backoff tăng dần; lỗi
 *   input/schema không retry và lifecycle được ghi terminal. Checkpoint v1 nằm tạm
 *   trong ai_imports để phục hồi ready; chỉ duyệt/apply mới ghi kho lâu dài.
 * =====================================================================
 */
class ProcessAiImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public array $backoff = [10, 60, 180];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo job xử lý một AiImport
     * =====================================================================
     * INPUT: UUID import và thời gian chờ HTTP đã chụp trong snapshot.
     * OUTPUT: job chờ đủ các lượt HTTP cộng 120 giây cho nguồn/media.
     * SIDE EFFECT: không truy cập database/provider khi khởi tạo.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function __construct(public readonly string $importId, int $requestTimeout = 30, int $calls = 1, public readonly ?int $generationNo = null)
    {
        $this->timeout = AiRunBudget::timeout($requestTimeout, $calls);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy pipeline và cập nhật lifecycle AiImport
     * =====================================================================
     * INPUT: ArticleImportService từ container.
     * OUTPUT: không trả giá trị; ghi ready/failed/cancelled vào AiImport và tracker.
     * SIDE EFFECT: gọi fetch/provider/uploader qua service, cập nhật progress và đồng bộ projection cuối nhánh.
     * EXCEPTION/TRANSACTION: lỗi retryable được ném lại cho queue; không mở
     *   transaction bao trùm toàn bộ pipeline.
     * =====================================================================
     */
    public function handle(ArticleImportService $service): void
    {
        // Lock tồn tại suốt generation để queue redelivery không gọi trùng provider.
        Cache::lock('ai-import-process-'.$this->importId, $this->timeout + 60)->get(function () use ($service): void {
            try {
                $this->process($service);
            } finally {
                $this->syncTaskRun();
            }
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ tracker dù pipeline kết thúc sớm hoặc ném retryable error.
     * =====================================================================
     * INPUT: UUID/generation của job. OUTPUT: không trả giá trị.
     * SIDE EFFECT: đọc source mới nhất và ghi projection; không gọi provider.
     * EXCEPTION/TRANSACTION: source thiếu/stale được bỏ qua an toàn.
     * =====================================================================
     */
    private function syncTaskRun(): void
    {
        if (($import = AiImport::query()->find($this->importId))
            && ($this->generationNo ?? 1) === (int) $import->generation_no) {
            app(AiTaskRunService::class)->syncFromImport($import);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xử lý run sau khi đã claim bằng lock độc quyền
     * =====================================================================
     * INPUT: service pipeline và UUID job đã khóa.
     * OUTPUT: lifecycle/result được ghi khi tác vụ chưa bị hủy/hết hạn.
     * SIDE EFFECT: gọi pipeline; checkpoint được lưu từng bước ngoài transaction HTTP.
     * EXCEPTION/TRANSACTION: exception retryable giữ chính sách queue hiện tại.
     * =====================================================================
     */
    private function process(ArticleImportService $service): void
    {
        $import = AiImport::query()->find($this->importId);
        if (! $import || ($this->generationNo ?? 1) !== (int) $import->generation_no) {
            return;
        }
        $taskRuns = app(AiTaskRunService::class);
        $archives = app(AiArticleArchiveService::class);
        $generationNo = (int) $import->generation_no;
        $recovering = $import->status === 'failed' && $import->error_code === 'AI_ARCHIVE_WRITE_FAILED' && $import->archive_pending_json !== null;
        if (in_array($import->status, AiImport::TERMINAL_STATUSES, true) && ! $recovering) {
            $taskRuns->syncFromImport($import);
            return;
        }
        $taskRuns->syncFromImport($import);
        try {
            if ($archives->tracked($import) && $import->archive_pending_json !== null) {
                if ($archives->resumeReady($import->id, $generationNo)) {
                    $this->queueOptionalImage($import->fresh());
                }

                return;
            }
            $sourceMetadata = (array) $import->source_meta_json;
            unset($sourceMetadata['ai_response'], $sourceMetadata['article_pipeline']);
            $import->update(['source_meta_json' => $sourceMetadata]);
            $result = $service->run($import);
            if ($import->fresh()?->status === 'cancelled') {
                return;
            }
            if ($archives->tracked($import)) {
                if ($archives->stageCompletion($import->id, $generationNo, $result)
                    && $archives->resumeReady($import->id, $generationNo)) {
                    $this->queueOptionalImage($import->fresh());
                }

                return;
            }
            $completion = [
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                'result_json' => $result, 'provider' => $result['provider'],
                'prompt_version' => $result['prompt_version'], 'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ];
            DB::transaction(function () use ($import, $completion, $generationNo): void {
                $parent = AiImport::query()->lockForUpdate()->find($import->id);
                if (! $parent || (int) $parent->generation_no !== $generationNo || in_array($parent->status, AiImport::TERMINAL_STATUSES, true) || $parent->expires_at?->isPast()) {
                    return;
                }
                $parent->forceFill($completion)->save();
                $this->queueOptionalImage($parent);
            });
        } catch (AiArticleArchiveException $exception) {
            $this->markArchiveFailure($generationNo, $exception);
            throw $exception;
        } catch (AiImportException $exception) {
            $shouldRetry = DB::transaction(function () use ($exception, $generationNo): bool {
                $run = AiImport::query()->lockForUpdate()->find($this->importId);
                if (! $run || (int) $run->generation_no !== $generationNo || in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
                    return false;
                }
                $updates = ['error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500)];
                if ($exception->diagnostics !== []) {
                    $updates['source_meta_json'] = array_replace((array) $run->source_meta_json, [
                        'ai_response' => AiResponseDiagnostics::sanitize(array_replace(
                            (array) data_get($run->source_meta_json, 'ai_response', []),
                            $exception->diagnostics,
                        )),
                    ]);
                }
                $retryable = $exception->retryable && $this->attempts() < $this->tries && $exception->errorCode !== 'CANCELLED';
                if (! $retryable) {
                    $status = match ($exception->errorCode) {
                        'CANCELLED' => 'cancelled', 'EXPIRED' => 'expired', default => 'failed',
                    };
                    $updates += ['status' => $status, 'current_step' => $status, 'completed_at' => now()];
                }
                $run->update($updates);

                return $retryable;
            });
            if ($shouldRetry) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($import, $generationNo): void {
                $run = AiImport::query()->lockForUpdate()->find($import->id);
                if (! $run || (int) $run->generation_no !== $generationNo || in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
                    return;
                }
                $run->forceFill(['status' => 'failed', 'current_step' => 'failed', 'completed_at' => now(),
                    'error_code' => 'AI_IMPORT_FAILED', 'error_message' => 'Tác vụ AI thất bại do lỗi hệ thống.'])->save();
            });
        }
        if ($latest = AiImport::query()->find($this->importId)) {
            $taskRuns->syncFromImport($latest);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi lỗi checkpoint riêng và giữ dữ liệu phục hồi của generation hiện tại
     * =====================================================================
     *
     * INPUT:
     * - Số generation của job và AiArticleArchiveException chứa mã/thông báo an toàn.
     *
     * OUTPUT:
     * - void: run hiện tại chưa terminal được đánh dấu failed; stale/missing/terminal không đổi.
     *
     * SIDE EFFECT:
     * - Ghi lifecycle/completed_at/error_code/error_message, giữ checkpoint; không tạo archive giả hoặc gọi provider.
     *
     * EXCEPTION/TRANSACTION:
     * - Transaction ngắn khóa run và kiểm generation; lỗi DB truyền lên queue, không báo ready hoặc làm mất checkpoint.
     *
     * =====================================================================
     */
    private function markArchiveFailure(int $generationNo, AiArticleArchiveException $exception): void
    {
        DB::transaction(function () use ($generationNo, $exception): void {
            $run = AiImport::query()->lockForUpdate()->find($this->importId);
            if ($run && (int) $run->generation_no === $generationNo && ! in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
                $run->forceFill(['status' => 'failed', 'current_step' => 'failed', 'completed_at' => now(),
                    'error_code' => $exception->reason, 'error_message' => $exception->getMessage()])->save();
            }
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi trạng thái failed sau khi queue hết attempt
     * =====================================================================
     * INPUT: throwable terminal hoặc null.
     * OUTPUT: không trả giá trị; AiImport chuyển sang failed nếu chưa ready/cancelled.
     * SIDE EFFECT: ghi mã/thông báo lỗi đã giới hạn độ dài, không lộ secret.
     * EXCEPTION/TRANSACTION: khóa run trong transaction ngắn; lỗi checkpoint giữ dữ liệu và truyền ra.
     * =====================================================================
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $import = AiImport::query()->find($this->importId);
            if (! $import || ($this->generationNo ?? 1) !== (int) $import->generation_no
                || in_array($import->status, AiImport::TERMINAL_STATUSES, true)) {
                return;
            }
            if ($exception instanceof AiArticleArchiveException
                || ($import->archive_pending_json !== null && app(AiArticleArchiveService::class)->tracked($import))) {
                $this->markArchiveFailure((int) $import->generation_no,
                    $exception instanceof AiArticleArchiveException ? $exception : new AiArticleArchiveException);

                return;
            }
            $updates = [
                'status' => 'failed', 'current_step' => 'failed', 'error_code' => $exception instanceof AiImportException ? $exception->errorCode : 'AI_IMPORT_FAILED',
                'error_message' => $exception instanceof AiImportException
                    ? Str::limit($exception->getMessage(), 500) : 'Tác vụ AI thất bại do lỗi hệ thống.',
            ];
            if ($exception instanceof AiImportException && $exception->diagnostics !== []) {
                $updates['source_meta_json'] = array_replace((array) $import->source_meta_json, [
                    'ai_response' => AiResponseDiagnostics::sanitize(array_replace(
                        (array) data_get($import->source_meta_json, 'ai_response', []),
                        $exception->diagnostics,
                    )),
                ]);
            }
            $updates['completed_at'] = now();
            DB::transaction(function () use ($import, $updates): void {
                $run = AiImport::query()->lockForUpdate()->find($import->id);
                if ($run && (int) $run->generation_no === (int) $import->generation_no && ! in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
                    $run->forceFill($updates)->save();
                }
            });
        } finally {
            $this->syncTaskRun();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tách image run tùy chọn sau khi content run đã ready
     * =====================================================================
     * INPUT: text import ready có image snapshot immutable hoặc null.
     * OUTPUT: không trả giá trị; child image job được queue riêng.
     * SIDE EFFECT: tạo child image/dispatch worker; lock và merge metadata không ghi đè edit parent.
     * EXCEPTION/TRANSACTION: lỗi tạo child chỉ ghi error parent, không retry text/provider.
     * =====================================================================
     */
    private function queueOptionalImage(?AiImport $import): void
    {
        if (! $import || ! AiThumbnailService::requested($import)) {
            return;
        }
        try {
            DB::transaction(fn () => app(AiThumbnailService::class)->schedule($import));
        } catch (Throwable $exception) {
            report($exception);
            $result = (array) $import->fresh()->result_json;
            $result['thumbnail_generation'] = ['job_id' => null, 'status' => 'failed', 'progress' => 0,
                'error_code' => 'AI_IMAGE_QUEUE_FAILED', 'error' => 'Không thể xếp hàng tạo ảnh; nội dung vẫn sẵn sàng. Hãy tạo lại thumbnail.'];
            $import->update(['result_json' => $result]);
        }
    }
}
