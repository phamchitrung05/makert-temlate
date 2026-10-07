<?php

namespace App\Services\Ai\Content\Evaluation;

use App\Services\Ai\Content\Data\ArticleInputHasher;
use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tổng hợp phiên C và báo cáo B/C lịch sử, giữ cả bài lỗi.
 * CÁC HÀM/METHOD: build(), verifyPair(), markdown(), number().
 * INPUT/OUTPUT: manifest/sources/artifacts/nhãn/rows -> report JSON/Markdown.
 * SIDE EFFECT: không AI/DB/I/O; không suy accuracy từ gate hoặc token thiếu = 0.
 * =====================================================================
 */
final class ArticleQualityStudyReport
{
    /**
     * =====================================================================
     * Input: corpus đã kiểm hash; artifacts phiên C hoặc phiên B/C lịch sử.
     * Output: đầy đủ trạng thái, errors, paired cost/time và review còn thiếu.
     * =====================================================================
     */
    public function build(array $manifest, array $sources, array $artifacts, array $key, array $readers, string $bundleHash): array
    {
        $metrics = new ArticleQualityHumanReview;
        $cases = [];
        $available = [];
        $groups = [];
        $studyArms = array_keys(reset($artifacts) ?: []);
        sort($studyArms);
        if (! in_array($studyArms, [['C'], ['B', 'C']], true)) {
            throw new InvalidArgumentException('Báo cáo chỉ nhận phiên C hoặc artifacts B/C lịch sử.');
        }
        $paired = count($studyArms) === 2;
        $runs = array_fill_keys($studyArms, []);
        foreach ($manifest['cases'] as $case) {
            $id = $case['case_id'];
            $pair = $artifacts[$id] ?? [];
            $source = $sources[$id];
            $expectedHash = ArticleInputHasher::hash(array_replace($source, ['inline_image_refs' => [], 'snapshot_run_id' => '']));
            if (count($pair) !== count($studyArms) || array_diff(array_keys($pair), $studyArms) !== []) {
                throw new InvalidArgumentException('Các ca trong phiên phải có cùng bộ artifacts.');
            }
            foreach ($studyArms as $arm) {
                if (! isset($pair[$arm]) || $pair[$arm]['arm'] !== $arm || $pair[$arm]['source_sha256'] !== $expectedHash) {
                    throw new InvalidArgumentException('Artifact thiếu hoặc source/arm khác phiên: '.$id.'-'.$arm);
                }
                $result = $pair[$arm];
                if (! in_array($result['status'], ['ready', 'failed'], true)) {
                    throw new InvalidArgumentException('Trạng thái kỹ thuật không hợp lệ.');
                }
                $available[$id][$arm] = ($result['candidate_for_review'] ?? null) !== null;
                $runs[$arm][] = $result;
            }
            if (count($key[$id] ?? []) !== count($studyArms) || array_diff(array_values($key[$id]), $studyArms) !== [] || count(array_unique($key[$id])) !== count($studyArms)) {
                throw new InvalidArgumentException('Blind key khác bộ artifacts: '.$id);
            }
            if ($paired) {
                $this->verifyPair($pair['B'], $pair['C'], $id);
            }
            $groups[$case['source_language']] = ($groups[$case['source_language']] ?? 0) + 1;
            $b = $pair['B'] ?? null;
            $c = $pair['C'];
            $cases[] = ['case_id' => $id, 'group' => $case['group'], 'source_language' => $case['source_language'], 'source_sha256' => $case['source_sha256'],
                'matched_inputs' => $paired ? true : null, 'source_images' => count($source['source_images'] ?? []), 'source_tables' => count(array_filter($source['blocks'], fn (array $block): bool => $block['type'] === 'table')),
                'B' => $b ? array_intersect_key($b, array_flip(['status', 'error_code', 'diagnostics', 'latency_ms', 'total_tokens', 'model', 'provider'])) : null,
                'C' => array_intersect_key($c, array_flip(['status', 'error_code', 'diagnostics', 'latency_ms', 'total_tokens', 'model', 'provider'])),
                'C_minus_B_latency_ms' => $b ? $c['latency_ms'] - $b['latency_ms'] : null,
                'C_minus_B_tokens' => ! $b || $c['total_tokens'] === null || $b['total_tokens'] === null ? null : $c['total_tokens'] - $b['total_tokens'],
                'C_over_B_tokens' => ! $b || $c['total_tokens'] === null || $b['total_tokens'] === null || $b['total_tokens'] === 0 ? null : round($c['total_tokens'] / $b['total_tokens'], 3)];
        }
        $arms = [];
        foreach ($runs as $arm => $results) {
            $errors = [];
            $callTokens = [];
            foreach ($results as $result) {
                if ($result['status'] === 'failed') {
                    $code = $result['error_code'] ?? 'unclassified';
                    $errors[$code] = ($errors[$code] ?? 0) + 1;
                }
                foreach ($result['calls'] as $call) {
                    $callTokens[] = $call['diagnostics']['usage']['total_tokens'] ?? null;
                }
            }
            $knownTokens = array_values(array_filter(array_column($results, 'total_tokens'), fn ($value): bool => $value !== null));
            $knownCallTokens = array_values(array_filter($callTokens, fn ($value): bool => $value !== null));
            $arms[$arm] = ['runs' => count($results), 'ready' => count(array_filter($results, fn (array $result): bool => $result['status'] === 'ready')),
                'failed' => count($errors) ? array_sum($errors) : 0, 'errors' => $errors,
                'calls' => array_sum(array_map(fn (array $result): int => count($result['calls']), $results)),
                'tokens' => $metrics->distribution($knownTokens), 'known_tokens_sum' => array_sum($knownTokens), 'unknown_usage_runs' => count($results) - count($knownTokens),
                'known_call_tokens_sum' => array_sum($knownCallTokens), 'known_usage_calls' => count($knownCallTokens), 'unknown_usage_calls' => count($callTokens) - count($knownCallTokens),
                'total_tokens' => count($knownTokens) === count($results) ? array_sum($knownTokens) : null,
                'latency_ms' => $metrics->distribution(array_column($results, 'latency_ms')), 'cost' => null, 'factual_accuracy' => 'pending_human'];
        }
        $human = $metrics->summarize($readers, $key, $available, $bundleHash);

        return ['version' => 'article.quality.study.v2', 'generated_at' => now()->toIso8601String(), 'cases' => count($cases),
            'unique_sources' => count(array_unique(array_column($manifest['cases'], 'source_sha256'))), 'source_languages' => $groups,
            'comparison' => $paired ? 'historical_B_vs_C' : 'three_step_C_only', 'matched_pairs' => $paired ? count($cases) : 0, 'baseline_A' => 'unavailable',
            'technical_gate_is_human_accuracy' => false, 'arms' => $arms, 'paired_cases' => $cases, 'human_review' => $human,
            'image_scope' => 'source_metadata_and_captions_only_not_pixels_or_approved_asset_regenerate',
            'coverage_gaps' => $manifest['coverage_gaps'] ?? [], 'rollout' => 'pending_human_reviews_and_owner_criteria'];
    }

