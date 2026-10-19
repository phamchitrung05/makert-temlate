<?php

namespace App\Http\Resources\Ai;

use App\Http\Resources\Media\MediaAssetResource;
use App\Models\AiImport;
use App\Models\AiTaskRun;
use App\Services\Ai\Content\AiContentReviewService;
use App\Services\Ai\Content\Quality\ArticleQualityEvaluationService;
use App\Services\Ai\Errors\AiPublicErrorMessage;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Services\Ai\Registries\TargetRegistry;
use App\Services\Ai\Runs\AiImportQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize chi tiết AI import theo allowlist public.
 * =====================================================================
 * Controller kiểm ownership trước khi trả resource. Giữ nguyên contract
 * lifecycle, candidate, thumbnail và diagnostics; không xuất raw provider.
 * CÁC HÀM/METHOD TRONG FILE:
 * - toArray(): dựng payload public và hydrate thumbnail còn thiếu.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): AiImport đã được cấp quyền -> payload API.
 * =====================================================================
 */
final class AiImportResource extends JsonResource
{
    /** Input: request và AiImport đã kiểm quyền. Output: payload bounded; chỉ đọc database. */
    public function toArray(Request $request): array
    {
        $import = $this->resource;
        if (! $import->relationLoaded('thumbnail')) {
            app(AiImportQueryService::class)->loadThumbnails(collect([$import]));
        }
        $thumbnail = $import->getRelation('thumbnail');
        $result = array_intersect_key((array) $import->result_json, array_flip([
            'source', 'draft', 'provider', 'model', 'prompt_key', 'prompt_version',
            'schema_version', 'requested_fields', 'image', 'image_job_id',
        ]));
        if (! in_array($import->status, ['ready', 'completed', 'succeeded'], true)) {
            unset($result['draft']);
        }
        $diagnostics = AiResponseDiagnostics::sanitize((array) data_get($import->source_meta_json, 'ai_response', []));
        $targetType = (string) data_get($import->input_json, 'target_type', 'post');
        $platform = data_get($import->input_json, 'platform');
        try {
            $outputOptions = app(TargetRegistry::class)->outputOptions($targetType, is_string($platform) ? $platform : null);
        } catch (\InvalidArgumentException) {
            $outputOptions = [];
        }
        $pipeline = (array) data_get($import->source_meta_json, 'article_pipeline', []);
        $taskRunId = AiTaskRun::query()
            ->where('taskable_type', AiImport::class)
            ->where('taskable_id', $import->id)
            ->where('dedupe_key', ($import->operation === 'image' ? 'image_generation' : 'article_generation').':'.$import->id.':'.(int) $import->generation_no)
            ->value('id');
        $steps = $import->steps->map(fn ($step): array => [
            'key' => $step->step_key, 'status' => $step->status, 'attempt' => $step->attempt,
            'diagnostics' => AiResponseDiagnostics::sanitize((array) $step->diagnostics_json),
            'started_at' => $step->started_at?->toIso8601String(), 'completed_at' => $step->completed_at?->toIso8601String(),
        ])->values()->all();

        return [
            ...$result,
            'job_id' => $import->id, 'status' => $import->status, 'current_step' => $import->current_step,
            'task_run_id' => $taskRunId,
            'generation_no' => (int) $import->generation_no,
            'steps' => $steps,
            // Allowlist dùng cả cho nhánh một lượt; không expose source/intermediate/raw response.
            'response_diagnostics' => $diagnostics,
            'quality_checks' => array_map(fn (array $check): array => array_intersect_key($check, array_flip(['check', 'status', 'reason', 'metric', 'detected', 'count'])), array_slice((array) ($pipeline['quality'] ?? []), 0, 20)),
            'image_warnings' => array_values(array_intersect((array) ($pipeline['image_warnings'] ?? []), ['restored_unplaced_images'])),
            'editor_warning_count' => max(0, min(100, (int) ($pipeline['editor_warning_count'] ?? 0))),
            'writing_profile' => array_intersect_key((array) data_get($import->input_json, 'writing_profile_snapshot', []), array_flip(['id', 'name', 'version'])),
            'source_format' => data_get($import->input_json, 'source_format', 'text'),
            'progress' => (int) $import->progress, 'error_code' => $import->error_code,
            'source_type' => data_get($import->input_json, 'source_type', filled($import->source_url) ? 'url' : 'text'),
            'source_url' => data_get($import->input_json, 'source_type') === 'text' ? null : $import->source_url,
            'error' => in_array($import->status, ['failed', 'cancelled', 'expired'], true)
                ? AiPublicErrorMessage::message($import->error_code, $import->error_message,
                    data_get($import->input_json, 'target_type', 'post'), (array) ($diagnostics['validation_errors'] ?? []))
                : null,
            'session_id' => $import->session_id ?: $import->id,
            'validation_errors' => $import->status === 'failed' ? ($diagnostics['validation_errors'] ?? []) : [],
            'parent_id' => $import->parent_id, 'operation' => $import->operation,
            'target_type' => $targetType,
            'platform' => $platform,
            'output_groups' => array_values((array) data_get($import->input_json, 'pipeline_snapshot.outputs', [])),
            'output_options' => $outputOptions,
            'editor_fields' => array_values((array) data_get($import->input_json, 'pipeline_snapshot.editor_fields', [])),
            'manual_taxonomy' => (bool) data_get($import->input_json, 'pipeline_snapshot.manual_taxonomy', false),
            'pipeline' => (array) data_get($import->input_json, 'pipeline_snapshot.pipeline', []),
            'created_at' => $import->created_at?->toIso8601String(),
            'draft_version' => hash('sha256', json_encode(data_get($import->result_json, 'draft', []))),
            'review' => AiContentReviewService::state($import),
            'quality_evaluation' => app(ArticleQualityEvaluationService::class)->summary($import),
            'review_version' => AiContentReviewService::version($import),
            'expires_at' => $import->expires_at?->toIso8601String(),
            'applied_target_id' => $import->applied_target_id, 'applied_fields' => $import->applied_fields,
            'thumbnail' => $thumbnail ? MediaAssetResource::make($thumbnail) : null,
            'thumbnail_generation' => AiThumbnailService::state($import),
            'thumbnail_options' => ['mode' => data_get($import->input_json, 'thumbnail_mode', 'source'),
                'model_id' => data_get($import->input_json, 'image_connection.model_id'),
                'prompt' => data_get($import->input_json, 'thumbnail_prompt', '')],
        ];
    }
}
