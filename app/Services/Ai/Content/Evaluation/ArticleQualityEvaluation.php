<?php

namespace App\Services\Ai\Content\Evaluation;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\AiContentSanitizer;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Quality\ArticleQualityGate;
use App\Services\Ai\Contracts\AiProviderContract;
use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đánh giá pipeline C trên source/brief/profile/model đã đóng băng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: plannedCalls(), run().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): snapshot/input/adapter/nhánh -> kết quả và artifacts thật.
 * SIDE EFFECT: call model có budget; transient AiImport không tồn tại trong DB.
 * Không Apply, publish, tạo asset, sửa default hay chấm thay người đọc.
 * =====================================================================
 */
final class ArticleQualityEvaluation
{
    /**
     * =====================================================================
     * Input: số case và nhánh.
     * Output: budget tối đa; A chưa có baseline phục hồi.
     * =====================================================================
     */
    public function plannedCalls(int $cases, array $arms): int
    {
        if ($cases < 1 || $arms !== ['C']) {
            throw new InvalidArgumentException('Đánh giá mới chỉ chạy luồng C ba bước; luồng B đã được gỡ.');
        }

        return $cases * 3;
    }

    /**
     * =====================================================================
     * Input: source đã hash, input immutable, provider và luồng C.
     * Output: trạng thái kỹ thuật, final/call artifacts, usage/latency; không score.
     * Không transaction/DB queue; không khôi phục output thiếu bằng nội dung nguồn.
     * =====================================================================
     */
    public function run(array $source, array $input, AiProviderContract $provider, string $arm, int $maxCalls): array
    {
        $planned = $this->plannedCalls(1, [$arm]);
        if ($maxCalls < $planned) {
            throw new InvalidArgumentException('Ngân sách chưa đủ cho nhánh đã chọn.');
        }
        if (! $provider->configured() || $provider->providerName() === 'deterministic') {
            throw new InvalidArgumentException('Provider chưa cấu hình; không chạy deterministic để lấy kết quả đánh giá.');
        }
        $input['target_type'] = 'post';
        $input['source_type'] = 'text';
        $input['fields'] = ['title', 'content'];
        $input['category_ids'] = $input['tag_ids'] = [];
        $input['thumbnail_mode'] = 'skip';
        $input['pipeline_snapshot']['pipeline'] = 'three_step';
        $source['inline_image_refs'] = [];
        $source['snapshot_run_id'] = '';
        $import = new AiImport(['input_json' => $input, 'source_text' => $source['content_html'], 'source_meta_json' => ['article_source' => $source]]);
        $recorder = new EvaluationProviderRecorder($provider, $planned);
        $started = microtime(true);
        $result = ['arm' => $arm, 'status' => 'failed', 'error_code' => null, 'final' => null, 'quality_checks' => [], 'cost' => null];
        try {
            $generated = (new ArticleImportService($recorder))->run($import);
            $result['status'] = 'ready';
            $result['final'] = array_intersect_key($generated['draft'], array_flip(['title', 'content_html']));
            $analysis = $recorder->calls[0]['output'] ?? [];
            $result['quality_checks'] = (new ArticleQualityGate)->inspect($source, $result['final'], (string) ($input['language'] ?? 'vi'), $analysis, $input['pipeline_snapshot']['quality'] ?? []);
        } catch (AiImportException $exception) {
            $result['error_code'] = $exception->errorCode;
            $result['diagnostics'] = $exception->diagnostics;
        }
        $result['calls'] = $recorder->calls;
        $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        $result['input_sha256'] = ArticleInputHasher::hash($input);
        $pairedInput = $input;
        unset($pairedInput['pipeline_snapshot']['pipeline']);
        $result['comparison_input_sha256'] = ArticleInputHasher::hash($pairedInput);
        $result['writing_profile_sha256'] = ArticleInputHasher::hash((array) ($input['writing_profile_snapshot'] ?? []));
        $result['brief_sha256'] = ArticleInputHasher::hash((array) ($input['writing_brief'] ?? []));
        $result['image_evaluation'] = ['mode' => 'source_metadata_only', 'source_images' => count($source['source_images'] ?? []), 'approved_asset_regenerate' => 'not_evaluated', 'pixel_understanding' => 'not_evaluated'];
        $result['source_sha256'] = ArticleInputHasher::hash($source);
        $result['provider'] = $provider->providerName();
        $result['model'] = $provider->modelName();
        $lastOutput = $recorder->calls === [] ? [] : $recorder->calls[array_key_last($recorder->calls)]['output'];
        $candidate = $lastOutput['final'] ?? null;
        $result['candidate_for_review'] = $result['final'] ?? (is_array($candidate) && isset($candidate['content_html'])
            ? ['title' => (string) ($candidate['title'] ?? ''), 'content_html' => (new AiContentSanitizer)->sanitize((string) $candidate['content_html'])] : null);
        $totals = array_column(array_column($recorder->calls, 'diagnostics'), 'usage');
        $result['total_tokens'] = $recorder->calls !== [] && count($totals) === count($recorder->calls) && count(array_filter($totals, fn (array $usage): bool => isset($usage['total_tokens']))) === count($recorder->calls)
            ? array_sum(array_column($totals, 'total_tokens')) : null;

        return $result;
    }
}
