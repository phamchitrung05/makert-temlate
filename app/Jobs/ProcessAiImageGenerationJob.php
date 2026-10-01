<?php

namespace App\Jobs;

use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Services\Ai\AiImageGenerationService;
use App\Services\Ai\Registries\ProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker image độc lập, retry có kiểm soát và không làm fail text run.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), uniqueId(), handle(), failed(), discardUnattachedAsset().
 * INPUT: UUID image run đã lưu connection snapshot không có key.
 * OUTPUT: lifecycle/result có MediaAsset ID hoặc lỗi an toàn.
 * SIDE EFFECT: đọc key server-side, gọi provider, upload asset và cập nhật parent nếu có.
 * EXCEPTION/TRANSACTION: chỉ retry lỗi xác định an toàn; không có transaction bao trùm HTTP.
 * =====================================================================
 */
final class ProcessAiImageGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [10, 60, 180];

    public int $uniqueFor = 300;

    public function __construct(public readonly string $importId) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo khóa unique riêng cho từng image run
     * =====================================================================
     * INPUT: UUID import đã lưu trong job.
     * OUTPUT: Chuỗi ai-image:<run UUID>.
     * SIDE EFFECT: Hàm thuần; không query DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function uniqueId(): string
    {
        return 'ai-image:'.$this->importId;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo ảnh, lưu asset và cập nhật image run độc lập
     * =====================================================================
     * INPUT: UUID run và các service được container inject.
     * OUTPUT: Run ready có MediaAsset ID hoặc failed với lỗi an toàn.
     * SIDE EFFECT: Đọc key server-side, gọi image service, ghi lifecycle và metadata parent nếu có.
     * EXCEPTION/TRANSACTION: Chỉ retry lỗi domain an toàn có giới hạn; lỗi ảnh không đổi status của content parent, không transaction bao HTTP.
     * =====================================================================
     */
    public function handle(AiImageGenerationService $service, ProviderRegistry $providers): void
    {
        $import = AiImport::query()->find($this->importId);
        if (! $import || in_array($import->status, ['ready', 'failed', 'cancelled', 'expired'], true)) {
            return;
        }
        $input = (array) $import->input_json;
        try {
            $snapshot = (array) ($input['ai_connection'] ?? []);
            $connection = $providers->connectionForRun($snapshot, AiCapability::Image);
            $import->advance('generating', 35);
            $asset = $service->generate($connection, (string) $input['prompt'], (int) $import->created_by, (string) ($input['title'] ?? 'AI generated image'), $input['alt_text'] ?? null);
            if ($import->fresh()?->status === 'cancelled') {
                $this->discardUnattachedAsset($asset);

                return;
            }
            $result = [
                'provider' => $snapshot['provider'] ?? null,
                'model' => $snapshot['model'] ?? null,
                'image' => ['media_asset_id' => $asset->getKey()],
            ];
            $import->update([
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                'result_json' => $result,
                'completed_at' => now(), 'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ]);
            if ($import->parent_id) {
                $parent = AiImport::query()->find($import->parent_id);
                if ($parent) {
                    $parentResult = (array) $parent->result_json;
                    $parentResult['image'] = $result['image'];
                    $parentResult['image_job_id'] = $import->id;
                    $parent->update(['result_json' => $parentResult]);
                }
            }
        } catch (AiImportException $exception) {
            $import->update(['error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500)]);
            if ($import->parent_id) {
                AiImport::query()->whereKey($import->parent_id)->update([
                    'error_code' => $exception->errorCode,
                    'error_message' => 'Tạo thumbnail AI không thành công; nội dung vẫn được giữ nguyên.',
                ]);
            }
            if (! $exception->retryable || $this->attempts() >= $this->tries) {
                $import->update(['status' => 'failed', 'current_step' => 'failed']);

                return;
            }
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $import->update(['error_code' => 'AI_IMAGE_FAILED', 'error_message' => 'Tạo ảnh thất bại do lỗi hệ thống.', 'status' => 'failed', 'current_step' => 'failed']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đánh dấu image run lỗi sau khi queue hết attempt.
     * =====================================================================
     * INPUT: Throwable terminal hoặc null.
     * OUTPUT: run failed với thông báo an toàn.
     * SIDE EFFECT: ghi ai_imports; không gọi provider.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function failed(?Throwable $exception): void
    {
        AiImport::query()->whereKey($this->importId)->whereNotIn('status', ['ready', 'cancelled'])->update([
            'status' => 'failed', 'current_step' => 'failed', 'error_code' => $exception instanceof AiImportException ? $exception->errorCode : 'AI_IMAGE_FAILED',
            'error_message' => 'Tạo ảnh thất bại. Hãy kiểm tra cấu hình provider rồi thử lại.',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn asset đã upload khi admin hủy run ngay sau provider call
     * =====================================================================
     * INPUT: MediaAsset vừa tạo nhưng chưa có usage nghiệp vụ.
     * OUTPUT: không trả giá trị; asset được xóa best-effort.
     * SIDE EFFECT: clear media collection và soft-delete asset nếu còn orphan.
     * EXCEPTION/TRANSACTION: lỗi cleanup không làm lộ dữ liệu provider.
     * =====================================================================
     */
    private function discardUnattachedAsset(MediaAsset $asset): void
    {
        if (! $asset->usages()->exists()) {
            $asset->clearMediaCollection('library');
            $asset->delete();
        }
    }
}
