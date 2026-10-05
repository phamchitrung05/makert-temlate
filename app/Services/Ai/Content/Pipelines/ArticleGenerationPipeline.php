<?php

namespace App\Services\Ai\Content\Pipelines;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\AiImportStep;
use App\Services\Ai\Content\Agents\AnalyzerPlannerAgent;
use App\Services\Ai\Content\Agents\EditorAgent;
use App\Services\Ai\Content\Agents\WriterAgent;
use App\Services\Ai\Content\AiContentSanitizer;
use App\Services\Ai\Content\AiOutputValidator;
use App\Services\Ai\Content\AiTaskSchemaValidator;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use App\Services\Ai\Content\Quality\ArticleEvidenceValidator;
use App\Services\Ai\Content\Quality\ArticleQualityGate;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối Analyze + Plan → Write → Edit và checkpoint.
 * =====================================================================
 * Reuse provider/queue/AiImport hiện có; không ghi Post hoặc auto-publish.
 * CÁC HÀM/METHOD TRONG FILE: run(), step(), boundary(), restoreImages(), metadata().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : run, provider, source/profile/settings snapshots và selected fields.
 * - OUTPUT: final canonical đã sanitize/quality; metadata lưu riêng source_meta.
 * - SIDE EFFECT: gọi AI ba lần, lưu checkpoint từng bước sau validate.
 * =====================================================================
 */