    /**
     * =====================================================================
     * Input: B/C thật. Output: exception nếu model/profile/brief/options khác.
     * comparison hash loại đúng lựa chọn pipeline, giữ toàn bộ input còn lại.
     * =====================================================================
     */
    private function verifyPair(array $b, array $c, string $id): void
    {
        foreach (['source_sha256', 'comparison_input_sha256', 'writing_profile_sha256', 'brief_sha256', 'provider', 'model'] as $field) {
            if (! isset($b[$field], $c[$field]) || $b[$field] !== $c[$field]) {
                throw new InvalidArgumentException('B/C không cùng '.$field.': '.$id);
            }
        }
    }

    /**
     * =====================================================================
     * Input: report đã validate. Output: báo cáo dễ đọc, không chấm thay người.
     * =====================================================================
     */
    public function markdown(array $report): string
    {
        $paired = isset($report['arms']['B']);
        $lines = ['# Báo cáo chất lượng bài · corpus v2', '',
            '**'.$report['cases'].' ca / '.$report['unique_sources'].' nguồn khác nhau.** '.($paired ? $report['matched_pairs'].' cặp B/C cùng nguồn, model, brief và văn phong; đây là dữ liệu lịch sử, B đã được gỡ khỏi thực thi.' : 'Phiên chỉ có C: Analyze → Write → Edit.').' Baseline A chưa phục hồi.', '',
            'Gate kỹ thuật chỉ ghi kết quả pipeline. Độ chính xác, tiếng Việt tự nhiên và công chỉnh sửa do hai người đọc chấm riêng; ô trống chưa phải điểm 0.', '',
            '| Nhánh | Ready / tổng | Failed | Lượt gọi | Token tổng | Token trung vị | Thời gian trung vị |',
            '| --- | --- | --- | --- | --- | --- | --- |'];
        foreach ($report['arms'] as $arm => $data) {
            $lines[] = '| '.$arm.' | '.$data['ready'].' / '.$data['runs'].' | '.$data['failed'].' | '.$data['calls'].' | '.$this->number($data['total_tokens']).' | '.$this->number($data['tokens']['median']).' | '.$this->number($data['latency_ms']['median'] / 1000).' giây |';
        }
        $lines[] = '';
        $lines[] = 'Chi phí tiền chưa có bảng giá xác nhận nên để trống. Token/thời gian bao gồm run bị chặn; dữ liệu usage thiếu được báo riêng, không thay bằng 0.';
        $lines[] = '';
        foreach ($report['arms'] as $arm => $data) {
            $lines[] = $arm.': nhà cung cấp đã báo '.$this->number($data['known_call_tokens_sum']).' token từ '.$data['known_usage_calls'].' / '.$data['calls'].' call; '.$data['unknown_usage_calls'].' call chưa có usage. Trung vị token tính trên '.$data['tokens']['n'].' / '.$data['runs'].' run có tổng usage đầy đủ.';
            $lines[] = '';
        }
        $lines[] = $paired ? '| Ca | Nhóm | B | C | Token B / C | Thời gian B / C (giây) |' : '| Ca | Nhóm | C | Token | Thời gian (giây) |';
        $lines[] = $paired ? '| --- | --- | --- | --- | --- | --- |' : '| --- | --- | --- | --- | --- |';
        foreach ($report['paired_cases'] as $case) {
            $lines[] = '| '.$case['case_id'].' | '.str_replace('|', '/', $case['group']).' | '.($paired ? $case['B']['status'].($case['B']['error_code'] ? ' · '.$case['B']['error_code'] : '').' | ' : '').$case['C']['status'].($case['C']['error_code'] ? ' · '.$case['C']['error_code'] : '').' | '.($paired ? $this->number($case['B']['total_tokens']).' / ' : '').$this->number($case['C']['total_tokens']).' | '.($paired ? $this->number($case['B']['latency_ms'] / 1000).' / ' : '').$this->number($case['C']['latency_ms'] / 1000).' |';
        }
        $lines[] = '';
        $lines[] = '**Chấm người: '.$report['human_review']['status'].'.**';
        $lines[] = '';
        foreach ($report['human_review']['progress'] as $reader => $progress) {
            $lines[] = '- '.$reader.': '.$progress['completed'].' / '.$progress['available'].' bài đã hoàn thành.';
        }
        $lines[] = '';
        if ($report['human_review']['status'] === 'pending_human') {
            $lines[] = 'Chưa kết luận '.($paired ? 'nhánh nào viết hay hơn, ít lỗi factual hơn hoặc ít phút sửa hơn.' : 'chất lượng diễn đạt, độ chính xác hoặc công sửa của C.').' Hai form reviewer-1.html và reviewer-2.html ở thư mục bộ chấm đi kèm; xem hướng dẫn ở README của bộ chấm.';
        } else {
            $lines[] = 'Phân bố từng tiêu chí, lỗi critical/major, coverage, phút sửa, preference và bất đồng nằm riêng trong report.json. Bất đồng cần đối chiếu dẫn chứng; không lấy điểm diễn đạt bù cho lỗi factual.';
        }
        $lines[] = '';
        $lines[] = 'Ảnh chỉ được kiểm metadata/chú thích từ nguồn; chưa có đánh giá pixel hoặc thử regenerate với MediaAsset được duyệt. Không tự tải hay gán ảnh nguồn vào bài. Các trích đoạn web mới không đại diện toàn bài dài.';
        $lines[] = '';
        $lines[] = 'Rollout: '.$report['rollout'].'. Thử nghiệm không Apply/Publish, không đổi Settings/default.';

        return implode("\n", $lines)."\n";
    }

    /**
     * =====================================================================
     * Input: số đo thật hoặc null. Output: số dễ đọc, null = chưa có dữ liệu.
     * =====================================================================
     */
    private function number(int|float|null $value): string
    {
        return $value === null ? '—' : number_format($value, $value == (int) $value ? 0 : 1, ',', '.');
    }
}
