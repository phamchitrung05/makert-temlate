<?php

namespace App\Jobs;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Services\Ai\Runs\AiRunBudget;
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
 * - __construct(), handle(), process(), failed(), queueOptionalImage().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID AiImport được dispatch từ controller/command.
 * - OUTPUT: trạng thái ready/failed/cancelled và result JSON.
 * - SIDE EFFECT: gọi outbound provider, fetch nguồn và tạo thumbnail asset.
 * - EXCEPTION/TRANSACTION: queue retry tối đa 3 lần, backoff tăng dần; lỗi
 *   input/schema không retry và lifecycle được ghi terminal.
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
    public function __construct(public readonly string $importId, int $requestTimeout = 30, int $calls = 1)
    {
        $this->timeout = AiRunBudget::timeout($requestTimeout, $calls);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy pipeline và cập nhật lifecycle AiImport
     * =====================================================================
     * INPUT: ArticleImportService từ container.
     * OUTPUT: không trả giá trị; ghi ready/failed/cancelled vào AiImport.
     * SIDE EFFECT: gọi fetch/provider/uploader qua service và cập nhật progress.
     * EXCEPTION/TRANSACTION: lỗi retryable được ném lại cho queue; không mở
     *   transaction bao trùm toàn bộ pipeline.
     * =====================================================================
     */
    public function handle(ArticleImportService $service): void
    {
        // Lock tồn tại suốt generation để queue redelivery không gọi trùng provider.
        Cache::lock('ai-import-process-'.$this->importId, $this->timeout + 60)->get(fn () => $this->process($service));
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
        if (! $import || in_array($import->status, ['ready', 'failed', 'cancelled', 'expired'], true)) {
            return;
        }
        $sourceMetadata = (array) $import->source_meta_json;
        unset($sourceMetadata['ai_response'], $sourceMetadata['article_pipeline']);
        $import->update(['source_meta_json' => $sourceMetadata]);
        try {
            $result = $service->run($import);
            if ($import->fresh()?->status === 'cancelled') {
                return;
            }
            $completion = [
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                'result_json' => $result, 'provider' => $result['provider'],
                'prompt_version' => $result['prompt_version'], 'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ];
            DB::transaction(function () use ($import, $completion): void {
                $parent = AiImport::query()->lockForUpdate()->find($import->id);
                if (! $parent || in_array($parent->status, AiImport::TERMINAL_STATUSES, true) || $parent->expires_at?->isPast()) {
                    return;
                }
                $parent->forceFill($completion)->save();
                $this->queueOptionalImage($parent);
            });
        } catch (AiImportException $exception) {
            $shouldRetry = DB::transaction(function () use ($exception): bool {
                $run = AiImport::query()->lockForUpdate()->find($this->importId);
                if (! $run || in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
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
            AiImport::query()->whereKey($import->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)->update([
                'status' => 'failed', 'current_step' => 'failed',
                'error_code' => 'AI_IMPORT_FAILED', 'error_message' => 'Tác vụ AI thất bại do lỗi hệ thống.',
            ]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi trạng thái failed sau khi queue hết attempt
     * =====================================================================
     * INPUT: throwable terminal hoặc null.
     * OUTPUT: không trả giá trị; AiImport chuyển sang failed nếu chưa ready/cancelled.
     * SIDE EFFECT: ghi mã/thông báo lỗi đã giới hạn độ dài, không lộ secret.
     * EXCEPTION/TRANSACTION: không mở transaction; queue worker quản lý lifecycle.
     * =====================================================================
     */
    public function failed(?Throwable $exception): void
    {
        $import = AiImport::query()->find($this->importId);
        if (! $import || in_array($import->status, AiImport::TERMINAL_STATUSES, true)) {
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
        AiImport::query()->whereKey($import->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)
            ->update((new AiImport)->forceFill($updates)->getAttributes());
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
