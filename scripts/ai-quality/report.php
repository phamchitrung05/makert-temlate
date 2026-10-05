<?php

use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Evaluation\ArticleQualityHumanReview;
use App\Services\Ai\Content\Evaluation\ArticleQualityReviewBundle;
use App\Services\Ai\Content\Evaluation\ArticleQualityStudyReport;
use Illuminate\Contracts\Console\Kernel;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xuất hai form chấm và báo cáo từ một study hoàn chỉnh.
 * CÁC HÀM/METHOD: readStudyJson(), writeNewJson(), blankReviewRows(); orchestration.
 * INPUT/OUTPUT: --study/manifest/output/review-output/reviewer-1/reviewer-2 -> files.
 * SIDE EFFECT: chỉ đọc artifacts/CSV và ghi output mới; không AI/DB/Apply.
 * Export lại giữ nguyên blind key, source, outputs và mọi CSV người đọc.
 * =====================================================================
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Report failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$options = getopt('', ['study:', 'manifest:', 'output:', 'review-output:', 'reviewer-1:', 'reviewer-2:']);
$study = realpath($options['study'] ?? base_path('docs/qa/task2-quality/study-2026-10-05-corpus-v2'));
$manifestPath = realpath($options['manifest'] ?? base_path('docs/qa/task2-quality/corpus-v2/manifest.json'));
if (! $study || ! $manifestPath) {
    throw new RuntimeException('Không tìm thấy study hoặc corpus.');
}
$manifestHash = hash('sha256', str_replace("\r\n", "\n", file_get_contents($manifestPath)));
$manifest = readStudyJson($manifestPath);
$summary = readStudyJson($study.'/summary.json');
$key = readStudyJson($study.'/blind-key.json');
$experiment = readStudyJson($study.'/experiment-input.json');
$experimentHash = hash('sha256', str_replace("\r\n", "\n", file_get_contents($study.'/experiment-input.json')));
$profileHash = ArticleInputHasher::hash((array) ($experiment['writing_profile_snapshot'] ?? []));
if ($summary['manifest_sha256'] !== $manifestHash) {
    throw new RuntimeException('Manifest không thuộc study này.');
}
$root = dirname($manifestPath).DIRECTORY_SEPARATOR;
$sources = [];
$artifacts = [];
$hashes = [];
$arms = array_values(array_unique(array_column($summary['results'], 'arm')));
sort($arms);
if (! in_array($arms, [['C'], ['B', 'C']], true)) {
    throw new RuntimeException('Chỉ nhận phiên C hoặc artifacts B/C lịch sử.');
}
foreach ($manifest['cases'] as $case) {
    $id = $case['case_id'];
    $path = realpath($root.$case['source_snapshot_path']);
    if (! preg_match('/^Q\d{2}$/D', $id) || ! $path || ! str_starts_with(strtolower($path), strtolower($root)) || hash('sha256', str_replace("\r\n", "\n", file_get_contents($path))) !== $case['source_sha256']) {
        throw new RuntimeException('Source hash/path không hợp lệ.');
    }
    $sources[$id] = readStudyJson($path);
    foreach ($arms as $arm) {
        $artifactPath = $study.'/'.$id.'-'.$arm.'.json';
        $artifacts[$id][$arm] = readStudyJson($artifactPath);
        if (($artifacts[$id][$arm]['writing_profile_sha256'] ?? '') !== $profileHash || ($artifacts[$id][$arm]['brief_sha256'] ?? '') !== ArticleInputHasher::hash($case['writing_brief'])) {
            throw new RuntimeException('Profile/brief khác bản đã freeze: '.$id.'-'.$arm);
        }
        $hashes[$id.'-'.$arm] = hash('sha256', str_replace("\r\n", "\n", file_get_contents($artifactPath)));
    }
}
$bundleHash = hash('sha256', json_encode([$manifestHash, $experimentHash, $hashes, $key, (new ArticleQualityHumanReview)->fields()], JSON_THROW_ON_ERROR));
$review = $options['review-output'] ?? $study.'/review-v2';
if (is_dir($review) && (readStudyJson($review.'/bundle-manifest.json')['bundle_sha256'] ?? '') !== $bundleHash) {
    throw new RuntimeException('Bộ chấm đã tồn tại với nguồn/output/nhãn khác. Không thay đề người đọc đang chấm.');
}
if (isset($options['reviewer-1']) !== isset($options['reviewer-2'])) {
    throw new RuntimeException('Nhập cả hai CSV độc lập hoặc dùng hai mẫu trống.');
}
$human = new ArticleQualityHumanReview;
$readers = [];
foreach (['reviewer-1', 'reviewer-2'] as $reader) {
    $readers[$reader] = isset($options[$reader]) ? $human->readCsv($options[$reader]) : blankReviewRows($human->fields(), $key, $bundleHash);
}
$reportService = new ArticleQualityStudyReport;
$report = $reportService->build($manifest, $sources, $artifacts, $key, $readers, $bundleHash);
$report['manifest_sha256'] = $manifestHash;
$report['bundle_sha256'] = $bundleHash;
$report['artifact_sha256'] = $hashes;
if (array_sum(array_column($report['arms'], 'calls')) !== $summary['actual_calls']) {
    throw new RuntimeException('Số call summary không khớp artifacts.');
}
$output = $options['output'] ?? $study.'/report-v2';
if (file_exists($output)) {
    throw new RuntimeException('Dùng thư mục report mới để giữ báo cáo/người chấm cũ.');
}
if (! is_dir($review)) {
    if (! mkdir($review, 0755, true)) {
        throw new RuntimeException('Không tạo được bộ chấm.');
    }
    $renderer = new ArticleQualityReviewBundle;
    $styleInstructions = (string) data_get($experiment, 'writing_profile_snapshot.style_instructions', '');
    foreach ((array) data_get($experiment, 'writing_profile_snapshot.rules', []) as $rule) {
        if (is_string($rule) && trim($rule) !== '') {
            $styleInstructions .= "\n".$rule;
        }
    }
    foreach (['reviewer-1', 'reviewer-2'] as $reader) {
        file_put_contents($review.'/'.$reader.'.html', $renderer->render($manifest, $sources, $artifacts, $key, $reader, $bundleHash, $styleInstructions));
        $stream = fopen($review.'/'.$reader.'.csv', 'x');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $human->fields(), escape: '');
        foreach (blankReviewRows($human->fields(), $key, $bundleHash) as $row) {
            fputcsv($stream, array_values($row), escape: '');
        }
        fclose($stream);
    }
    copy(__DIR__.'/review.js', $review.'/review.js');
    copy(__DIR__.'/review.css', $review.'/review.css');
    writeNewJson($review.'/bundle-manifest.json', ['bundle_sha256' => $bundleHash, 'manifest_sha256' => $manifestHash, 'experiment_sha256' => $experimentHash, 'cases' => count($manifest['cases']), 'human_review' => 'pending', 'scores_filled_by_agent' => false]);
    $preferenceInstructions = count($arms) > 1 ? ' Chọn bài ưu tiên sau khi đọc cả hai.' : ' Phiên C không có lựa chọn giữa hai bài; để trống paired_preference trong CSV.';
    file_put_contents($review.'/README.md', "# Hai bộ chấm độc lập\n\nMỗi người mở một file reviewer-N.html, nhập tên/mã riêng rồi đọc nguồn và brief. Không xem summary, report hoặc blind-key trước khi chốt điểm. Có thể mở trực tiếp file; giữ review.js/review.css cùng thư mục.\n\n1. Lập dữ kiện quan trọng từ source blocks, ghi mã đoạn và điều kiện. Không lấy ledger Analyze làm đáp án.\n2. Đọc từng ứng viên theo nhãn, ghi lỗi critical/major/minor, coverage và checklist code/link/table/quote/ảnh. URL/alt/chú thích không chứng minh đã nhìn pixel ảnh.\n3. Chấm 1–5 theo rubric ở docs/AI_ARTICLE_QUALITY_EVALUATION.md. 1 kém, 3 dùng được sau sửa, 5 tốt; 2/4 là mức giữa.\n4. Ghi phút sửa thực tế và số sửa; không suy công sửa từ tokens/latency/diff.".$preferenceInstructions."\n5. Chỉ đánh dấu Hoàn thành khi đủ dữ kiện và điểm. Tải CSV để giữ bản chắc chắn; bản nháp lưu riêng theo bộ chấm/trình duyệt khi hỗ trợ.\n\nHai người chấm trước khi trao đổi. CSV trống là chưa chấm, không phải 0; không chỉnh CSV của người kia. Sau đó đưa hai CSV vào scripts/ai-quality/report.php --reviewer-1=PATH --reviewer-2=PATH --output=FRESH_REPORT_DIR. Tool không gọi AI hoặc đổi cấu hình. Bất đồng fact cần đối chiếu evidence và owner quyết định nghiệm thu chất lượng.\n");
}
if (! mkdir($output, 0755, true)) {
    throw new RuntimeException('Không tạo được thư mục report mới.');
}
writeNewJson($output.'/report.json', $report);
file_put_contents($output.'/report.md', $reportService->markdown($report));
echo json_encode(['cases' => $report['cases'], 'matched_pairs' => $report['matched_pairs'], 'calls' => array_sum(array_column($report['arms'], 'calls')), 'B_ready' => $report['arms']['B']['ready'] ?? null, 'C_ready' => $report['arms']['C']['ready'], 'human_review' => $report['human_review']['status'], 'output' => $output, 'AI_calls_added' => 0], JSON_UNESCAPED_SLASHES).PHP_EOL;

/**
 * =====================================================================
 * Input: file JSON local. Output: array, không chạy nội dung file.
 * =====================================================================
 */
function readStudyJson(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException('Study chưa đủ artifact: '.basename($path));
    }

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * =====================================================================
 * Input: path mới/JSON. Output: UTF-8 LF, không overwrite.
 * =====================================================================
 */
function writeNewJson(string $path, array $value): void
{
    $stream = fopen($path, 'x');
    if (! $stream) {
        throw new RuntimeException('File báo cáo đã tồn tại.');
    }
    fwrite($stream, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    fclose($stream);
}

/**
 * =====================================================================
 * Input: hợp đồng fields/key. Output: chỉ case/label; không điền danh tính/điểm.
 * =====================================================================
 */
function blankReviewRows(array $fields, array $key, string $bundleHash): array
{
    $rows = [];
    foreach ($key as $case => $labels) {
        foreach ($labels as $label => $arm) {
            $rows[] = array_replace(array_fill_keys($fields, ''), ['case_id' => $case, 'label' => $label, 'bundle_sha256' => $bundleHash]);
        }
    }

    return $rows;
}
