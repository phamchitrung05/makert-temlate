<?php

namespace App\Services\Ai\Runs\Adapters;

use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\AiTaskRun;
use App\Models\ResourceVersionTemplate;
use App\Models\User;
use App\Services\Ai\Runs\Contracts\AiTaskRunAdapter;
use App\Services\Resources\TemplateVersionProcessingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter tracker cho queue xử lý Resource Template
 * =====================================================================
 *
 * Adapter chiếu processing_status của ResourceVersionTemplate vào tracker
 * dùng chung để popup Admin hiển thị queue template cạnh các task AI. Token
 * xử lý nằm trong dedupe key/metadata để worker cũ không ghi đè lượt mới.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - modelClass(): khai báo model nguồn.
 * - project(): tạo projection bounded cho task center.
 * - matchesSource(): kiểm version/template/token của worker.
 * - isCurrent(): kiểm tracker còn đúng processing token.
 * - applyVisibility(): scope theo quyền resource_versions.manage/resources.create.
 * - canUse(): kiểm actor có quyền quản lý queue template.
 * - cancel(): đánh dấu template failed do user hủy.
 * - afterCancel(): dọn staging/artifact theo token.
 * - source(): ép kiểu model theo adapter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersionTemplate đã được lock.
 * - OUTPUT: projection resource_template_processing bounded cho AiTaskRun.
 * - SIDE EFFECT: cancel có thể dọn artifact private qua processing service.
 * - EXCEPTION/TRANSACTION: caller quản lý transaction; không gọi provider.
 * =====================================================================
 */
