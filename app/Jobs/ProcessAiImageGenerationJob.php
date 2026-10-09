<?php

namespace App\Jobs;

use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Runs\AiTaskRunService;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 * CHỨC NĂNG FILE: Worker image độc lập, retry có kiểm soát và không làm fail text run.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận image UUID, generation và ngân sách HTTP.
 * - uniqueId(): tạo khóa unique theo image run.
 * - handle(): giữ process lock và chạy image pipeline.
 * - process(): gọi provider, upload asset và cập nhật lifecycle.
 * - complete(): commit asset reference khi generation còn active.
 * - syncTaskRun(): ghi projection tracker sau mọi nhánh worker.
 * - mergeParentImage(): sync thumbnail hoặc merge metadata parent.
 * - failed(): ghi terminal image failure và sync thumbnail.
 * - discardUnattachedAsset(): dọn asset orphan sau response trễ.
 * INPUT: UUID image run, generation tùy chọn và connection snapshot không có key.
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
     * Input: UUID image run, generation và ngân sách HTTP trong snapshot.
     * Output: job/unique lock đủ dài cho HTTP và lưu ảnh; không đọc DB/provider.
     * =====================================================================
     */
    public function __construct(public readonly string $importId, int $requestTimeout = 30, public readonly ?int $generationNo = null)
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
     * OUTPUT: Run ready có MediaAsset ID hoặc failed với lỗi an toàn, tracker cùng trạng thái.
     * SIDE EFFECT: Đọc key server-side, gọi image service, ghi lifecycle/tracker và metadata parent nếu có.
     * EXCEPTION/TRANSACTION: Chỉ retry lỗi domain an toàn có giới hạn; lỗi ảnh không đổi status của content parent, không transaction bao HTTP.
     * =====================================================================
     */
    public function handle(AiImageGenerationService $service, ProviderRegistry $providers): void
    {
        $lock = Cache::lock('ai-image-process-'.$this->importId, $this->timeout + 60);
        if (! $lock->get()) {
            // Queue redelivery/manual retry phải còn trong queue khi worker cũ giữ lock.
            $this->release($this->timeout + 60);

            return;
        }
        try {
            try {
                $this->process($service, $providers);
            } finally {
                $this->syncTaskRun();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ tracker sau mọi nhánh của image worker.
     * =====================================================================
     * INPUT: UUID image run. OUTPUT: không trả giá trị.
     * SIDE EFFECT: ghi projection trạng thái mới nhất; không gọi provider.
     * EXCEPTION/TRANSACTION: source thiếu/stale được bỏ qua an toàn.
     * =====================================================================
     */
    private function syncTaskRun(): void
    {
        if (($import = AiImport::query()->find($this->importId))
            && ($this->generationNo === null || $this->generationNo === (int) $import->generation_no)) {
            app(AiTaskRunService::class)->syncFromImport($import);
        }
    }

    /** INPUT: services inject. OUTPUT: lifecycle dưới lock độc quyền; HTTP ngoài DB transaction. */
    private function process(AiImageGenerationService $service, ProviderRegistry $providers): void
    {
        $import = AiImport::query()->find($this->importId);
        if (! $import || ($this->generationNo !== null && $this->generationNo !== (int) $import->generation_no)
            || in_array($import->status, AiImport::TERMINAL_STATUSES, true)) {
            if ($import) {
                app(AiTaskRunService::class)->syncFromImport($import);
            }
            return;
        }
        $taskRuns = app(AiTaskRunService::class);
        $taskRuns->syncFromImport($import);
        if ($import->expires_at?->isPast()) {
            $import->update(['status' => 'expired', 'current_step' => 'expired', 'completed_at' => now()]);
            app(AiThumbnailService::class)->sync($import);
            $taskRuns->syncFromImport($import->refresh());

            return;
        }
        $input = (array) $import->input_json;
        $thumbnails = app(AiThumbnailService::class);
        if (($input['purpose'] ?? null) === 'thumbnail' && ! $thumbnails->accepts($import, AiImport::find($import->parent_id))) {
            $import->update(['status' => 'cancelled', 'current_step' => 'cancelled', 'completed_at' => now()]);

            return;
        }
        $asset = null;
        try {
            $snapshot = (array) ($input['ai_connection'] ?? []);
            $connection = $providers->connectionForRun($snapshot, AiCapability::Image);
            $import->advance('generating', 35);
            $thumbnails->sync($import);
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
                AiImport::query()->whereKey($import->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)
                    ->where('expires_at', '<=', now())->update(['status' => 'expired', 'current_step' => 'expired', 'completed_at' => now()]);
                $this->discardUnattachedAsset($asset);
                $thumbnails->sync($import->fresh());

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
            $thumbnails->sync($import->fresh());
            if ($recorded > 0 && $import->parent_id && ($input['purpose'] ?? null) !== 'thumbnail') {
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
            $thumbnails->sync($import->fresh());
        }
        if ($latest = AiImport::query()->find($this->importId)) {
            $taskRuns->syncFromImport($latest);
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
     * OUTPUT: Thumbnail được gắn canonical qua service, image legacy chỉ merge metadata;
     * giữ title/content/taxonomy hiện tại của parent.
     * SIDE EFFECT: Transaction riêng khóa parent; không giữ đồng thời child lock
     * hoặc gọi provider, bỏ qua parent đã hủy/hết hạn/không còn tồn tại.
     * =====================================================================
     */
    private function mergeParentImage(AiImport $import, array $result): void
    {
        if (! $import->parent_id) {
            return;
        }
        if (data_get($import->input_json, 'purpose') === 'thumbnail') {
            app(AiThumbnailService::class)->sync($import->fresh());

            return;
        }
        DB::transaction(function () use ($import, $result): void {
            $parent = AiImport::query()->whereKey($import->parent_id)->lockForUpdate()->first();
            if (! $parent || ! AiThumbnailService::editable($parent) || (int) $parent->created_by !== (int) $import->created_by) {
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
        try {
            AiImport::query()->whereKey($this->importId)->whereNotIn('status', AiImport::TERMINAL_STATUSES)->update([
                'status' => 'failed', 'current_step' => 'failed', 'error_code' => $exception instanceof AiImportException ? $exception->errorCode : 'AI_IMAGE_FAILED',
                'error_message' => 'Tạo ảnh thất bại. Hãy kiểm tra cấu hình provider rồi thử lại.',
                'completed_at' => now(),
            ]);
            app(AiThumbnailService::class)->sync(AiImport::find($this->importId));
        } finally {
            $this->syncTaskRun();
        }
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
