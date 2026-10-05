<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đóng băng corpus nguồn thật từ commit docs chính thức và HTML local.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: section(), saveJson(); danh sách ca và vòng lặp freeze.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): --output, --html-source -> raw/HTML/snapshot/hash/manifest.
 * SIDE EFFECT: tải Markdown public có timeout, ghi artifact mới; không gọi AI/DB.
 * Trích phần tài liệu được ghi rõ; không giả là toàn bài hoặc facts đã được chấm.
 * =====================================================================
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$options = getopt('', ['output:', 'html-source:']);
$output = $options['output'] ?? base_path('docs/qa/task2-quality/corpus-v1');
if (file_exists($output)) {
    throw new RuntimeException('Output đã tồn tại. Dùng thư mục version mới để không sửa hash đã freeze.');
}
if (! mkdir($output, 0755, true) && ! is_dir($output)) {
    throw new RuntimeException('Không tạo được thư mục corpus.');
}
$vue = '40aa88af0094f7bab4aaf786e55c748a6a251d88';
$laravel = '5b8c610735c8af96a3bda4e37a820b27dc40aee9';
$specs = [
    ['Q01', 'vue', 'src/guide/extras/reactivity-transform.md', 'preface', 'Tin thay đổi rất ngắn'],
    ['Q02', 'laravel', 'releases.md', 'first-section', 'Release và phiên bản'],
    ['Q03', 'laravel', 'upgrade.md', 'first-section', 'Migration/breaking changes'],
    ['Q04', 'laravel', 'license.md', 'all', 'Điều kiện license'],
    ['Q05', 'vue', 'src/guide/best-practices/performance.md', 'first-section', 'Hiệu năng và bối cảnh'],
    ['Q06', 'vue', 'src/guide/quick-start.md', 'first-section', 'Tutorial CLI'],
    ['Q07', 'laravel', 'configuration.md', 'first-section', 'Cấu hình PHP/Laravel'],
    ['Q08', 'vue', 'src/guide/typescript/overview.md', 'first-section', 'JavaScript/TypeScript'],
    ['Q09', 'vue', 'src/guide/essentials/watchers.md', 'first-section', 'Async/watchers'],
    ['Q10', 'vue', 'src/guide/essentials/component-basics.md', 'first-section', 'Minh họa cây component'],
    ['Q11', 'laravel', 'database.md', 'first-section', 'Các database hỗ trợ/điều kiện'],
    ['Q12', 'vue', 'src/guide/extras/composition-api-faq.md', 'first-section', 'So sánh trade-off'],
    ['Q13', 'vue', 'src/guide/introduction.md', 'first-section', 'Giải thích cho người mới'],
    ['Q14', 'vue', 'src/guide/extras/reactivity-in-depth.md', 'first-section', 'Chuyên sâu'],
    ['Q15', 'vue', 'src/about/faq.md', 'first-section', 'FAQ ngắn'],
    ['Q16', 'vue', 'src/guide/reusability/composables.md', 'all', 'Bài dài có heading'],
    ['Q17', 'vue', 'src/api/built-in-directives.md', 'first-section', 'Nhiều code/ít prose'],
    ['Q19', 'vue', 'src/guide/essentials/forms.md', 'first-section', 'Nội dung HTML trong form'],
    ['Q20', 'vue', 'src/guide/essentials/computed.md', 'first-section', 'Tiếng Anh sang tiếng Việt'],
    ['Q22', 'vue', 'src/guide/components/props.md', 'first-section', 'Thuật ngữ/proper names'],
    ['Q23', 'vue', 'src/guide/best-practices/security.md', 'first-section', 'Attribution và link'],
    ['Q24', 'vue', 'src/guide/components/attrs.md', 'first-section', 'Nguồn cho ca regenerate với ảnh'],
    ['Q25', 'vue', 'src/guide/components/async.md', 'first-section', 'Điều kiện/async và số liệu'],
];
$converter = new League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
$extractor = new App\Services\Ai\Content\ArticleSourceExtractor;
$manifest = ['version' => 1, 'captured_at' => now()->toIso8601String(), 'review_status' => 'pending_human',
    'coverage_gaps' => ['Corpus chủ yếu tài liệu kỹ thuật, chưa đại diện đủ bài du lịch/tin tức.', 'Chưa có benchmark với bảng số độc lập/gói trả phí.', 'Facts chuẩn và reviewer chưa được người đọc xác nhận.', 'Q24 cần fixture parent/asset riêng; source corpus không tự tạo MediaAsset.'], 'cases' => []];
/**
 * =====================================================================
 * Input: Markdown và chế độ chọn.
 * Output: một đơn vị nguồn hoàn chỉnh, selection được ghi trong manifest.
 * =====================================================================
 */