final class ResourceTemplateTaskAdapter implements AiTaskRunAdapter
{
    private const CANCELLED_MESSAGE = 'Tác vụ xử lý template đã được hủy.';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model class mà adapter nhận xử lý
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: FQCN ResourceVersionTemplate.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function modelClass(): string
    {
        return ResourceVersionTemplate::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chiếu processing state thành task center metadata
     * =====================================================================
     * INPUT: ResourceVersionTemplate đã lock.
     * OUTPUT: projection allowlist, không chứa HTML/raw exception.
     * SIDE EFFECT: Đọc version/resource để lấy tên và owner.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function project(Model $source): array
    {
        $template = $this->source($source);
        $version = $template->resourceVersion;
        $resource = $version?->resource;
        $status = match ($template->processing_status) {
            ResourceVersionTemplateProcessingStatus::Pending => 'queued',
            ResourceVersionTemplateProcessingStatus::Processing => 'processing',
            ResourceVersionTemplateProcessingStatus::Ready => 'ready',
            ResourceVersionTemplateProcessingStatus::Failed => $template->processing_error === self::CANCELLED_MESSAGE
                ? 'cancelled'
                : 'failed',
        };

        return [
            'dedupe_key' => 'resource-template:'.$template->resource_version_id.':'.$template->processing_token,
            'task_type' => 'resource_template_processing',
            'source' => 'resource_template',
            'model' => null,
            'provider' => 'template-parser',
            'status' => $status,
            'progress' => match ($status) {
                'ready' => 100,
                'processing' => 50,
                default => 0,
            },
            'user_id' => $version?->created_by ?? $resource?->created_by,
            'taskable_type' => $template::class,
            'taskable_id' => (string) $template->getKey(),
            'metadata_json' => [
                'name' => 'Xử lý template'.($version?->version ? ' v'.$version->version : ''),
                'resource_version_id' => $template->resource_version_id,
                'resource_id' => $version?->resource_id,
                'source_media_id' => $template->source_media_id,
                'processing_token' => $template->processing_token,
            ],
            'error_code' => $status === 'failed' ? 'TEMPLATE_PROCESSING_FAILED' : null,
            'error_message' => $status === 'failed' ? $template->processing_error : null,
            'started_at' => $template->processing_status === ResourceVersionTemplateProcessingStatus::Processing
                ? ($template->updated_at ?? now())
                : null,
            'completed_at' => in_array($status, ['ready', 'failed'], true) ? ($template->processed_at ?? now()) : null,
            'expires_at' => null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm template snapshot còn đúng worker request
     * =====================================================================
     * INPUT: requested/current ResourceVersionTemplate.
     * OUTPUT: true khi cùng version, package và processing token.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function matchesSource(Model $requested, Model $current): bool
    {
        return $requested instanceof ResourceVersionTemplate
            && $current instanceof ResourceVersionTemplate
            && (int) $requested->resource_version_id === (int) $current->resource_version_id
            && (string) $requested->processing_token === (string) $current->processing_token
            && (string) $requested->package_sha256 === (string) $current->package_sha256;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn tracker cũ đồng bộ sau lượt processing mới
     * =====================================================================
     * INPUT: source và AiTaskRun hiện tại.
     * OUTPUT: true khi token/version/source media trùng metadata tracker.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function isCurrent(Model $source, AiTaskRun $task): bool
    {
        $template = $this->source($source);
        $metadata = (array) $task->metadata_json;

        return (int) $template->resource_version_id === (int) ($metadata['resource_version_id'] ?? $template->resource_version_id)
            && (string) $template->processing_token === (string) ($metadata['processing_token'] ?? '')
            && (int) $template->source_media_id === (int) ($metadata['source_media_id'] ?? $template->source_media_id);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Scope queue template theo quyền resource version
     * =====================================================================
     * INPUT: Builder đã scope owner và actor.
     * OUTPUT: query có branch task_type resource_template_processing.
     * SIDE EFFECT: Chỉ sửa query builder.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function applyVisibility(Builder $query, User $actor): void
    {
        if ($this->canUse($actor)) {
            $query->orWhere('task_type', 'resource_template_processing');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm actor có thể xem queue xử lý template
     * =====================================================================
     * INPUT: User admin hiện tại.
     * OUTPUT: boolean theo permission resource_versions.manage/resources.create.
     * SIDE EFFECT: Chỉ đọc permission.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function canUse(User $actor): bool
    {
        return $actor->can('resource_versions.manage') || $actor->can('resources.create');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đánh dấu processing failed khi actor hủy task
     * =====================================================================
     * INPUT: template source đang lock.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Ghi failed/error public-safe vào template.
     * EXCEPTION/TRANSACTION: Caller giữ transaction; không gọi provider.
     * =====================================================================
     */
    public function cancel(Model $source): void
    {
        $template = $this->source($source);
        if ($template->processing_status !== ResourceVersionTemplateProcessingStatus::Processing
            && $template->processing_status !== ResourceVersionTemplateProcessingStatus::Pending) {
            return;
        }

        $template->forceFill([
            'processing_status' => ResourceVersionTemplateProcessingStatus::Failed,
            'processing_error' => self::CANCELLED_MESSAGE,
            'processed_at' => null,
        ])->save();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn staging/artifact sau khi template bị hủy
     * =====================================================================
     * INPUT: template đã persist trạng thái failed.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Xóa đúng token directory trên private disk.
     * EXCEPTION/TRANSACTION: Cleanup best-effort; không che lỗi cancel.
     * =====================================================================
     */
    public function afterCancel(Model $source): void
    {
        $template = $this->source($source);
        if ($template->processing_token !== null) {
            app(TemplateVersionProcessingService::class)->cleanupArtifacts(
                (int) $template->resource_version_id,
                (string) $template->processing_token,
            );
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép model source đúng schema của adapter
     * =====================================================================
     * INPUT: Eloquent model.
     * OUTPUT: ResourceVersionTemplate.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Sai model ném lỗi cấu hình.
     * =====================================================================
     */
    private function source(Model $source): ResourceVersionTemplate
    {
        abort_unless($source instanceof ResourceVersionTemplate, 500, 'Sai adapter cho resource template.');

        return $source;
    }
}