final class ArticleGenerationPipeline
{
    /**
     * =====================================================================
     * Input: AiImport, provider, source snapshot và field groups thật sự yêu cầu.
     * Output: final fields; ném provider/schema/ref/quality/cancelled, không fallback.
     * SIDE EFFECT: checkpoint DB sau từng bước; không mở transaction qua HTTP.
     * =====================================================================
     */
    public function run(AiImport $import, AiProviderContract $provider, array $source, array $fields): array
    {
        $input = (array) $import->input_json;
        $settings = (array) ($input['pipeline_snapshot'] ?? config('ai-content', []));
        $groups = array_values(array_diff($fields === [] ? ['title', 'content'] : $fields, ['thumbnail', 'taxonomy']));
        $profile = (array) ($input['writing_profile_snapshot'] ?? []);
        $writingBrief = (array) ($input['writing_brief'] ?? []);
        $brief = array_filter([
            'language' => $input['language'] ?? 'vi', 'title' => $input['title'] ?? null,
            'audience' => $writingBrief['audience'] ?? $input['audience'] ?? null, 'article_type' => $writingBrief['article_type'] ?? $input['article_type'] ?? null,
            'purpose' => $writingBrief['purpose'] ?? null,
            'angle' => $writingBrief['angle'] ?? $input['angle'] ?? null, 'length' => $writingBrief['length'] ?? $input['length'] ?? $input['word_count'] ?? null,
            'additional_instructions' => $input['instructions'] ?? '',
            'website_instructions' => data_get($input, 'ai_connection.system_prompt', $input['system_prompt'] ?? ''),
            'requested_fields' => $groups,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $refs = (array) ($source['inline_image_refs'] ?? []);
        $context = [
            'source' => array_diff_key($source, array_flip(['inline_image_refs', 'source_images', 'snapshot_run_id'])),
            'brief' => $brief, 'profile' => $profile,
            'images' => array_map(fn (string $id): array => ['id' => $id, 'placeholder' => '<span data-ai-image-ref="'.$id.'"></span>'], array_keys($refs)),
        ];
        $evidence = new ArticleEvidenceValidator;
        $analysis = $this->step($import, $provider, 'analyzing', 'article.analysis-plan', $context, $groups, $settings,
            fn () => (new AnalyzerPlannerAgent)->run($provider, $context, $groups, $settings),
            fn (array $output) => $evidence->analysis($output, $source));
        $context['analysis'] = $analysis;
        $writer = $this->step($import, $provider, 'writing', 'article.writer', $context, $groups, $settings,
            fn () => (new WriterAgent)->run($provider, $context, $groups, $settings),
            function (array $output) use ($evidence, $analysis, $refs, $groups): void {
                $evidence->references($output['used_fact_ids'], $analysis);
                if (array_diff($output['used_asset_ids'], array_keys($refs)) !== []) {
                    throw new AiImportException('Writer trả tham chiếu ảnh không có trong nguồn.', 'AI_SOURCE_REFERENCE');
                }
                (new AiOutputValidator)->validate($output['draft'], $groups);
            });
        $context['draft'] = $writer['draft'];
        $editor = $this->step($import, $provider, 'editing', 'article.editor', $context, $groups, $settings,
            fn () => (new EditorAgent)->run($provider, $context, $groups, $settings),
            function (array $output) use ($evidence, $analysis, $groups): void {
                $evidence->references($output['used_fact_ids'], $analysis, requireImportant: true);
                (new AiOutputValidator)->validate($output['final'], $groups);
                if (array_filter($output['issues'], fn (array $issue): bool => $issue['severity'] === 'blocking') !== []) {
                    throw new AiImportException('Editor phát hiện thông tin chưa đủ căn cứ; hãy xem nguồn và tạo lại có chủ ý.', 'AI_QUALITY_EDITOR');
                }
            });
        $this->boundary($import, 'validating', 76);
        $final = $editor['final'];
        $imageWarnings = [];
        if (isset($final['content_html'])) {
            [$final['content_html'], $imageWarnings] = $this->restoreImages($final['content_html'], $refs);
        }
        $final = (new AiOutputValidator)->validate($final, $groups, sanitizeContent: true);
        $checks = (new ArticleQualityGate)->inspect($source, $final, (string) ($input['language'] ?? 'vi'), $analysis, (array) ($settings['quality'] ?? []));
        $this->metadata($import, [
            'pipeline' => 'three_step', 'quality' => $checks, 'image_warnings' => $imageWarnings,
            'editor_warning_count' => count($editor['issues']),
        ]);

        return $final;
    }

    /**
     * =====================================================================
     * Input: stage request/schema/context, callback gọi AI và semantic validator.
     * Output: output mới hoặc checkpoint hash khớp đã kiểm lại; không blind retry.
     * SIDE EFFECT: ghi step started/completed/failed; lỗi luôn truyền lên job.
     * =====================================================================
     */
    private function step(AiImport $import, AiProviderContract $provider, string $step, string $task, array $context, array $groups, array $settings, callable $execute, callable $validate): array
    {
        $this->boundary($import, $step, match ($step) {
            'analyzing' => 42, 'writing' => 55, default => 68
        });
        $request = (new ArticlePromptBuilder)->build($task, $context, $groups, $settings);
        $hash = ArticleInputHasher::hash([
            'request' => $request, 'provider' => $provider->providerName(), 'model' => $provider->modelName(),
            'connection' => data_get($import->input_json, 'ai_connection'),
            'inline_image_refs' => data_get($import->source_meta_json, 'article_source.inline_image_refs', []),
        ]);
        $artifact = null;
        if ($import->exists) {
            $artifact = AiImportStep::query()->firstOrNew(['ai_import_id' => $import->getKey(), 'step_key' => $task, 'input_hash' => $hash]);
            if ($artifact->status === 'completed' && is_array($artifact->output_json)) {
                $output = (new AiTaskSchemaValidator)->validate($artifact->output_json, $request->schema);
                $validate($output);

                return $output;
            }
            $artifact->forceFill(['status' => 'running', 'started_at' => now(), 'completed_at' => null, 'attempt' => $artifact->exists ? (int) $artifact->attempt + 1 : 1])->save();
        }
        try {
            $started = microtime(true);
            $response = $execute();
            $output = (new AiTaskSchemaValidator)->validate($response->output, $request->schema);
            $validate($output);
            $this->boundary($import);
            $diagnostics = AiResponseDiagnostics::sanitize($response->diagnostics);
            $diagnostics += [
                'task' => $task, 'prompt_version' => $request->options['prompt_version'], 'schema_version' => $request->options['schema_version'],
                'provider' => $provider->providerName(), 'model' => $provider->modelName(), 'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            $artifact?->forceFill(['status' => 'completed', 'output_json' => $output, 'diagnostics_json' => $diagnostics, 'completed_at' => now()])->save();

            return $output;
        } catch (AiImportException $exception) {
            $artifact?->forceFill(['status' => $exception->errorCode === 'CANCELLED' ? 'cancelled' : 'failed', 'output_json' => null,
                'diagnostics_json' => AiResponseDiagnostics::sanitize($exception->diagnostics) + ['error_code' => $exception->errorCode], 'completed_at' => now()])->save();
            throw $exception;
        }
    }

    /**
     * =====================================================================
     * Input: run và step/progress tùy chọn.
     * Output: kiểm cancel ở boundary; có thể update progress, không ghi ready.
     * =====================================================================
     */
    private function boundary(AiImport $import, ?string $step = null, int $progress = 0): void
    {
        if (! $import->exists) {
            return;
        }
        $import->refresh();
        if ($import->isCancelled()) {
            throw new AiImportException('Import đã bị hủy.', 'CANCELLED');
        }
        if ($import->status === 'expired' || ($import->expires_at !== null && $import->expires_at->isPast())) {
            throw new AiImportException('Run đã hết hạn; hãy tạo tác vụ mới.', 'EXPIRED');
        }
        if (in_array($import->status, ['ready', 'applied', 'failed'], true)) {
            throw new AiImportException('Run không còn ở trạng thái đang xử lý.', 'RUN_NOT_ACTIVE');
        }
        if ($step !== null) {
            $import->advance($step, $progress);
            $import->refresh();
            if ($import->isCancelled()) {
                throw new AiImportException('Import đã bị hủy.', 'CANCELLED');
            }
            if (in_array($import->status, ['expired', 'ready', 'applied', 'failed'], true)) {
                throw new AiImportException('Run đã kết thúc trước khi bước kế tiếp bắt đầu.', 'RUN_NOT_ACTIVE');
            }
        }
    }

    /**
     * =====================================================================
     * Input: final HTML và map ảnh parent đã được backend giữ.
     * Output: HTML khôi phục ảnh; ref giả/URL ảnh tự tạo bị chặn, ảnh thiếu nối cuối.
     * SIDE EFFECT: chỉ DOM memory; ảnh thiếu có warning để editor đổi vị trí.
     * =====================================================================
     */
    public function restoreImages(string $html, array $refs): array
    {
        if (preg_match('/<img\b/i', $html)) {
            throw new AiImportException('AI không được tự trả URL ảnh; chỉ dùng tham chiếu ảnh đã cấp.', 'AI_SOURCE_REFERENCE');
        }
        $used = [];
        $html = preg_replace_callback('/<span\b[^>]*data-ai-image-ref=["\']([^"\']+)["\'][^>]*>\s*<\/span>/i', function (array $match) use ($refs, &$used): string {
            if (! isset($refs[$match[1]]) || in_array($match[1], $used, true)) {
                throw new AiImportException('AI trả tham chiếu ảnh lạ hoặc lặp.', 'AI_SOURCE_REFERENCE');
            }
            $used[] = $match[1];

            return $refs[$match[1]];
        }, $html) ?? $html;
        if (str_contains($html, 'data-ai-image-ref')) {
            throw new AiImportException('AI trả placeholder ảnh không hợp lệ.', 'AI_SOURCE_REFERENCE');
        }
        $missing = array_diff(array_keys($refs), $used);
        foreach ($missing as $id) {
            $html .= '<figure>'.$refs[$id].'</figure>';
        }

        return [(new AiContentSanitizer)->sanitize($html), $missing === [] ? [] : ['restored_unplaced_images']];
    }

    /**
     * =====================================================================
     * Input: quality/progress metadata bounded, không chứa raw provider response.
     * Output: lưu riêng source_meta_json.article_pipeline; không mutate public draft.
     * =====================================================================
     */
    private function metadata(AiImport $import, array $metadata): void
    {
        if ($import->exists) {
            $this->boundary($import);
            $import->forceFill(['source_meta_json' => array_replace((array) $import->source_meta_json, ['article_pipeline' => $metadata])])->save();
        }
    }
}
