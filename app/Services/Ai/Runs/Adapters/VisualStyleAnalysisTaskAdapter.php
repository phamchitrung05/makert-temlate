<?php

namespace App\Services\Ai\Runs\Adapters;

use App\Models\AiImageProfile;
use App\Models\AiTaskRun;
use App\Models\User;
use App\Services\Ai\Errors\AiPublicErrorMessage;
use App\Services\Ai\Runs\Contracts\AiTaskRunAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter tracker cho worker phân tích Visual Style DNA.
 * =====================================================================
 * Cho phép task analyze `AiImageProfile` (Visual Style) hiện trong popup
 * queue dùng chung ở góc phải, giống writing profile/resource template.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - modelClass(): khai báo model profile/style.
 * - project(): tạo projection visual_style_analysis bounded.
 * - matchesSource(): kiểm profile cùng id.
 * - isCurrent(): kiểm tracker còn đúng profile identity.
 * - applyVisibility(): thêm branch quyền ai_settings.manage.
 * - canUse(): kiểm actor có quyền queue.
 * - cancel(): ghi cancelled vào profile còn active.
 * - afterCancel(): hook cleanup no-op.
 * - source(): ép kiểu model theo adapter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImageProfile đã được lock.
 * - OUTPUT: projection visual_style_analysis bounded cho AiTaskRun.
 * - SIDE EFFECT: cancel cập nhật profile; không gọi provider.
 * =====================================================================
 */
final class VisualStyleAnalysisTaskAdapter implements AiTaskRunAdapter
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model class mà adapter nhận xử lý.
     * =====================================================================
     * INPUT: không có. OUTPUT: FQCN AiImageProfile.
     * SIDE EFFECT: không query hoặc ghi database. EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function modelClass(): string
    {
        return AiImageProfile::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo projection bounded cho profile đã được lock.
     * =====================================================================
     * INPUT: Model nguồn. OUTPUT: field AiTaskRun không chứa dna/path nhạy cảm.
     * SIDE EFFECT: chỉ đọc source. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function project(Model $source): array
    {
        $profile = $this->source($source);
        $status = (string) $profile->status;
        if (! in_array($status, ['queued', 'ready', 'analyzed', 'failed', 'cancelled'], true)) {
            $status = 'analyzing';
        }
        $errorCode = $status === 'failed' ? $profile->error_message : null;

        return [
            'dedupe_key' => 'visual-style-analysis:'.$profile->id,
            'task_type' => 'visual_style_analysis',
            'source' => 'visual_style',
            'model' => data_get($profile->connection_snapshot_json, 'model'),
            'provider' => data_get($profile->connection_snapshot_json, 'provider'),
            'status' => $status,
            'progress' => in_array($status, ['ready', 'analyzed'], true) ? 100 : ($status === 'analyzing' ? 50 : 0),
            'user_id' => $profile->created_by,
            'taskable_type' => $profile::class,
            'taskable_id' => (string) $profile->getKey(),
            'metadata_json' => ['name' => $profile->name, 'category' => $profile->category],
            'error_code' => null,
            'error_message' => $errorCode ? mb_substr((string) $errorCode, 0, 120) : null,
            'started_at' => null,
            'completed_at' => in_array($status, ['ready', 'analyzed', 'failed', 'cancelled'], true) ? ($profile->updated_at ?? now()) : null,
            'expires_at' => null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận profile snapshot cùng id với source hiện tại.
     * =====================================================================
     * INPUT: hai model profile. OUTPUT: true khi cùng identity.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function matchesSource(Model $requested, Model $current): bool
    {
        return $requested instanceof AiImageProfile
            && $current instanceof AiImageProfile
            && (string) $requested->getKey() === (string) $current->getKey();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận source vẫn là profile của tracker hiện tại.
     * =====================================================================
     * INPUT: source và task. OUTPUT: true khi cùng model identity.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function isCurrent(Model $source, AiTaskRun $task): bool
    {
        return $source instanceof AiImageProfile;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Scope task theo quyền AI settings.
     * =====================================================================
     * INPUT: Builder đã scope owner và actor authenticated. OUTPUT: query có OR branch.
     * SIDE EFFECT: chỉ sửa query builder. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function applyVisibility(Builder $query, User $actor): void
    {
        if ($actor->can('ai_settings.manage')) {
            $query->orWhere('task_type', 'visual_style_analysis');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra actor có quyền dùng analysis queue.
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
     * CHỨC NĂNG: Ghi cancelled nếu profile còn active.
     * =====================================================================
     * INPUT: source đã lock trong transaction của service. OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật status. EXCEPTION/TRANSACTION: không gọi provider.
     * =====================================================================
     */
    public function cancel(Model $source): void
    {
        $profile = $this->source($source);
        if (! in_array($profile->status, ['ready', 'analyzed', 'failed', 'cancelled'], true)) {
            $profile->forceFill(['status' => 'cancelled'])->save();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kết thúc cleanup cho profile bị hủy.
     * =====================================================================
     * INPUT: source đã persist. OUTPUT: không trả giá trị.
     * SIDE EFFECT: không có tài nguyên ngoài database cần dọn.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function afterCancel(Model $source): void
    {
        // Analysis chỉ giữ file ảnh local; không cần cleanup ngoài DB.
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra source đúng schema của adapter.
     * =====================================================================
     * INPUT: Eloquent model. OUTPUT: AiImageProfile đã ép kiểu.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: sai adapter trả lỗi 500.
     * =====================================================================
     */
    private function source(Model $source): AiImageProfile
    {
        abort_unless($source instanceof AiImageProfile, 500, 'Sai adapter cho Visual Style.');

        return $source;
    }
}
