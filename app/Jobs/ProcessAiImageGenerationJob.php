<?php

namespace App\Jobs;

use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker image độc lập, retry có kiểm soát và không làm fail text run.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), uniqueId(): ngân sách job và khóa dispatch riêng theo run.
 * - handle(), complete(): gọi provider và commit kết quả nếu run còn active.
 * - mergeParentImage(): khóa/đọc lại parent trước khi merge metadata ảnh.
 * - failed(), discardUnattachedAsset(): giữ terminal state và dọn asset orphan.
 * INPUT: UUID image run đã lưu connection snapshot không có key.
 * OUTPUT: lifecycle/result có MediaAsset ID hoặc lỗi an toàn.
 * SIDE EFFECT: đọc key server-side, gọi provider, upload asset và cập nhật parent nếu có.
 * EXCEPTION/TRANSACTION: chỉ retry lỗi xác định an toàn; không có transaction bao trùm HTTP.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): UUID run -> lifecycle/asset refs persisted;
 * cancelled/expired không bị response trễ ghi đè, chỉnh sửa draft parent được giữ.
 * =====================================================================
 */
final class ProcessAiImageGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public array $backoff = [10, 60, 180];

    public int $uniqueFor;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo ngân sách job ảnh và unique lock
     * =====================================================================
     * Input: UUID image run và ngân sách HTTP trong snapshot.
     * Output: job/unique lock đủ dài cho HTTP và lưu ảnh; không đọc DB/provider.
     * =====================================================================
     */
    public function __construct(public readonly string $importId, int $requestTimeout = 30)
    {
        $this->timeout = max((int) config('ai-import.job_timeout', 180), $requestTimeout + 120);
        $this->uniqueFor = $this->timeout * $this->tries + array_sum($this->backoff) + 60;
    }

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
        if (! $import || in_array($import->status, AiImport::TERMINAL_STATUSES, true) || $import->expires_at?->isPast()) {
            return;
        }
        $input = (array) $import->input_json;
        $asset = null;
        try {
            $snapshot = (array) ($input['ai_connection'] ?? []);
            $connection = $providers->connectionForRun($snapshot, AiCapability::Image);
            $import->advance('generating', 35);
            if (in_array($import->status, AiImport::TERMINAL_STATUSES, true) || $import->expires_at?->isPast()) {
                return;
            }
            $asset = $service->generate($connection, (string) $input['prompt'], (int) $import->created_by, (string) ($input['title'] ?? 'AI generated image'), $input['alt_text'] ?? null);
            $result = [
                'provider' => $snapshot['provider'] ?? null,
                'model' => $snapshot['model'] ?? null,
                'image' => ['media_asset_id' => $asset->getKey()],
            ];
            if (! $this->complete($import, $asset, $result)) {
                $this->discardUnattachedAsset($asset);

                return;
            }
            $this->mergeParentImage($import, $result);
        } catch (AiImportException $exception) {
            $retryable = $exception->retryable && $this->attempts() < $this->tries;
            $updates = ['error_code' => $exception->errorCode, 'error_message' => Str::limit($exception->getMessage(), 500)];
            if (! $retryable) {
                $updates += ['status' => 'failed', 'current_step' => 'failed', 'completed_at' => now()];
            }
            $recorded = AiImport::query()->whereKey($import->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)->update($updates);
            if ($asset instanceof MediaAsset) {
                $this->discardUnattachedAsset($asset);
            }
            if ($recorded > 0 && $import->parent_id) {
                AiImport::query()->whereKey($import->parent_id)->where('status', 'ready')->update([
                    'error_code' => $exception->errorCode,
                    'error_message' => 'Tạo thumbnail AI không thành công; nội dung vẫn được giữ nguyên.',
                ]);
            }
            if (! $retryable || $recorded === 0) {
                return;
            }
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            AiImport::query()->whereKey($import->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)->update([
                'error_code' => 'AI_IMAGE_FAILED', 'error_message' => 'Tạo ảnh thất bại do lỗi hệ thống.',
                'status' => 'failed', 'current_step' => 'failed', 'completed_at' => now(),
            ]);
            if ($asset instanceof MediaAsset) {
                $this->discardUnattachedAsset($asset);
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Commit ref ảnh bằng điều kiện lifecycle atomic trên database
     * =====================================================================
     * INPUT: Run, asset vừa upload và result canonical từ worker.
     * OUTPUT: true khi run còn active/còn hạn; false khi hủy/kết thúc/hết hạn.
     * SIDE EFFECT: Khóa asset trong transaction trước khi ghi ref để DELETE
     * không chen giữa kiểm asset và lưu kết quả; không giữ khóa qua HTTP.
     * EXCEPTION: AI_MEDIA_REFERENCE khi asset đã bị xóa trước completion.
     * =====================================================================
     */
    private function complete(AiImport $import, MediaAsset $asset, array $result): bool
    {
        return DB::transaction(function () use ($import, $asset, $result): bool {
            if (! MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first()) {
                throw new AiImportException('Ảnh vừa tạo không còn trong MediaLibrary.', 'AI_MEDIA_REFERENCE');
            }
            $updates = (new AiImport)->forceFill([
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100, 'result_json' => $result,
                'completed_at' => now(), 'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ])->getAttributes();

            return AiImport::query()->whereKey($import->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->update($updates) > 0;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Merge metadata ảnh vào parent đã đọc lại dưới row lock
     * =====================================================================
     * INPUT: Image run đã ready và result chứa image ref.
     * OUTPUT: Không trả giá trị; chỉ merge image/image_job_id, giữ draft hiện tại.
     * SIDE EFFECT: Transaction riêng khóa parent; không giữ đồng thời child lock
     * hoặc gọi provider, bỏ qua parent đã hủy/hết hạn/không còn tồn tại.
     * =====================================================================
     */
    private function mergeParentImage(AiImport $import, array $result): void
    {
        if (! $import->parent_id) {
            return;
        }
        DB::transaction(function () use ($import, $result): void {
            $parent = AiImport::query()->whereKey($import->parent_id)->lockForUpdate()->first();
            if (! $parent || in_array($parent->status, ['cancelled', 'expired'], true) || $parent->expires_at?->isPast()) {
                return;
            }
            $parentResult = (array) $parent->result_json;
            $parentResult['image'] = $result['image'];
            $parentResult['image_job_id'] = $import->id;
            $parent->update(['result_json' => $parentResult]);
        });
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
        AiImport::query()->whereKey($this->importId)->whereNotIn('status', AiImport::TERMINAL_STATUSES)->update([
            'status' => 'failed', 'current_step' => 'failed', 'error_code' => $exception instanceof AiImportException ? $exception->errorCode : 'AI_IMAGE_FAILED',
            'error_message' => 'Tạo ảnh thất bại. Hãy kiểm tra cấu hình provider rồi thử lại.',
            'completed_at' => now(),
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn asset đã upload khi admin hủy run ngay sau provider call
     * =====================================================================
     * INPUT: MediaAsset vừa tạo nhưng chưa có usage nghiệp vụ.
     * OUTPUT: không trả giá trị; asset được xóa best-effort.
     * SIDE EFFECT: Khóa asset, kiểm usage/retained refs rồi clear/soft-delete orphan.
     * EXCEPTION/TRANSACTION: lỗi cleanup không làm lộ dữ liệu provider.
     * =====================================================================
     */
    private function discardUnattachedAsset(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $current = MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first();
            if (! $current || $current->usages()->exists()
                || app(ContentMediaReferenceService::class)->isReferencedByRetainedAiRun((int) $current->getKey())) {
                return;
            }
            $current->clearMediaCollection('library');
            $current->delete();
        });
    }
}
