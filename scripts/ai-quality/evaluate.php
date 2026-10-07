<?php

use App\Models\AiImport;
use App\Services\Ai\Content\AiContentSanitizer;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Evaluation\ArticleQualityEvaluation;
use App\Services\Ai\Registries\ProviderRegistry;
use Illuminate\Contracts\Console\Kernel;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preflight/chạy C hoặc xuất lại artifacts lịch sử, không chạy B.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: jsonFile(), frozenJsonHash(), writeJson(), escapeHtml();
 * vòng lặp pilot/export.
 * INPUT/OUTPUT: manifest/prototype/input-snapshot/case/arms/max-calls/--run -> files.
 * SIDE EFFECT: mặc định chỉ đọc/hash; --run mới gọi model, không ghi domain DB.
 * Không baseline A giả, không Apply/Publish, không score tự động hoặc sửa Settings.
 * =====================================================================
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Script CLI: hash không khớp phải trả exit 1, không bị handler Laravel coi là exit 0.
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Evaluation failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$options = getopt('', ['manifest:', 'prototype:', 'input-snapshot:', 'case:', 'arms:', 'max-calls:', 'request-timeout:', 'temperature:', 'output:', 'run', 'current-prompts', 'export-only']);
$exportOnly = isset($options['export-only']);
$temperature = isset($options['temperature']) ? filter_var($options['temperature'], FILTER_VALIDATE_FLOAT) : null;
if ($temperature === false || ($temperature !== null && (! is_finite($temperature) || $temperature < 0 || $temperature > 2)) || ($exportOnly && $temperature !== null)) {
    throw new RuntimeException('--temperature chỉ nhận số hữu hạn 0–2 cho phiên C mới; không sửa phiên lịch sử.');
}
$requestTimeout = isset($options['request-timeout']) ? filter_var($options['request-timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 600]]) : null;
if ($requestTimeout === false || ($exportOnly && $requestTimeout !== null)) {
    throw new RuntimeException('--request-timeout chỉ nhận 5–600 giây cho phiên C mới, không sửa phiên export lịch sử.');
}
if ($exportOnly && isset($options['run'])) {
    throw new RuntimeException('Chọn --run hoặc --export-only; không gộp hai chế độ.');
}
if (app()->environment('production')) {
    throw new RuntimeException('Harness này chỉ phục vụ môi trường nghiệm thu local/test.');
}
$manifestPath = realpath($options['manifest'] ?? base_path('docs/qa/task2-quality/corpus-v1/manifest.json'));
if (! $manifestPath) {
    throw new RuntimeException('Không tìm thấy manifest đã freeze.');
}
$root = dirname($manifestPath).DIRECTORY_SEPARATOR;
$manifest = jsonFile($manifestPath);
$ids = isset($options['case']) ? explode(',', $options['case']) : array_column($manifest['cases'], 'case_id');
$exportKey = [];
if ($exportOnly) {
    if (empty($options['output'])) {
        throw new RuntimeException('Export cần --output trỏ tới phiên đã lưu.');
    }
    $exportKey = jsonFile($options['output'].'/blind-key.json');
}
$arms = isset($options['arms']) ? explode(',', $options['arms']) : ($exportOnly ? array_values($exportKey[$ids[0]] ?? []) : ['C']);
$cases = array_values(array_filter($manifest['cases'], fn (array $case): bool => in_array($case['case_id'], $ids, true)));
if (count($cases) !== count(array_unique($ids))) {
    throw new RuntimeException('Case ID trùng hoặc không tồn tại.');
}
$runner = new ArticleQualityEvaluation;
if ($exportOnly && ($arms === [] || array_diff($arms, ['B', 'C']) !== [] || count(array_unique($arms)) !== count($arms))) {
    throw new RuntimeException('Export chỉ đọc nhãn B/C đã có trong phiên lịch sử.');
}
$planned = $exportOnly ? 0 : $runner->plannedCalls(count($cases), $arms);
$sources = [];
foreach ($cases as $case) {
    if (! preg_match('/^Q\d{2}$/D', $case['case_id'])) {
        throw new RuntimeException('Case ID không hợp lệ.');
    }
    $path = realpath($root.$case['source_snapshot_path']);
    if (! $path || ! str_starts_with(strtolower($path), strtolower($root)) || frozenJsonHash($path) !== $case['source_sha256']) {
        throw new RuntimeException('Source hash/path không hợp lệ: '.$case['case_id']);
    }
    $sources[$case['case_id']] = jsonFile($path);
    if (count($sources[$case['case_id']]['blocks'] ?? []) < 1) {
        throw new RuntimeException('Source không có blocks.');
    }
}
echo json_encode(['cases' => $ids, 'arms' => $arms, 'planned_calls' => $exportOnly ? 0 : $planned, 'temperature_override' => $temperature, 'baseline_A' => 'unavailable', 'mode' => $exportOnly ? 'export' : (isset($options['run']) ? 'execute' : 'preflight')], JSON_UNESCAPED_SLASHES).PHP_EOL;
if (! isset($options['run']) && ! $exportOnly) {
    exit;
}
$maxCalls = filter_var($options['max-calls'] ?? null, FILTER_VALIDATE_INT);
if (! $exportOnly && ($maxCalls === false || $maxCalls < $planned)) {
    throw new RuntimeException('Cần --max-calls đủ trước khi gọi model; không tự mở rộng budget.');
}
if (! $exportOnly && empty($options['prototype'])) {
    throw new RuntimeException('Cần UUID run local đã được chọn model/profile để giữ cùng cấu hình.');
}
$input = $exportOnly ? [] : (array) AiImport::query()->findOrFail($options['prototype'])->input_json;
if (! $exportOnly && isset($options['input-snapshot'])) {
    // Đọc lại profile/pipeline đã freeze cho máy local khác, không nhận connection/key từ file.
    $savedInput = jsonFile($options['input-snapshot']);
    $input = array_replace($input, array_intersect_key($savedInput, array_flip(['writing_profile_snapshot', 'pipeline_snapshot'])));
}
if (isset($options['current-prompts'])) {
    $input['pipeline_snapshot']['prompts'] = config('ai-content.prompts');
    $input['pipeline_snapshot']['prompt_version'] = config('ai-content.prompt_version');
}
if (! $exportOnly) {
    $input['pipeline_snapshot']['pipeline'] = 'three_step';
}
if (! $exportOnly && empty($input['ai_connection'])) {
    throw new RuntimeException('Prototype không có connection snapshot thật.');
}
if (! $exportOnly && $requestTimeout !== null) {
    $input['ai_connection']['timeout'] = $requestTimeout;
}
if (! $exportOnly && $temperature !== null) {
    $input['ai_connection']['temperature'] = $temperature;
}
$output = $options['output'] ?? base_path('docs/qa/task2-quality/pilot-'.now()->format('Ymd-His'));
if (! $exportOnly && file_exists($output)) {
    throw new RuntimeException('Output đã tồn tại; không ghi đè thử nghiệm trước.');
}
if (! $exportOnly && ! mkdir($output.'/review', 0755, true)) {
    throw new RuntimeException('Không tạo được output.');
}
$summary = $exportOnly ? jsonFile($output.'/summary.json') : ['captured_at' => now()->toIso8601String(), 'manifest_sha256' => frozenJsonHash($manifestPath), 'baseline_A' => 'unavailable', 'human_review' => 'pending', 'prompt_version' => data_get($input, 'pipeline_snapshot.prompt_version'), 'actual_calls' => 0, 'results' => []];
if ($summary['manifest_sha256'] !== frozenJsonHash($manifestPath)) {
    throw new RuntimeException('Manifest khác phiên đánh giá; không gán lại nguồn cho output cũ.');
}
if (! $exportOnly) {
    $summary['request_timeout'] = $input['ai_connection']['timeout'] ?? null;
    $summary['temperature'] = $input['ai_connection']['temperature'] ?? null;
    writeJson($output.'/experiment-input.json', array_intersect_key($input, array_flip(['writing_profile_snapshot', 'pipeline_snapshot'])) + ['evaluation_options' => ['request_timeout' => $summary['request_timeout'], 'temperature' => $summary['temperature']]]);
}
// Resolve một lần: toàn phiên C dùng cùng options/connection trong memory.
// Clone từng nhánh để state diagnostics/output fields không truyền qua bài khác.
$studyProvider = $exportOnly ? null : app(ProviderRegistry::class)->resolveForRun($input['ai_connection']);
$review = '';
$key = $exportOnly ? $exportKey : [];
$scoreRows = [];
$scoreFields = ['case_id', 'label', 'facts_errors_and_severity', 'naturalness_1_5', 'usefulness_1_5', 'structure_1_5', 'repetition_1_5', 'brief_style_1_5', 'important_facts_preserved', 'important_facts_expected', 'editing_minutes', 'fact_edits', 'expression_edits', 'paragraphs_added', 'paragraphs_removed', 'coverage_notes', 'paired_preference', 'reviewer'];
foreach ($cases as $case) {
    $id = $case['case_id'];
    $source = $sources[$id];
    $caseInput = $input;
    unset($caseInput['parent_draft_snapshot'], $caseInput['refresh_source']);
    $caseInput['language'] = $case['output_language'];
    $caseInput['writing_brief'] = $case['writing_brief'];
    $caseInput['instructions'] = 'Viết tiếng Việt tự nhiên theo nguồn. Giữ nguyên code, bảng, số liệu, phiên bản, điều kiện và link. Không bịa API, benchmark, trải nghiệm hay thông tin mới. Tránh dịch từng câu và các đoạn lặp ý.';
    $results = [];
    foreach ($arms as $arm) {
        if ($exportOnly) {
            $result = jsonFile($output.'/'.$id.'-'.$arm.'.json');
        } else {
            $provider = clone $studyProvider;
            $result = $runner->run($source, $caseInput, $provider, $arm, $maxCalls - $summary['actual_calls']);
            $summary['actual_calls'] += count($result['calls']);
            writeJson($output.'/'.$id.'-'.$arm.'.json', $result);
        }
        $expectedSource = array_replace($source, ['inline_image_refs' => [], 'snapshot_run_id' => '']);
        if ($result['arm'] !== $arm || $result['source_sha256'] !== ArticleInputHasher::hash($expectedSource)) {
            throw new RuntimeException('Hash source/arm không khớp artifact: '.$id);
        }
        $results[$arm] = $result;
        if (! $exportOnly) {
            $summary['results'][] = array_intersect_key($result, array_flip(['arm', 'status', 'error_code', 'latency_ms', 'total_tokens', 'input_sha256', 'comparison_input_sha256', 'source_sha256', 'writing_profile_sha256', 'brief_sha256', 'provider', 'model', 'cost', 'image_evaluation'])) + ['case_id' => $id, 'calls' => count($result['calls'])];
            writeJson($output.'/summary.json', $summary);
        }
        echo $id.' '.$arm.' '.$result['status'].' '.$result['error_code'].' calls='.count($result['calls']).' tokens='.($result['total_tokens'] ?? 'unknown').PHP_EOL;
    }
    $order = $exportOnly ? array_values($key[$id]) : array_keys($results);
    if (count($order) !== count($arms) || array_diff($order, $arms) !== [] || count(array_unique($order)) !== count($order)) {
        throw new RuntimeException('Các nhánh export phải khớp blind-key gốc của '.$id.'; không thay thứ tự hoặc đổi bộ output đang chấm.');
    }
    if (! $exportOnly) {
        shuffle($order);
    }
    $review .= '<section><h2>'.escapeHtml($id.' · '.$case['group']).'</h2><p>Brief: '.escapeHtml(json_encode($case['writing_brief'], JSON_UNESCAPED_UNICODE)).'</p>';
    $review .= '<p>Nguồn: '.escapeHtml($case['attribution'] ?? '').' · '.escapeHtml($case['license'] ?? '').'. Phần chọn: '.escapeHtml($case['selection'] ?? '').'; HTML được chuyển đổi để kiểm pipeline.</p>';
    if ($case['source_origin_url']) {
        $review .= '<p><a href="'.escapeHtml($case['source_origin_url']).'">Nguồn gốc tại commit đã đóng băng</a> · <a href="'.escapeHtml($case['license_url'] ?? '').'">License/attribution</a></p>';
    }
    $review .= '<details><summary>Đọc nguồn đã đóng băng</summary>'.(new AiContentSanitizer)->sanitize($source['content_html']).'</details><div class="pair'.(count($order) === 1 ? ' single' : '').'">';
    foreach ($order as $index => $arm) {
        $label = ['X', 'Y'][$index];
        $key[$id][$label] = $arm;
        $candidate = $results[$arm]['candidate_for_review'];
        $review .= '<article><h3>'.escapeHtml($label).'</h3>';
        $review .= $candidate ? '<h4>'.escapeHtml($candidate['title']).'</h4>'.(new AiContentSanitizer)->sanitize($candidate['content_html']) : '<p>Không có bài để chấm. Lỗi kỹ thuật được ghi riêng; để trống điểm.</p>';
        $review .= '</article>';
        $scoreRows[] = array_merge([$id, $label], array_fill(0, count($scoreFields) - 2, ''));
    }
    $review .= '</div></section>';
}
if (! $exportOnly) {
    writeJson($output.'/blind-key.json', $key);
}
$header = '<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src &#39;none&#39;"><title>Task 2 · Gói chấm mù chất lượng</title><style>body{font:16px/1.65 system-ui;background:#f5f5fb;color:#27253e;max-width:1440px;margin:24px auto;padding:20px}h1,h2{line-height:1.3}.pair{display:grid;grid-template-columns:1fr 1fr;gap:20px}.pair.single{grid-template-columns:1fr}article,details{padding:24px;background:white;border:1px solid #ddd;border-radius:12px;margin:16px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere}table{border-collapse:collapse;display:block;overflow:auto}td,th{border:1px solid #aaa;padding:8px}section{margin:40px 0}a{color:#6552ca}@media(max-width:800px){.pair{grid-template-columns:1fr}}</style><body><h1>Task 2 · Chấm chất lượng bài viết</h1><p>Đọc nguồn và brief rồi chấm từng ứng viên theo nhãn. Hai người đọc dùng hai CSV riêng. Ghi lỗi facts/điều kiện trước khi chấm văn phong; không dùng AI thay người đánh giá. Chưa có điểm hoặc kết luận chất lượng. Không mở blind-key/summary trong lúc chấm.</p>';
file_put_contents($output.'/review/index.html', $header.$review.'</body></html>');
foreach (['reviewer-1', 'reviewer-2'] as $reader) {
    // Export lại giữ nguyên thứ tự X/Y và mọi CSV đã có, kể cả người đọc đã chấm.
    if ($exportOnly && is_file($output.'/review/'.$reader.'.csv')) {
        continue;
    }
    $stream = fopen($output.'/review/'.$reader.'.csv', 'x');
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, $scoreFields);
    foreach ($scoreRows as $row) {
        fputcsv($stream, $row);
    }
    fclose($stream);
}
echo 'Review bundle: '.$output.'/review/index.html; human review pending.'.PHP_EOL;
/**
 * =====================================================================
 * Input: đường dẫn JSON đã whitelist/hash.
 * Output: array, không chạy nội dung file.
 * =====================================================================
 */
function jsonFile(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}
/**
 * =====================================================================
 * Input: JSON đã freeze với LF. Output: SHA-256 tương thích Git autocrlf trên Windows.
 * Chỉ chuẩn hóa newline vật lý ngoài JSON; giữ escaped CRLF của source/code nguyên vẹn.
 * Mọi sửa nội dung khác vẫn fail hash; không ghi lại corpus hoặc artifact cũ.
 * =====================================================================
 */
function frozenJsonHash(string $path): string
{
    return hash('sha256', str_replace("\r\n", "\n", file_get_contents($path)));
}
/**
 * =====================================================================
 * Input: output riêng của phiên đánh giá.
 * Output: JSON canonical, không secrets upstream.
 * =====================================================================
 */
function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
/**
 * =====================================================================
 * Input: nhãn/nguồn untrusted.
 * Output: text HTML escaped, không thực thi model output.
 * =====================================================================
 */
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
