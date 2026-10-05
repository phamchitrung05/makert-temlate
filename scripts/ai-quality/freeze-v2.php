<?php

use App\Services\Ai\Content\AiContentSanitizer;
use App\Services\Ai\Content\ArticleSourceExtractor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bổ sung nguồn thật vào corpus v2, giữ nguyên corpus v1.
 * CÁC HÀM/METHOD: saveFrozenJson(), selectedExcerpt(); vòng lặp reuse/fetch.
 * INPUT/OUTPUT: --output, --spec -> snapshot/hash/attribution/checklist nguồn.
 * SIDE EFFECT: HTTP public có timeout; ghi thư mục mới, không AI/DB/ảnh.
 * Checklist là dẫn chứng từ nguồn chờ người duyệt, không phải đáp án đã chấm.
 * =====================================================================
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Freeze failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$options = getopt('', ['output:', 'spec:']);
$spec = json_decode(file_get_contents($options['spec'] ?? __DIR__.'/sources-v2.json'), true, flags: JSON_THROW_ON_ERROR);
$output = $options['output'] ?? base_path('docs/qa/task2-quality/corpus-v2');
if (file_exists($output) || ! mkdir($output, 0755, true)) {
    throw new RuntimeException('Dùng thư mục corpus mới, không ghi đè nguồn đã freeze.');
}
$original = base_path('docs/qa/task2-quality/corpus-v1');
$old = json_decode(file_get_contents($original.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest = ['version' => 2, 'captured_at' => now()->toIso8601String(), 'review_status' => 'pending_human',
    'coverage_gaps' => ['Nguồn web mới là trích đoạn được chọn, không mặc định là toàn bài.', 'Facts quan trọng cần hai người đọc xác nhận độc lập từ nguồn.', 'Ảnh nguồn chỉ là metadata/chú thích: chưa kiểm pixel hoặc regenerate với asset được duyệt.', 'Chưa có benchmark độc lập; bảng giới hạn dịch vụ không đại diện benchmark.'], 'cases' => []];
foreach ($old['cases'] as $case) {
    if (! in_array($case['case_id'], $spec['reuse_cases'], true)) {
        continue;
    }
    $bytes = str_replace("\r\n", "\n", file_get_contents($original.'/'.$case['source_snapshot_path']));
    if (hash('sha256', $bytes) !== $case['source_sha256']) {
        throw new RuntimeException('Source v1 không khớp hash: '.$case['case_id']);
    }
    file_put_contents($output.'/'.$case['source_snapshot_path'], $bytes);
    $case['reused_from'] = 'corpus-v1';
    $manifest['cases'][] = $case;
}
foreach ($spec['web_cases'] as $case) {
    if (! preg_match('/^Q\d{2}$/D', $case['case_id']) || ! str_starts_with($case['url'], 'https://')) {
        throw new RuntimeException('Spec nguồn không hợp lệ.');
    }
    $response = Http::timeout(30)->get($case['url']);
    if (! $response->successful()) {
        throw new RuntimeException('Fetch '.$case['case_id'].' thất bại: '.$response->status());
    }
    $raw = $response->body();
    $extracted = (new ArticleSourceExtractor)->extract($raw, $case['url']);
    [$excerpt, $selection] = selectedExcerpt($extracted, $case['selection']);
    $metadata = new DOMDocument;
    @$metadata->loadHTML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $metadataQuery = new DOMXPath($metadata);
    $published = $metadataQuery->query('//meta[@property="article:published_time" or @name="pubdate"]/@content')->item(0)?->nodeValue;
    $snapshot = (new ArticleSourceExtractor)->snapshot($excerpt, ['source_url' => $case['url'], 'source_format' => 'html', 'title' => $case['group'], 'published_at' => $published]);
    saveFrozenJson($output.'/'.$case['case_id'].'.json', $snapshot);
    file_put_contents($output.'/'.$case['case_id'].'.html', $excerpt);
    $manifest['cases'][] = ['case_id' => $case['case_id'], 'group' => $case['group'], 'source_language' => $case['language'], 'output_language' => 'vi',
        'source_snapshot_path' => $case['case_id'].'.json', 'source_sha256' => hash_file('sha256', $output.'/'.$case['case_id'].'.json'),
        'raw_sha256' => hash('sha256', $raw), 'excerpt_sha256' => hash('sha256', $excerpt), 'source_origin_url' => $case['url'], 'selection' => $selection,
        'license' => $case['license'] ?? 'Quyền thuộc đơn vị xuất bản; trích đoạn phục vụ QA, không cấp quyền xuất bản lại/ảnh',
        'license_url' => $case['license_url'] ?? $case['url'], 'attribution' => $case['attribution'],
        'writing_brief' => ['audience' => $case['audience'], 'purpose' => 'Viết bài trong phạm vi trích đoạn đóng băng; giữ mốc thời gian và điều kiện nguồn', 'length' => 'Theo lượng thông tin nguồn, tránh kéo dài/lặp ý', 'source_scope' => $case['note']],
        'important_facts' => [], 'human_fact_review' => 'pending', 'notes' => $case['note'], 'captured_at' => now()->toIso8601String(),
        'source_image_count' => count($snapshot['source_images']), 'source_table_count' => count(array_filter($snapshot['blocks'], fn (array $block): bool => $block['type'] === 'table'))];
    echo $case['case_id'].' blocks='.count($snapshot['blocks']).' images='.count($snapshot['source_images']).' chars='.mb_strlen($snapshot['content_html']).PHP_EOL;
}
$manifest['unique_sources'] = count(array_unique(array_column($manifest['cases'], 'source_sha256')));
if (count($manifest['cases']) !== count($spec['reuse_cases']) + count($spec['web_cases']) || $manifest['unique_sources'] !== count($manifest['cases'])) {
    throw new RuntimeException('Corpus phải có đủ nguồn độc lập, không nhân ca trên cùng snapshot.');
}
$checklists = [];
foreach ($manifest['cases'] as $case) {
    $source = json_decode(file_get_contents($output.'/'.$case['source_snapshot_path']), true, flags: JSON_THROW_ON_ERROR);
    $checklists[$case['case_id']] = ['status' => 'pending_human', 'source_sha256' => $case['source_sha256'], 'evidence_blocks' => $source['blocks'], 'source_images' => $source['source_images']];
}
saveFrozenJson($output.'/source-checklists.json', $checklists);
saveFrozenJson($output.'/manifest.json', $manifest);
echo 'Frozen '.count($manifest['cases']).' cases / '.$manifest['unique_sources'].' sources; human facts pending; AI calls=0.'.PHP_EOL;

/**
 * =====================================================================
 * Input: file mới và giá trị. Output: JSON UTF-8 LF; không ghi đè.
 * =====================================================================
 */
function saveFrozenJson(string $path, array $value): void
{
    $stream = fopen($path, 'x');
    if (! $stream) {
        throw new RuntimeException('Artifact đã tồn tại: '.basename($path));
    }
    fwrite($stream, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    fclose($stream);
}

/**
 * =====================================================================
 * Input: body đã extract, chế độ chọn. Output: trích đoạn semantic và selection.
 * Không cắt giữa câu/đoạn; web opening giới hạn 200 từ, giữ ảnh xen kẽ.
 * Bảng dùng nguyên bảng đầu cùng đoạn giải thích gần trước bảng.
 * =====================================================================
 */
function selectedExcerpt(string $html, string $mode): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    if ($mode === 'first-table') {
        $table = $xpath->query('//table')->item(0);
        if (! $table) {
            throw new RuntimeException('Không có bảng thật trong nguồn.');
        }
        $heading = $xpath->query('preceding::h2[1] | preceding::h3[1]', $table);
        $explanation = $xpath->query('preceding::p[1]', $table)->item(0);
        $selected = '';
        foreach ($heading ?: [] as $node) {
            $selected .= $document->saveHTML($node);
        }
        $selected .= ($explanation ? $document->saveHTML($explanation) : '').$document->saveHTML($table);

        return [(new AiContentSanitizer)->sanitize($selected), 'Bảng đầu nguyên vẹn, heading và đoạn giải thích liền trước; không phải toàn trang'];
    }
    $fragments = [];
    $words = 0;
    $paragraphs = 0;
    $seen = [];
    foreach ($xpath->query('//h1 | //h2 | //h3 | //p[not(ancestor::li or ancestor::table or ancestor::figcaption)] | //div[normalize-space(.) != "" and not(ancestor::li or ancestor::figcaption) and not(descendant::p or descendant::div or descendant::table or descendant::ul or descendant::ol or descendant::h1 or descendant::h2 or descendant::h3)] | //img | //figcaption') as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        $count = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($node->nodeName !== 'img' && ($text === '' || $text === 'Tin tức - Sự kiện' || isset($seen[$text]))) {
            continue;
        }
        if ($words + $count > 200) {
            break;
        }
        $fragment = $document->saveHTML($node);
        if ($node->nodeName === 'div') {
            $fragment = '<p>';
            foreach ($node->childNodes as $child) {
                $fragment .= $document->saveHTML($child);
            }
            $fragment .= '</p>';
        }
        $fragments[] = ['html' => $fragment, 'type' => $node->nodeName];
        $words += $count;
        $paragraphs += in_array($node->nodeName, ['p', 'div'], true) ? 1 : 0;
        $seen[$text] = true;
    }
    if ($paragraphs < 1) {
        throw new RuntimeException('Không trích được đoạn văn nguồn hoàn chỉnh.');
    }

    while ($fragments !== [] && in_array($fragments[array_key_last($fragments)]['type'], ['h1', 'h2', 'h3'], true)) {
        array_pop($fragments);
    }

    return [(new AiContentSanitizer)->sanitize(implode('', array_column($fragments, 'html'))), 'Đầu body đã extract: '.$paragraphs.' đoạn hoàn chỉnh, tối đa 200 từ, ảnh/chú thích xen kẽ; không phải toàn bài'];
}
