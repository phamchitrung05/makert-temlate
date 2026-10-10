<?php

namespace App\Services\Ai\Runs\Adapters;

use App\Models\AiTaskRun;
use App\Models\AiWritingProfileAnalysis;
use App\Models\User;
use App\Services\Ai\Errors\AiPublicErrorMessage;
use App\Services\Ai\Runs\Contracts\AiTaskRunAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter tracker cho worker phân tích bài mẫu văn phong.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - modelClass(): khai báo model analysis.
 * - project(): tạo projection writing_profile_analysis bounded.
 * - matchesSource(): kiểm analysis UUID worker/source hiện tại.
 * - isCurrent(): kiểm tracker còn đúng analysis identity.
 * - applyVisibility(): thêm branch quyền ai_settings.manage.
 * - canUse(): kiểm actor có quyền analysis queue.
 * - cancel(): ghi cancelled vào analysis active.
 * - afterCancel(): hook cleanup no-op cho analysis.
 * - source(): ép kiểu model theo adapter.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiWritingProfileAnalysis đã được lock.
 * - OUTPUT: projection writing_profile_analysis bounded.
 * - SIDE EFFECT: cancel cập nhật analysis; không gọi provider.
 * =====================================================================
 */
final class AiWritingProfileAnalysisTaskAdapter implements AiTaskRunAdapter
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model class mà adapter nhận xử lý.
     * =====================================================================
     * INPUT: không có. OUTPUT: FQCN AiWritingProfileAnalysis.
     * SIDE EFFECT: không query hoặc ghi database. EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function modelClass(): string
    {
        return AiWritingProfileAnalysis::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo projection bounded cho analysis đã được lock.
     * =====================================================================
     * INPUT: Model nguồn. OUTPUT: field AiTaskRun không chứa reference text/result.
     * SIDE EFFECT: chỉ đọc source. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function project(Model $source): array
    {
        $analysis = $this->source($source);
        $status = $analysis->expires_at?->isPast() ? 'expired' : $analysis->status;
        if (! in_array($status, ['queued', 'ready', 'failed', 'cancelled', 'expired'], true)) {
            $status = 'analyzing';
        }
        $errorCode = $status === 'failed' ? $analysis->error_code : null;

        return [
            'dedupe_key' => 'writing-profile-analysis:'.$analysis->id,
            'task_type' => 'writing_profile_analysis',
            'source' => 'ai_writing_profile',
            'model' => data_get($analysis->connection_snapshot_json, 'model'),
            'provider' => data_get($analysis->connection_snapshot_json, 'provider'),
            'status' => $status,
            'progress' => $status === 'ready' ? 100 : ($status === 'analyzing' ? 50 : 0),
            'user_id' => $analysis->created_by,
            'taskable_type' => $analysis::class,
            'taskable_id' => (string) $analysis->id,
            'metadata_json' => ['name' => $analysis->name, 'draft_profile_id' => $analysis->draft_profile_id],
            'error_code' => $errorCode ? mb_substr((string) $errorCode, 0, 120) : null,
            'error_message' => $errorCode ? AiPublicErrorMessage::message($errorCode, $analysis->error_message, 'writing_profile_analysis') : null,
            'started_at' => $analysis->started_at,
            'completed_at' => in_array($status, ['ready', 'failed', 'cancelled', 'expired'], true) ? ($analysis->completed_at ?? now()) : null,
            'expires_at' => $analysis->expires_at,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận analysis snapshot cùng UUID với source hiện tại.
     * =====================================================================
     * INPUT: hai model analysis. OUTPUT: true khi cùng identity.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function matchesSource(Model $requested, Model $current): bool
    {
        return $requested instanceof AiWritingProfileAnalysis
            && $current instanceof AiWritingProfileAnalysis
            && (string) $requested->getKey() === (string) $current->getKey();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận source vẫn là analysis của tracker hiện tại.
     * =====================================================================
     * INPUT: source và task. OUTPUT: true khi cùng identity UUID.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function isCurrent(Model $source, AiTaskRun $task): bool
    {
        return $source instanceof AiWritingProfileAnalysis;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Scope analysis theo quyền AI settings.
     * =====================================================================
     * INPUT: Builder đã scope owner và actor authenticated. OUTPUT: query có OR branch.
     * SIDE EFFECT: chỉ sửa query builder. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function applyVisibility(Builder $query, User $actor): void
    {
        if ($actor->can('ai_settings.manage')) {
            $query->orWhere('task_type', 'writing_profile_analysis');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm actor có quyền dùng analysis queue.
     * =====================================================================
     * INPUT: actor hiện tại. OUTPUT: boolean theo ai_settings.manage.
     * SIDE EFFECT: chỉ đọc permission. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function canUse(User $actor): bool
    {
        return $actor->can('ai_settings.manage');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi cancelled nếu analysis còn active.
     * =====================================================================
     * INPUT: source đã lock trong transaction của service. OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật status/completed_at. EXCEPTION/TRANSACTION: không gọi provider.
     * =====================================================================
     */
    public function cancel(Model $source): void
    {
        $analysis = $this->source($source);
        if (! in_array($analysis->status, ['ready', 'failed', 'cancelled', 'expired'], true) && ! $analysis->expires_at?->isPast()) {
            $analysis->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kết thúc cleanup cho analysis bị hủy.
     * =====================================================================
     * INPUT: source đã persist. OUTPUT: không trả giá trị.
     * SIDE EFFECT: không có tài nguyên ngoài database cần dọn.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function afterCancel(Model $source): void
    {
        // Analysis không giữ tài nguyên ngoài database nên không cần cleanup.
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra source đúng schema của adapter.
     * =====================================================================
     * INPUT: Eloquent model. OUTPUT: AiWritingProfileAnalysis đã ép kiểu.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: sai adapter trả lỗi 500.
     * =====================================================================
     */
    private function source(Model $source): AiWritingProfileAnalysis
    {
        abort_unless($source instanceof AiWritingProfileAnalysis, 500, 'Sai adapter cho analysis AI.');

        return $source;
    }
}
