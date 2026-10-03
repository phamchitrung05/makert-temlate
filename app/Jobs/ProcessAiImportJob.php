<?php

namespace App\Jobs;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\AiResponseDiagnostics;
use App\Services\Ai\AiRunService;
use App\Services\Ai\ArticleImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker queue xử lý pipeline AI import có retry có kiểm soát.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), handle(), failed(), queueOptionalImage().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UUID AiImport được dispatch từ controller/command.
 * - OUTPUT: trạng thái ready/failed/cancelled và result JSON.
 * - SIDE EFFECT: gọi outbound provider, fetch nguồn và tạo thumbnail asset.
 * - EXCEPTION/TRANSACTION: queue retry tối đa 3 lần, backoff tăng dần; lỗi
 *   input/schema không retry và lifecycle được ghi terminal.
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
     * OUTPUT: job chờ đủ HTTP cộng 120 giây cho đọc nguồn/lưu kết quả.
     * SIDE EFFECT: không truy cập database/provider khi khởi tạo.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function __construct(public readonly string $importId, int $requestTimeout = 30)
    {
        $this->timeout = max((int) config('ai-import.job_timeout', 180), $requestTimeout + 120);
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
        $import = AiImport::query()->find($this->importId);
        if (! $import || in_array($import->status, ['ready', 'failed', 'cancelled', 'expired'], true)) {
            return;
        }
        $sourceMetadata = (array) $import->source_meta_json;
        unset($sourceMetadata['ai_response']);
        $import->update(['source_meta_json' => $sourceMetadata]);
        try {
            $result = $service->run($import);
            if ($import->fresh()?->status === 'cancelled') {
                return;
            }
            $import->update([
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                'result_json' => $result, 'provider' => $result['provider'],
                'prompt_version' => $result['prompt_version'], 'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ]);
            $this->queueOptionalImage($import->fresh());
        } catch (AiImportException $exception) {
            $import->refresh();
            if ($exception->errorCode === 'CANCELLED' || $import->status === 'cancelled') {
                $import->update(['status' => 'cancelled', 'current_step' => 'cancelled', 'error_code' => 'CANCELLED', 'error_message' => $exception->getMessage()]);

                return;
            }
            $import->update(['error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500)]);
            if ($exception->diagnostics !== []) {
                $import->update(['source_meta_json' => array_replace((array) $import->source_meta_json, [
                    'ai_response' => AiResponseDiagnostics::sanitize(array_replace(
                        (array) data_get($import->source_meta_json, 'ai_response', []),
                        $exception->diagnostics,
                    )),
                ])]);
            }
            if (! $exception->retryable || $this->attempts() >= $this->tries) {
                $import->update(['status' => 'failed', 'current_step' => 'failed']);

                return;
            }
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $import->update([
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
        if (! $import || in_array($import->status, ['ready', 'cancelled'], true)) {
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
        $import->update($updates);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tách image run tùy chọn sau khi content run đã ready
     * =====================================================================
     * INPUT: text import ready có image snapshot immutable hoặc null.
     * OUTPUT: không trả giá trị; child image job được queue riêng.
     * SIDE EFFECT: tạo AiImport operation=image và dispatch worker; parent không fail.
     * EXCEPTION/TRANSACTION: lỗi tạo child chỉ ghi error parent, không retry text/provider.
     * =====================================================================
     */
    private function queueOptionalImage(?AiImport $import): void
    {
        $requestedFields = (array) ($import?->input_json['fields'] ?? []);
        if (! $import || ($import->input_json['thumbnail_mode'] ?? 'auto') !== 'generate'
            || ! ($import->input_json['generate_thumbnail'] ?? true)
            || ($requestedFields !== [] && ! in_array('thumbnail', $requestedFields, true))
            || empty($import->input_json['image_connection'])) {
            return;
        }
        try {
            $input = (array) $import->input_json;
            $draft = (array) data_get($import->result_json, 'draft', []);
            $child = DB::transaction(function () use ($import, $input, $draft): AiImport {
                $child = AiImport::query()->create([
                    'id' => (string) Str::uuid(), 'created_by' => $import->created_by,
                    'source_url' => '', 'source_hash' => hash('sha256', 'image|'.$import->id),
                    'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
                    'input_json' => [
                        'prompt' => (string) ($draft['thumbnail_prompt'] ?? $draft['title'] ?? 'Tạo ảnh đại diện'),
                        'title' => (string) ($draft['title'] ?? 'AI generated image'),
                        'alt_text' => (string) ($draft['thumbnail']['alt_text'] ?? $draft['title'] ?? ''),
                        'ai_connection' => $input['image_connection'],
                    ],
                    'provider' => data_get($input, 'image_connection.provider'),
                    'prompt_version' => 'image-v1', 'session_id' => $import->session_id ?: $import->id,
                    'parent_id' => $import->id, 'operation' => 'image',
                ]);

                return $child;
            });
            app(AiRunService::class)->dispatch($child);
            $result = (array) $import->result_json;
            $result['image_job_id'] = $child->id;
            $import->update(['result_json' => $result]);
        } catch (Throwable $exception) {
            report($exception);
            $import->update(['error_code' => 'AI_IMAGE_QUEUE_FAILED', 'error_message' => 'Không thể xếp hàng tạo ảnh tự động; nội dung vẫn sẵn sàng.']);
        }
    }
}