function section(string $markdown, string $selection): string
{
    if ($selection === 'all') {
        return $markdown;
    }
    $offsets = [];
    $insideFence = false;
    $offset = 0;
    foreach (preg_split('/(?<=\n)/', $markdown) as $line) {
        if (preg_match('/^\s*(?:```|~~~)/', $line)) {
            $insideFence = ! $insideFence;
        }
        if (! $insideFence && str_starts_with($line, '## ')) {
            $offsets[] = $offset;
        }
        $offset += strlen($line);
    }
    $end = $selection === 'preface' ? ($offsets[0] ?? strlen($markdown)) : ($offsets[1] ?? strlen($markdown));

    return substr($markdown, 0, $end);
}
/**
 * =====================================================================
 * Input: file/JSON.
 * Output: UTF-8 artifact/hash ổn định, không ghi đè nếu file có sẵn.
 * =====================================================================
 */
function saveJson(string $path, array $value): void
{
    if (file_exists($path)) {
        throw new RuntimeException('Artifact đã tồn tại: '.basename($path));
    }
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
foreach ($specs as [$id, $repo, $path, $selection, $group]) {
    $commit = $repo === 'vue' ? $vue : $laravel;
    $repository = $repo === 'vue' ? 'vuejs/docs' : 'laravel/docs';
    $rawUrl = 'https://raw.githubusercontent.com/'.$repository.'/'.$commit.'/'.$path;
    $response = Illuminate\Support\Facades\Http::timeout(25)->get($rawUrl);
    if (! $response->successful()) {
        throw new RuntimeException($id.' fetch failed: '.$response->status());
    }
    $raw = $response->body();
    $chosen = section($raw, $selection);
    $html = (string) $converter->convert($chosen);
    if ($id === 'Q19') {
        $html = '<form><main>'.$html.'</main></form>';
    }
    $origin = 'https://github.com/'.$repository.'/blob/'.$commit.'/'.$path;
    $license = $repo === 'vue' ? 'CC BY 4.0 (image files excluded)' : 'MIT';
    $content = $extractor->extract('<article>'.$html.'</article>', $origin);
    $snapshot = $extractor->snapshot($content, ['source_url' => $origin, 'title' => $group, 'source_format' => 'html']);
    file_put_contents($output.'/'.$id.'.md', $raw);
    saveJson($output.'/'.$id.'.json', $snapshot);
    $manifest['cases'][] = ['case_id' => $id, 'group' => $group, 'source_language' => 'en', 'output_language' => 'vi',
        'source_snapshot_path' => $id.'.json', 'source_sha256' => hash_file('sha256', $output.'/'.$id.'.json'), 'raw_sha256' => hash('sha256', $raw),
        'source_origin_url' => $origin, 'selection' => $selection, 'license' => $license,
        'attribution' => $repo === 'vue' ? 'Yuxi (Evan) You and Vue documentation contributors' : 'Taylor Otwell and Laravel documentation contributors',
        'license_url' => 'https://github.com/'.$repository.'/blob/'.$commit.'/'.($repo === 'vue' ? 'LICENSE' : 'license.md'),
        'writing_brief' => ['audience' => 'Người phát triển website', 'purpose' => 'Hiểu nội dung và điều kiện nguồn', 'length' => 'Theo lượng thông tin nguồn, tránh kéo dài/lặp ý'],
        'important_facts' => [], 'human_fact_review' => 'pending'];
    echo $id.' frozen: '.count($snapshot['blocks']).' blocks'.PHP_EOL;
}
if (isset($options['html-source'])) {
    $raw = file_get_contents($options['html-source']);
    $content = $extractor->extract($raw);
    $snapshot = $extractor->snapshot($content, ['title' => 'Ovation of the Seas — nguồn người dùng cung cấp', 'source_format' => 'html']);
    foreach (['Q18' => 'HTML có navigation/footer/ads', 'Q21' => 'Nguồn tiếng Việt viết lại tiếng Việt'] as $id => $group) {
        saveJson($output.'/'.$id.'.json', $snapshot);
        $manifest['cases'][] = ['case_id' => $id, 'group' => $group, 'source_language' => 'vi', 'output_language' => 'vi',
            'source_snapshot_path' => $id.'.json', 'source_sha256' => hash_file('sha256', $output.'/'.$id.'.json'), 'raw_sha256' => hash('sha256', $raw),
            'source_origin_url' => null, 'selection' => 'backend extracts main article', 'license' => 'User supplied; local evaluation only',
            'attribution' => 'Star Travel / Star International; HTML supplied by project owner', 'license_url' => null,
            'writing_brief' => ['audience' => 'Người tìm hiểu du thuyền', 'purpose' => 'Hiểu trải nghiệm và giới hạn nguồn cũ', 'length' => 'Theo lượng thông tin nguồn'],
            'important_facts' => [], 'human_fact_review' => 'pending', 'notes' => 'Q18/Q21 dùng cùng bài thật để kiểm hai luồng; không tính là hai nguồn độc lập.'];
    }
}
usort($manifest['cases'], fn (array $a, array $b): int => strcmp($a['case_id'], $b['case_id']));
$manifest['unique_sources'] = count(array_unique(array_column($manifest['cases'], 'raw_sha256')));
saveJson($output.'/manifest.json', $manifest);
echo 'Frozen '.count($manifest['cases']).' cases / '.$manifest['unique_sources'].' unique sources; human review pending.'.PHP_EOL;
