<?php

namespace App\Services\Ai\Runs\Adapters;

use App\Models\AiImport;
use App\Models\AiTaskRun;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Runs\Contracts\AiTaskRunAdapter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter tracker cho worker tạo bài và tạo ảnh AI.
 * =====================================================================
 * Cùng một AiImport có hai task type; operation quyết định projection nhưng
 * generation_no vẫn nằm trong dedupe key để worker cũ không ghi đè run mới.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - modelClass(): khai báo model import.
 * - project(): tạo projection article/image bounded.
 * - matchesSource(): kiểm UUID và generation worker/source.
 * - isCurrent(): kiểm tracker còn đúng generation.
 * - applyVisibility(): thêm branch target/media permission.
 * - canUse(): kiểm actor có quyền content/image queue.
 * - cancel(): ghi cancelled vào import active.
 * - afterCancel(): đồng bộ canonical thumbnail nếu là image.
 * - source(): ép kiểu model theo adapter.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport đã được lock.
 * - OUTPUT: projection article_generation hoặc image_generation.
 * - SIDE EFFECT: hủy import và đồng bộ thumbnail sau transaction.
 * =====================================================================
 */
final class AiImportTaskAdapter implements AiTaskRunAdapter
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model class mà adapter nhận xử lý.
     * =====================================================================
     * INPUT: không có. OUTPUT: FQCN AiImport.
     * SIDE EFFECT: không query hoặc ghi database. EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function modelClass(): string
    {
        return AiImport::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo projection bounded cho article/image import.
     * =====================================================================
     * INPUT: Model nguồn đã lock. OUTPUT: task type, progress và metadata allowlist.
     * SIDE EFFECT: chỉ đọc source. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function project(Model $source): array
    {
        $import = $this->source($source);
        $image = $import->operation === 'image';
        $type = $image ? 'image_generation' : 'article_generation';
        $snapshot = (array) data_get($import->input_json, 'ai_connection', []);
        $status = $import->expires_at?->isPast() ? 'expired' : match ($import->status) {
            // Một số run cũ dùng completed/succeeded trước khi lifecycle chuẩn hóa về ready.
            'completed', 'succeeded' => 'ready',
            default => $import->status,
        };
        if (! in_array($status, ['queued', 'ready', 'failed', 'cancelled', 'expired'], true)) {
            $status = 'processing';
        }
        $errorCode = $status === 'failed' ? $import->error_code : null;

        return [
            'dedupe_key' => $type.':'.$import->id.':'.(int) $import->generation_no,
            'task_type' => $type,
            'source' => $image ? 'ai_image' : 'ai_content',
            'model' => data_get($snapshot, 'model'),
            'provider' => $import->provider ?: data_get($snapshot, 'provider'),
            'status' => $status,
            'progress' => $status === 'ready' ? 100 : max(0, min(100, (int) $import->progress)),
            'user_id' => $import->created_by,
            'taskable_type' => $import::class,
            'taskable_id' => (string) $import->id,
            'metadata_json' => [
                'operation' => $import->operation,
                'target_type' => data_get($import->input_json, 'target_type', 'post'),
                'generation_no' => (int) $import->generation_no,
                'parent_id' => $import->parent_id,
                'media_asset_id' => data_get($import->result_json, 'image.media_asset_id'),
            ],
            'error_code' => $errorCode ? mb_substr((string) $errorCode, 0, 120) : null,
            'error_message' => $errorCode ? 'Tác vụ AI thất bại. Hãy kiểm tra cấu hình và thử lại.' : null,
            'started_at' => $import->started_at,
            'completed_at' => in_array($status, ['ready', 'failed', 'cancelled', 'expired'], true) ? ($import->completed_at ?? now()) : null,
            'expires_at' => $import->expires_at,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận import snapshot còn cùng generation.
     * =====================================================================
     * INPUT: hai model import. OUTPUT: true khi cùng UUID và generation.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function matchesSource(Model $requested, Model $current): bool
    {
        return $requested instanceof AiImport
            && $current instanceof AiImport
            && (string) $requested->getKey() === (string) $current->getKey()
            && (int) $requested->generation_no === (int) $current->generation_no;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn worker generation cũ ghi đè tracker mới.
     * =====================================================================
     * INPUT: source và task metadata. OUTPUT: true khi generation trùng.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function isCurrent(Model $source, AiTaskRun $task): bool
    {
        $import = $this->source($source);

        return (int) $import->generation_no === (int) data_get($task->metadata_json, 'generation_no', $import->generation_no);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Scope article/image theo target và media permission.
     * =====================================================================
     * INPUT: Builder owner scope và actor authenticated. OUTPUT: query có OR branch.
     * SIDE EFFECT: chỉ sửa query builder. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function applyVisibility(Builder $query, User $actor): void
    {
        $targets = collect((array) config('ai.agent.targets'))
            ->filter(fn ($target) => $actor->can($target['permission'] ?? 'posts.manage'))
            ->keys()->all();
        if ($targets !== []) {
            $query->orWhere(fn (Builder $content) => $content
                ->where('task_type', 'article_generation')
                ->whereIn('metadata_json->target_type', $targets));
        }
        if ($actor->can('media.upload')) {
            $query->orWhere('task_type', 'image_generation');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm actor có một quyền content/image hợp lệ.
     * =====================================================================
     * INPUT: actor hiện tại. OUTPUT: boolean theo target registry/media.upload.
     * SIDE EFFECT: chỉ đọc permission/config. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function canUse(User $actor): bool
    {
        return $actor->can('media.upload') || collect((array) config('ai.agent.targets'))
            ->contains(fn ($target) => $actor->can($target['permission'] ?? 'posts.manage'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi cancelled vào import còn active.
     * =====================================================================
     * INPUT: source đã lock trong transaction service. OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật status/step/error/completed_at. EXCEPTION/TRANSACTION: không gọi provider.
     * =====================================================================
     */
    public function cancel(Model $source): void
    {
        $import = $this->source($source);
        if (in_array($import->status, ['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired'], true) || $import->expires_at?->isPast()) {
            return;
        }
        $import->forceFill(['status' => 'cancelled', 'current_step' => 'cancelled', 'error_code' => 'CANCELLED', 'error_message' => 'Tác vụ đã được hủy.', 'completed_at' => now()])->save();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ canonical thumbnail sau khi image bị hủy.
     * =====================================================================
     * INPUT: source đã persist cancelled. OUTPUT: không trả giá trị.
     * SIDE EFFECT: gọi thumbnail service cleanup ngoài transaction nguồn.
     * EXCEPTION/TRANSACTION: service xử lý cleanup theo domain.
     * =====================================================================
     */
    public function afterCancel(Model $source): void
    {
        $import = $this->source($source);
        if ($import->operation === 'image') {
            app(AiThumbnailService::class)->sync($import->fresh());
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra source đúng schema của adapter.
     * =====================================================================
     * INPUT: Eloquent model. OUTPUT: AiImport đã ép kiểu.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: sai adapter trả lỗi 500.
     * =====================================================================
     */
    private function source(Model $source): AiImport
    {
        abort_unless($source instanceof AiImport, 500, 'Sai adapter cho import AI.');

        return $source;
    }
}
