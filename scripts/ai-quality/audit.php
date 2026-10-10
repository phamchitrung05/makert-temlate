<?php

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Quality\ArticleEvidenceValidator;
use App\Services\Ai\Content\Quality\ArticleQualityGate;
use App\Services\Ai\Content\Quality\ArticleSourceLinkPolicy;
use Illuminate\Contracts\Console\Kernel;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đối chiếu outputs pilot đã lưu với nguồn và gate hiện tại.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: readAuditJson(), auditJsonHash(); vòng lặp source/calls.
 * INPUT/OUTPUT: --pilot/--manifest/--case/--output -> link/ref và kiểm lại offline.
 * SIDE EFFECT: đọc artifacts, chỉ ghi file mới khi yêu cầu; 0 AI/HTTP/DB/Apply call.
 * Không sửa candidate hoặc artifacts cũ; kết quả gate không phải điểm văn phong.
 * =====================================================================
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Audit failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$options = getopt('', ['pilot:', 'manifest:', 'case:', 'output:']);
$outputPath = $options['output'] ?? null;
if ($outputPath !== null && file_exists($outputPath)) {
    throw new RuntimeException('Audit output đã tồn tại; không ghi đè bằng chứng.');
}
$root = base_path('docs/qa/task2-quality');
$pilotPath = $options['pilot'] ?? $root.'/pilot-2026-10-05-five-cases';
$names = explode(',', $options['case'] ?? 'Q02-B,Q02-C,Q07-C,Q18-C');
if (count($names) !== count(array_unique($names))) {
    throw new RuntimeException('Không audit trùng case/arm.');
}
$manifestPath = realpath($options['manifest'] ?? $root.'/corpus-v1/manifest.json');
if (! $manifestPath) {
    throw new RuntimeException('Không tìm thấy manifest nguồn.');
}
$manifestRoot = dirname($manifestPath).DIRECTORY_SEPARATOR;
$manifest = readAuditJson($manifestPath);
$caseIndex = array_column($manifest['cases'], null, 'case_id');
$policy = new ArticleSourceLinkPolicy;
$evidence = new ArticleEvidenceValidator;
$gate = new ArticleQualityGate;
$report = ['mode' => 'saved_outputs_only', 'model_calls' => 0, 'prompt_version_current' => config('ai.content.prompt_version'),
    'captured_at' => now()->toIso8601String(), 'manifest_sha256' => auditJsonHash($manifestPath),
    'human_quality' => 'not_evaluated', 'cases' => []];
foreach ($names as $name) {
    if (! preg_match('/^Q\d{2}-[BC]$/D', $name) || ! isset($caseIndex[substr($name, 0, 3)])) {
        throw new RuntimeException('Case/arm audit không hợp lệ.');
    }
    $caseId = substr($name, 0, 3);
    $sourcePath = realpath($manifestRoot.$caseIndex[$caseId]['source_snapshot_path']);
    if (! $sourcePath || ! str_starts_with(strtolower($sourcePath), strtolower($manifestRoot))) {
        throw new RuntimeException('Source path phải nằm trong corpus.');
    }
    $artifactPath = $pilotPath.'/'.$name.'.json';
    if (auditJsonHash($sourcePath) !== $caseIndex[$caseId]['source_sha256']) {
        throw new RuntimeException('Nguồn không khớp hash frozen: '.$caseId);
    }
    $source = readAuditJson($sourcePath);
    $artifact = readAuditJson($artifactPath);
    if (($artifact['arm'] ?? '') !== substr($name, -1) || ($artifact['source_sha256'] ?? '') !== ArticleInputHasher::hash(array_replace($source, ['inline_image_refs' => [], 'snapshot_run_id' => '']))) {
        throw new RuntimeException('Artifact không cùng source/arm đã freeze: '.$name);
    }
    $links = $policy->manifest($source);
    $analysis = $artifact['arm'] === 'C' ? $artifact['calls'][0]['output'] : [];
    $factIds = array_column($analysis['knowledge']['facts'] ?? [], 'id');
    $entry = ['case' => $name, 'original_status' => $artifact['status'], 'original_error_code' => $artifact['error_code'],
        'original_diagnostics' => $artifact['diagnostics'] ?? null, 'source_json_sha256' => auditJsonHash($sourcePath),
        'artifact_json_sha256' => auditJsonHash($artifactPath), 'link_manifest' => $links, 'stages' => []];
    $referenceError = null;
    foreach ($artifact['calls'] as $call) {
        $stage = ['task' => $call['task']];
        $fields = $call['output']['draft'] ?? $call['output']['final'] ?? $call['output'];
        if (isset($fields['content_html'])) {
            $hrefs = $policy->hrefs($fields['content_html']);
            $stage += ['required_links_missing' => array_values(array_diff($links['required_hrefs'], $hrefs)),
                'navigation_links_omitted' => array_values(array_diff($links['optional_navigation_hrefs'], $hrefs)),
                'links_outside_allowlist' => array_values(array_diff($hrefs, $links['allowed_hrefs']))];
        }
        if (isset($call['output']['used_fact_ids'])) {
            $stage['unknown_fact_ids'] = array_values(array_diff($call['output']['used_fact_ids'], $factIds));
            try {
                $evidence->references($call['output']['used_fact_ids'], $analysis, requireImportant: $call['task'] === 'article.editor');
            } catch (AiImportException $exception) {
                $referenceError = ['status' => 'blocked', 'task' => $call['task'], 'error_code' => $exception->errorCode, 'diagnostics' => $exception->diagnostics];
            }
        }
        $entry['stages'][] = $stage;
    }
    if ($referenceError !== null) {
        $entry['current_gate_recheck'] = $referenceError;
    } elseif ($artifact['candidate_for_review'] !== null) {
        try {
            $checks = $gate->inspect($source, $artifact['candidate_for_review'], $caseIndex[$caseId]['output_language'], $analysis);
            $entry['current_gate_recheck'] = ['status' => 'pass', 'checks' => $checks];
        } catch (AiImportException $exception) {
            $entry['current_gate_recheck'] = ['status' => 'blocked', 'error_code' => $exception->errorCode, 'diagnostics' => $exception->diagnostics];
        }
    } else {
        $entry['current_gate_recheck'] = ['status' => 'no_final_candidate'];
    }
    $report['cases'][] = $entry;
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
if ($outputPath !== null) {
    if (file_put_contents($outputPath, $json) === false) {
        throw new RuntimeException('Không ghi được audit output.');
    }
    echo json_encode(['model_calls' => 0, 'output' => $outputPath, 'rechecks' => array_map(fn (array $case): array => ['case' => $case['case'], 'status' => $case['current_gate_recheck']['status']], $report['cases'])], JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    echo $json;
}

/** Input: artifact JSON. Output: dữ liệu đã lưu, chỉ đọc, không thực thi nội dung. */
function readAuditJson(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** Input: artifact UTF-8. Output: hash LF frozen; không ghi lại file trên Windows. */
function auditJsonHash(string $path): string
{
    return hash('sha256', str_replace("\r\n", "\n", file_get_contents($path)));
}
