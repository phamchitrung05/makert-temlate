<?php

namespace App\Services\Resources;

use App\Exceptions\MediaSecurityException;
use App\Services\Media\ArchiveSecurityScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giải nén và phân tích ZIP Resource Template an toàn
 * =====================================================================
 *
 * Extractor đọc lại archive policy, ghi từng entry vào staging private bằng
 * stream, phát hiện source root, đọc manifest canonical hoặc discovery ZIP
 * Stitch, rồi thu thập screen/dependency diagnostics cho TPL-04 dùng tiếp.
 * File HTML/CSS/JS chỉ được đọc như dữ liệu; extractor không thực thi package,
 * không tải dependency ngoài và không lưu nội dung HTML lớn vào JSON.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận archive scanner và manifest validator dùng chung
 * - extract(): scan, extract và trả kết quả parser/discovery trong deadline
 * - readArchiveEntries(): chuẩn hóa entry và chặn collision/static executable
 * - extractEntries(): ghi stream entry vào staging với actual-byte guard
 * - detectSourceRoot(): phát hiện wrapper folder và manifest ambiguity
 * - sourceRootErrors(): kiểm nhiều root HTML/manifest trùng
 * - logicalFiles(): đổi entry archive thành path logical với source root
 * - parseManifest(): validate template.json theo contract v1
 * - discoverScreens(): tìm code.html hoặc HTML screen khi thiếu manifest
 * - buildScreen(): tạo slug/title/screenshot candidate cho một HTML
 * - inspectDependencies(): thu thập link nội bộ và dependency ngoài
 * - inspectReference(): resolve một href/src/url theo source path
 * - validateScreenshot(): phân loại screenshot thiếu/placeholder
 * - normalizeArchivePath(): kiểm path ZIP trước khi ghi disk
 * - readSnippet(): đọc bounded bytes để phân tích title/link
 * - titleFromHtml(): lấy HTML title không thực thi markup
 * - slugify(): chuẩn hóa slug discovery không tự sửa collision
 * - addWarning(): giới hạn warning theo contract
 * - fileMap(): lập map path tới absolute staging file
 * - stagingPathForLogicalFile(): dựng absolute path từ entry record
 * - screenSlugForPath(): tìm slug của screen theo HTML path
 * - resolveLocalPath(): resolve relative path và chặn ra ngoài source root
 * - externalDependency(): redaction URL ngoài, không giữ query/fragment
 * - removeStaging(): dọn run staging khi extraction thất bại
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : absolute ZIP path đã upload và private staging root do caller cấp
 * - OUTPUT: array source_root, manifest/screens, files, diagnostics, warnings/errors
 * - SIDE EFFECT: tạo staging run private; caller chịu trách nhiệm publish/dọn run thành công
 * - EXCEPTION/TRANSACTION: MediaSecurityException cho archive/path vi phạm; không mở DB transaction
 * =====================================================================
 */
final class TemplatePackageExtractor
{
    private const INSPECTION_BYTES = 2_097_152;

    private const MAX_WARNING_COUNT = 200;

    private const MAX_WARNING_LENGTH = 500;

    private const HTML_EXTENSIONS = ['html', 'htm'];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    private const STATIC_BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'blade', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi',
        'scr', 'sh', 'bash', 'ps1', 'vbs', 'jar', 'so', 'dylib',
    ];

    private int $warningTotal = 0;

    private bool $warningsTruncated = false;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo extractor với các policy service dùng chung
     * =====================================================================
     * INPUT: ArchiveSecurityScanner và TemplateManifestValidator từ container.
     * OUTPUT: Instance extractor sẵn sàng parse ZIP.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Container exception nếu dependency không resolve được; không DB transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly ArchiveSecurityScanner $scanner,
        private readonly TemplateManifestValidator $validator,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Scan, extract và parse một Resource Template ZIP
     * =====================================================================
     * INPUT:
     * - $archivePath: absolute path tới ZIP private đã upload.
     * - $stagingRoot: private directory dùng tạo run staging riêng.
     * - $deadlineAt: Unix timestamp có phần thập phân; null khi gọi parser độc lập.
     * OUTPUT: result gồm valid, files, source root, manifest/screens và diagnostics.
     * SIDE EFFECT: tạo một thư mục run-* dưới staging root khi thành công.
     * EXCEPTION/TRANSACTION: MediaSecurityException cho input/archive nguy hiểm; không DB transaction.
     * =====================================================================
     */
    public function extract(string $archivePath, string $stagingRoot, ?float $deadlineAt = null): array
    {
        $this->warningTotal = 0;
        $this->warningsTruncated = false;
        $this->assertWithinDeadline($deadlineAt);

        if (! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new MediaSecurityException('Không thể đọc ZIP Resource Template.');
        }

        $scan = $this->scanner->scan($archivePath);
        File::ensureDirectoryExists($stagingRoot, 0700);
        $resolvedStagingRoot = realpath($stagingRoot) ?: $stagingRoot;
        $runPath = $resolvedStagingRoot.DIRECTORY_SEPARATOR.'run-'.Str::uuid()->toString();
        File::makeDirectory($runPath, 0700, true);

        $zip = new ZipArchive;
        $opened = $zip->open($archivePath);
        if ($opened !== true) {
            $this->removeStaging($runPath);

            throw new MediaSecurityException('Không thể mở ZIP Resource Template.');
        }

        try {
            $entries = $this->readArchiveEntries($zip, $deadlineAt);
            $this->extractEntries($zip, $entries, $runPath, $deadlineAt);
            $this->assertWithinDeadline($deadlineAt);
            $root = $this->detectSourceRoot($entries);
            $logicalFiles = $this->logicalFiles($entries, $root['source_root']);
            $availablePaths = array_column($logicalFiles, 'path');
            $warnings = $root['warnings'];
            $errors = $root['errors'];
            $manifest = null;
            $manifestPresent = false;
            $screens = [];
            $entryScreen = null;
            $requiresEntrySelection = false;

            $manifestResult = $this->parseManifest(
                $runPath,
                $logicalFiles,
                $root['source_root'],
                $availablePaths,
                $warnings,
                $errors,
            );
            $this->assertWithinDeadline($deadlineAt);

            if ($manifestResult['present']) {
                $manifestPresent = true;
                $manifest = $manifestResult['manifest'];
                $screens = array_map(
                    fn (array $screen): array => $screen + ['source_path' => $screen['path']],
                    $manifestResult['screens'],
                );
                $entryScreen = $manifestResult['entry_screen'];
            } else {
                $discovery = $this->discoverScreens($runPath, $logicalFiles, $warnings, $errors);
                $screens = $discovery['screens'];
                $entryScreen = $discovery['entry_screen'];
                $requiresEntrySelection = $discovery['requires_entry_selection'];
                $this->addWarning($warnings, 'manifest_missing', 'Package không có template.json; dùng discovery fallback.');
                if ($requiresEntrySelection) {
                    $this->addWarning($warnings, 'entry_selection_required', 'Package chưa có entry screen xác định; admin phải chọn trước ready.');
                }
            }

            $dependencies = $this->inspectDependencies(
                $runPath,
                $logicalFiles,
                $screens,
                $warnings,
                $errors,
            );
            $this->assertWithinDeadline($deadlineAt);

            return [
                'valid' => $errors === [],
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
                'warnings_total' => $this->warningTotal,
                'warnings_truncated' => $this->warningsTruncated,
                'scan' => $scan,
                'staging_path' => $runPath,
                'source_root' => $root['source_root'],
                'manifest_present' => $manifestPresent,
                'manifest' => $manifest,
                'entry_screen' => $entryScreen,
                'requires_entry_selection' => $requiresEntrySelection,
                'screens' => $screens,
                'files' => $availablePaths,
                'diagnostics' => $dependencies,
            ];
        } catch (Throwable $exception) {
            $this->removeStaging($runPath);

            throw $exception;
        } finally {
            $zip->close();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc central directory và chuẩn hóa toàn bộ ZIP entry
     * =====================================================================
     * INPUT: ZipArchive đã mở và đã scan policy.
     * OUTPUT: Entry metadata có path normalized, directory flag và sizes.
     * SIDE EFFECT: Không ghi disk.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu path trùng/nguy hiểm.
     * =====================================================================
     */
    private function readArchiveEntries(ZipArchive $zip, ?float $deadlineAt = null): array
    {
        $entries = [];
        $seen = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $this->assertWithinDeadline($deadlineAt);
            $stat = $zip->statIndex($index);
            if (! is_array($stat)) {
                throw new MediaSecurityException('Archive có entry không đọc được.');
            }

            $rawName = (string) ($stat['name'] ?? '');
            $isDirectory = str_ends_with($rawName, '/');
            $path = $this->normalizeArchivePath($rawName, $isDirectory);
            if ($path === null) {
                throw new MediaSecurityException('Archive chứa path không an toàn.');
            }

            $key = mb_strtolower($path);
            if (isset($seen[$key])) {
                throw new MediaSecurityException('Archive chứa path trùng sau chuẩn hóa.');
            }
            $seen[$key] = true;

            $size = (int) ($stat['size'] ?? 0);
            $compressedSize = (int) ($stat['comp_size'] ?? 0);
            if (! $isDirectory && $size > 0 && $compressedSize === 0) {
                throw new MediaSecurityException('Archive có entry nén không hợp lệ.');
            }

            if (! $isDirectory && $this->isStaticBlocked($path)) {
                throw new MediaSecurityException('Archive chứa file server-side hoặc executable.');
            }

            $entries[] = [
                'index' => $index,
                'path' => $path,
                'directory' => $isDirectory,
                'size' => $size,
                'compressed_size' => $compressedSize,
            ];
        }

        return $entries;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi entry vào staging bằng stream và kiểm actual bytes
     * =====================================================================
     * INPUT: Zip entries đã normalized, ZipArchive và run staging path.
     * OUTPUT: Không trả giá trị; entry được ghi đúng path dưới run.
     * SIDE EFFECT: Tạo thư mục/file private trên staging.
     * EXCEPTION/TRANSACTION: RuntimeException/MediaSecurityException khi stream hoặc byte guard lỗi.
     * =====================================================================
     */
    private function extractEntries(ZipArchive $zip, array $entries, string $runPath, ?float $deadlineAt = null): void
    {
        $maxBytes = (int) config('media-assets.archive.max_uncompressed_bytes', 1_073_741_824);
        $actualBytes = 0;

        foreach ($entries as $entry) {
            $this->assertWithinDeadline($deadlineAt);
            $destination = $runPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            if ($entry['directory']) {
                File::ensureDirectoryExists($destination, 0700);

                continue;
            }

            $parent = dirname($destination);
            if (is_link($parent)) {
                throw new MediaSecurityException('Staging parent không được là symlink.');
            }
            File::ensureDirectoryExists($parent, 0700);
            if (is_link($destination)) {
                throw new MediaSecurityException('Staging destination không được là symlink.');
            }

            $input = method_exists($zip, 'getStreamIndex')
                ? $zip->getStreamIndex($entry['index'])
                : false;
            if (! is_resource($input)) {
                throw new RuntimeException('Không thể mở stream entry archive.');
            }

            $output = fopen($destination, 'wb');
            if ($output === false) {
                fclose($input);

                throw new RuntimeException('Không thể ghi entry vào staging.');
            }

            $written = 0;
            try {
                while (! feof($input)) {
                    $this->assertWithinDeadline($deadlineAt);
                    $chunk = fread($input, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Không thể đọc stream entry archive.');
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    $length = strlen($chunk);
                    $written += $length;
                    $actualBytes += $length;
                    if ($actualBytes > $maxBytes) {
                        throw new MediaSecurityException('Archive vượt giới hạn actual bytes khi extract.');
                    }
                    if (fwrite($output, $chunk) !== $length) {
                        throw new RuntimeException('Không thể ghi đủ bytes entry archive.');
                    }
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            if ($written !== $entry['size']) {
                throw new RuntimeException('Kích thước entry sau extract không khớp metadata ZIP.');
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phát hiện wrapper source root và manifest ambiguity
     * =====================================================================
     * INPUT: Entry metadata đã normalize.
     * OUTPUT: source_root nullable cùng warning/error root-level.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không ném exception; lỗi source root trả trong errors.
     * =====================================================================
     */
    private function detectSourceRoot(array $entries): array
    {
        $files = array_values(array_filter($entries, fn (array $entry): bool => ! $entry['directory']));
        $topLevel = [];
        foreach ($files as $entry) {
            $topLevel[explode('/', $entry['path'])[0]] = true;
        }

        $sourceRoot = null;
        if (count($topLevel) === 1) {
            $candidate = array_key_first($topLevel);
            $hasNestedFile = false;
            foreach ($files as $entry) {
                if (str_starts_with($entry['path'], $candidate.'/')) {
                    $hasNestedFile = true;

                    break;
                }
            }
            if ($hasNestedFile) {
                $sourceRoot = $candidate;
            }
        }

        $manifestPaths = array_values(array_filter(
            array_column($files, 'path'),
            fn (string $path): bool => strtolower(basename($path)) === 'template.json',
        ));

        return [
            'source_root' => $sourceRoot,
            'warnings' => [],
            'errors' => $this->sourceRootErrors($files, $topLevel, $manifestPaths),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phát hiện nhiều source root độc lập hoặc manifest trùng
     * =====================================================================
     * INPUT: File entries, top-level segments và các template.json paths.
     * OUTPUT: Blocking source_root_ambiguous errors nếu package không có root rõ.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function sourceRootErrors(array $files, array $topLevel, array $manifestPaths): array
    {
        $errors = [];
        if (count($manifestPaths) > 1) {
            $errors[] = 'source_root_ambiguous: nhiều template.json trong package.';
        }

        $hasRootFile = false;
        $htmlRoots = [];
        foreach ($files as $file) {
            $path = $file['path'];
            if (! str_contains($path, '/')) {
                $hasRootFile = true;

                continue;
            }
            if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::HTML_EXTENSIONS, true)) {
                $htmlRoots[explode('/', $path)[0]] = true;
            }
        }

        if (! $hasRootFile && count($topLevel) > 1 && count($htmlRoots) > 1) {
            $errors[] = 'source_root_ambiguous: nhiều thư mục cùng chứa HTML screen.';
        }

        return $errors;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đổi entry archive thành path tương đối với source root
     * =====================================================================
     * INPUT: Entry list và wrapper source root nullable.
     * OUTPUT: File records có path logical và absolute staging path.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function logicalFiles(array $entries, ?string $sourceRoot): array
    {
        return array_values(array_map(function (array $entry) use ($sourceRoot): array {
            $path = $entry['path'];
            if ($sourceRoot !== null && str_starts_with($path, $sourceRoot.'/')) {
                $path = substr($path, strlen($sourceRoot) + 1);
            }

            return [
                'path' => $path,
                'archive_path' => $entry['path'],
                'directory' => $entry['directory'],
                'size' => $entry['size'],
            ];
        }, array_filter($entries, fn (array $entry): bool => ! $entry['directory'])));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc và validate template.json ở root logical
     * =====================================================================
     * INPUT: Staging, logical files, source root, available paths và diagnostics refs.
     * OUTPUT: present/manifest/screens/entry_screen; lỗi validator append vào refs.
     * SIDE EFFECT: Chỉ đọc template.json trong staging.
     * EXCEPTION/TRANSACTION: File read lỗi trở thành blocking error.
     * =====================================================================
     */
    private function parseManifest(
        string $runPath,
        array $logicalFiles,
        ?string $sourceRoot,
        array $availablePaths,
        array &$warnings,
        array &$errors,
    ): array {
        $manifestFile = null;
        foreach ($logicalFiles as $file) {
            if (strtolower($file['path']) === 'template.json') {
                $manifestFile = $file;

                break;
            }
        }

        if ($manifestFile === null) {
            return [
                'present' => false,
                'manifest' => null,
                'screens' => [],
                'entry_screen' => null,
            ];
        }

        $absolutePath = $runPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifestFile['archive_path']);
        $json = file_get_contents($absolutePath);
        if ($json === false) {
            $errors[] = 'manifest_read_failed: không thể đọc template.json.';

            return ['present' => true, 'manifest' => null, 'screens' => [], 'entry_screen' => null];
        }

        $result = $this->validator->validateJson($json, $availablePaths);
        foreach ($result['errors'] as $error) {
            $errors[] = 'manifest_invalid: '.$error;
        }
        foreach ($result['warnings'] as $warning) {
            $this->addWarning($warnings, 'manifest_warning', $warning);
        }

        return [
            'present' => true,
            'manifest' => $result['valid'] ? $result['manifest'] : null,
            'screens' => $result['valid'] ? $result['manifest']['screens'] : [],
            'entry_screen' => $result['valid'] ? $result['manifest']['entry_screen'] : null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Discovery screen cho package không có manifest
     * =====================================================================
     * INPUT: Staging, logical file list và diagnostics refs.
     * OUTPUT: screens ổn định, entry candidate hoặc requires selection.
     * SIDE EFFECT: Đọc bounded HTML và screenshot metadata.
     * EXCEPTION/TRANSACTION: Không DB transaction; lỗi path ghi vào errors.
     * =====================================================================
     */
    private function discoverScreens(string $runPath, array $files, array &$warnings, array &$errors): array
    {
        $fileMap = $this->fileMap($files, $runPath);
        $codePaths = array_values(array_filter(
            array_column($files, 'path'),
            fn (string $path): bool => strtolower(basename($path)) === 'code.html',
        ));
        $htmlPaths = $codePaths !== []
            ? $codePaths
            : array_values(array_filter(
                array_column($files, 'path'),
                fn (string $path): bool => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::HTML_EXTENSIONS, true),
            ));
        usort($htmlPaths, 'strcmp');

        if ($htmlPaths === []) {
            $errors[] = 'missing_html: package không có screen HTML.';
        }

        $screens = [];
        $slugs = [];
        foreach ($htmlPaths as $path) {
            $screen = $this->buildScreen($runPath, $path, $fileMap, $codePaths !== [], $warnings);
            if (isset($slugs[$screen['slug']])) {
                $errors[] = 'duplicate_slug: discovery tạo slug trùng '.$screen['slug'].'.';
            }
            $slugs[$screen['slug']] = true;
            $screens[] = $screen;
        }

        $entryScreen = null;
        if ($codePaths === [] && isset($fileMap['index.html'])) {
            $entryScreen = $this->screenSlugForPath($screens, 'index.html');
        }
        if (count($screens) === 1 && $codePaths !== []) {
            $entryScreen = $screens[0]['slug'];
        }

        return [
            'screens' => $screens,
            'entry_screen' => $entryScreen,
            'requires_entry_selection' => $entryScreen === null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo screen discovery từ một file HTML
     * =====================================================================
     * INPUT: Staging, logical HTML path, file map, Stitch flag và warnings refs.
     * OUTPUT: slug/title/path/screenshot candidate.
     * SIDE EFFECT: Đọc tối đa INSPECTION_BYTES từ HTML và kiểm screenshot.
     * EXCEPTION/TRANSACTION: Không DB transaction.
     * =====================================================================
     */
    private function buildScreen(string $runPath, string $path, array $fileMap, bool $stitch, array &$warnings): array
    {
        $absolutePath = $fileMap[mb_strtolower($path)]['absolute_path'];
        $truncated = false;
        $html = $this->readSnippet($absolutePath, $truncated);
        if ($truncated) {
            $this->addWarning($warnings, 'html_inspection_truncated', "HTML {$path} vượt giới hạn đọc diagnostics.");
        }
        $base = pathinfo($path, PATHINFO_FILENAME);
        $folder = $stitch && strtolower($base) === 'code' && dirname($path) !== '.'
            ? basename(dirname($path))
            : $base;
        $title = $this->titleFromHtml($html) ?: $this->humanize($folder);
        $slug = $this->slugify($folder);
        $screenshot = $this->findScreenshot($path, $slug, $fileMap, $stitch);

        $screen = [
            'slug' => $slug,
            'title' => $title,
            'path' => $path,
            'source_path' => $path,
        ];
        if ($screenshot !== null) {
            $screen['screenshot'] = $screenshot;
        } else {
            $this->addWarning($warnings, 'missing_screenshot', "Screen {$slug} chưa có screenshot candidate.");
        }

        return $screen;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thu thập dependency từ HTML/CSS và screenshot diagnostics
     * =====================================================================
     * INPUT: Staging, logical files, screens và diagnostics refs.
     * OUTPUT: local_dependencies, external_dependencies và ignored references.
     * SIDE EFFECT: Đọc bounded HTML/CSS; không tải URL ngoài.
     * EXCEPTION/TRANSACTION: Missing local required dependency append errors.
     * =====================================================================
     */
    private function inspectDependencies(string $runPath, array $files, array $screens, array &$warnings, array &$errors): array
    {
        $fileMap = $this->fileMap($files, $runPath);
        $local = [];
        $external = [];
        $ignored = [];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            if (! in_array($extension, ['html', 'htm', 'css'], true)) {
                continue;
            }

            $truncated = false;
            $content = $this->readSnippet($fileMap[mb_strtolower($file['path'])]['absolute_path'], $truncated);
            if ($truncated) {
                $this->addWarning($warnings, 'inspection_truncated', "File {$file['path']} vượt giới hạn đọc diagnostics.");
            }
            if ($extension === 'css') {
                preg_match_all('/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)|url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $target = $match[1] !== '' ? $match[1] : $match[2];
                    $this->inspectReference($file['path'], 'style', $target, $fileMap, $local, $external, $ignored, $warnings, $errors);
                }
            } else {
                preg_match_all('/\b(?:href|src|poster)\s*=\s*(["\'])(.*?)\1/is', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $this->inspectReference($file['path'], 'markup', html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $fileMap, $local, $external, $ignored, $warnings, $errors);
                }
            }
        }

        foreach ($screens as $screen) {
            if (isset($screen['screenshot']) && isset($fileMap[mb_strtolower($screen['screenshot'])])) {
                $this->validateScreenshot($fileMap[mb_strtolower($screen['screenshot'])]['absolute_path'], $screen['screenshot'], $warnings);
            }
        }

        return [
            'local_dependencies' => array_values($local),
            'external_dependencies' => array_values($external),
            'ignored_references' => array_values($ignored),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve một href/src/url và phân loại dependency
     * =====================================================================
     * INPUT: source path, loại reference, target và các map/diagnostics refs.
     * OUTPUT: Append local/external/ignored diagnostics; missing local là error.
     * SIDE EFFECT: Không network; chỉ đọc map path.
     * EXCEPTION/TRANSACTION: Không ném exception cho link sai; parser trả blocking error.
     * =====================================================================
     */
    private function inspectReference(
        string $sourcePath,
        string $type,
        string $target,
        array $fileMap,
        array &$local,
        array &$external,
        array &$ignored,
        array &$warnings,
        array &$errors,
    ): void {
        if ($target === '' || $target === '#') {
            $this->addWarning($warnings, 'placeholder_link', "Reference placeholder trong {$sourcePath}.");

            return;
        }
        if (preg_match('/^(?:mailto:|tel:|data:|javascript:)/i', $target) === 1) {
            $ignored[] = ['source' => $sourcePath, 'target' => $target, 'type' => $type];

            return;
        }
        if (str_starts_with($target, '//') || preg_match('/^https?:\/\//i', $target) === 1) {
            $dependency = $this->externalDependency($sourcePath, $type, $target);
            $key = $dependency['host'].'|'.$dependency['type'].'|'.$dependency['url'];
            $external[$key] = $dependency;
            $this->addWarning($warnings, 'external_dependency', "Reference ngoài package trong {$sourcePath}: {$dependency['host']}.");

            return;
        }

        $targetWithoutFragment = preg_split('/[?#]/', $target, 2)[0] ?? '';
        if ($targetWithoutFragment === '') {
            $this->addWarning($warnings, 'fragment_reference', "Fragment reference trong {$sourcePath}.");

            return;
        }
        if (str_starts_with($targetWithoutFragment, '/') || str_contains($targetWithoutFragment, '%') || str_contains($targetWithoutFragment, '\\')) {
            $errors[] = "unsafe_local_dependency: {$sourcePath} -> {$target}";

            return;
        }

        $resolved = $this->resolveLocalPath($sourcePath, $targetWithoutFragment);
        if ($resolved === null) {
            $errors[] = "link_outside_source_root: {$sourcePath} -> {$target}";

            return;
        }
        $key = mb_strtolower($resolved);
        if (! isset($fileMap[$key])) {
            $errors[] = "missing_local_dependency: {$sourcePath} -> {$resolved}";

            return;
        }
        $local[] = [
            'source' => $sourcePath,
            'target' => $target,
            'resolved' => $resolved,
            'type' => $type,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra screenshot thật hay placeholder
     * =====================================================================
     * INPUT: Absolute image path, logical path và warnings ref.
     * OUTPUT: Không trả giá trị; warning nếu file không decode được.
     * SIDE EFFECT: Đọc image metadata, không sửa image.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function validateScreenshot(string $path, string $logicalPath, array &$warnings): void
    {
        if (@getimagesize($path) === false) {
            $this->addWarning($warnings, 'placeholder_screenshot', "Screenshot không decode được: {$logicalPath}.");
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm path ZIP trước khi ghi staging
     * =====================================================================
     * INPUT: Raw entry name và cờ directory.
     * OUTPUT: Path slash-normalized hoặc null cho root directory.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có; caller ném MediaSecurityException.
     * =====================================================================
     */
    private function normalizeArchivePath(string $path, bool $directory): ?string
    {
        if ($path === '' || str_contains($path, '\\')) {
            return null;
        }
        $trimmed = $directory ? rtrim($path, '/') : $path;
        if ($trimmed === '') {
            return null;
        }

        return $this->validator->normalizeRelativePath($trimmed);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc tối đa một lượng bytes để phân tích static metadata
     * =====================================================================
     * INPUT: Absolute file path và cờ truncated by reference.
     * OUTPUT: Chuỗi bounded hoặc rỗng khi không đọc được.
     * SIDE EFFECT: Chỉ đọc file.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function readSnippet(string $path, bool &$truncated): string
    {
        $truncated = false;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        $content = fread($handle, self::INSPECTION_BYTES + 1);
        fclose($handle);
        if ($content === false) {
            return '';
        }
        if (strlen($content) > self::INSPECTION_BYTES) {
            $truncated = true;
            $content = substr($content, 0, self::INSPECTION_BYTES);
        }

        return $content;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy title HTML đầu tiên không thực thi nội dung
     * =====================================================================
     * INPUT: HTML snippet bounded.
     * OUTPUT: title đã trim hoặc null nếu thiếu/rỗng.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function titleFromHtml(string $html): ?string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return null;
        }

        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $title === '' ? null : Str::limit($title, 160, '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển tên file/folder discovery thành title đọc được
     * =====================================================================
     * INPUT: Folder hoặc file stem.
     * OUTPUT: Title fallback giới hạn 160 ký tự.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function humanize(string $value): string
    {
        $value = preg_replace('/[_-]+/', ' ', $value) ?? $value;

        return Str::limit(trim($value) === '' ? 'Untitled screen' : trim($value), 160, '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo slug fallback ASCII ổn định
     * =====================================================================
     * INPUT: Tên folder/file discovery.
     * OUTPUT: kebab-case ASCII slug, không tự thêm suffix khi collision.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function slugify(string $value): string
    {
        $slug = Str::slug(Str::ascii($value));

        return $slug === '' ? 'screen' : Str::limit($slug, 120, '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tìm screenshot candidate theo Stitch hoặc convention
     * =====================================================================
     * INPUT: HTML path, slug, file map và cờ Stitch.
     * OUTPUT: Logical image path hoặc null.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function findScreenshot(string $htmlPath, string $slug, array $fileMap, bool $stitch): ?string
    {
        $directory = dirname($htmlPath);
        $base = pathinfo($htmlPath, PATHINFO_FILENAME);
        $candidates = [];
        if ($stitch) {
            foreach (self::IMAGE_EXTENSIONS as $extension) {
                $candidates[] = ($directory === '.' ? '' : $directory.'/').'screen.'.$extension;
            }
        }
        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $candidates[] = 'screenshots/'.$slug.'.'.$extension;
            $candidates[] = 'screenshots/'.$base.'.'.$extension;
        }

        foreach ($candidates as $candidate) {
            if (isset($fileMap[mb_strtolower($candidate)])) {
                return $fileMap[mb_strtolower($candidate)]['path'];
            }
        }

        return null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm file extension static-preview và executable policy
     * =====================================================================
     * INPUT: Relative path file trong archive.
     * OUTPUT: true nếu path bị block.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function isStaticBlocked(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $blocked = array_unique(array_merge(
            self::STATIC_BLOCKED_EXTENSIONS,
            config('media-assets.archive.blocked_entry_extensions', []),
        ));

        return in_array($extension, $blocked, true) || str_ends_with(strtolower($path), '.blade.php');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng map file theo path case-folding
     * =====================================================================
     * INPUT: Logical file records.
     * OUTPUT: map lowercase path tới record và absolute staging path.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function fileMap(array $files, string $runPath): array
    {
        $map = [];
        foreach ($files as $file) {
            $file['_run_path'] = $runPath;
            $file['absolute_path'] = $this->stagingPathForLogicalFile($file);
            $map[mb_strtolower($file['path'])] = $file;
        }

        return $map;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo absolute path từ record logical file
     * =====================================================================
     * INPUT: File record có archive_path và implicit staging path.
     * OUTPUT: Placeholder path được thay thế bởi caller trước khi inspect.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function stagingPathForLogicalFile(array $file): string
    {
        return $file['_run_path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['archive_path']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy slug của screen theo path discovery
     * =====================================================================
     * INPUT: Screen list và logical HTML path.
     * OUTPUT: slug hoặc null.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function screenSlugForPath(array $screens, string $path): ?string
    {
        foreach ($screens as $screen) {
            if (($screen['path'] ?? null) === $path) {
                return $screen['slug'];
            }
        }

        return null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve URL ngoài và loại bỏ query/fragment nhạy cảm
     * =====================================================================
     * INPUT: Source path, loại dependency và URL ngoài.
     * OUTPUT: Diagnostic chỉ giữ scheme/host/path đã redacted.
     * SIDE EFFECT: Không network.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function externalDependency(string $sourcePath, string $type, string $target): array
    {
        $value = str_starts_with($target, '//') ? 'https:'.$target : $target;
        $parts = parse_url($value) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return [
            'source' => $sourcePath,
            'type' => $type,
            'host' => $host,
            'url' => $scheme.'://'.$host.$path,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve local path tương đối so với file nguồn
     * =====================================================================
     * INPUT: Source logical path và target không query/fragment.
     * OUTPUT: normalized path trong source root hoặc null nếu ra ngoài.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function resolveLocalPath(string $sourcePath, string $target): ?string
    {
        $parts = [];
        $combined = (dirname($sourcePath) === '.' ? '' : dirname($sourcePath).'/').$target;
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        $resolved = implode('/', $parts);

        return $this->validator->normalizeRelativePath($resolved) === null ? null : $resolved;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Append warning theo giới hạn contract
     * =====================================================================
     * INPUT: Warning list ref, code và message.
     * OUTPUT: Warning list tối đa 200 message, mỗi message tối đa 500 ký tự.
     * SIDE EFFECT: Cập nhật warning list.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function addWarning(array &$warnings, string $code, string $message): void
    {
        $this->warningTotal++;
        if (count($warnings) >= self::MAX_WARNING_COUNT) {
            $this->warningsTruncated = true;

            return;
        }
        $warnings[] = Str::limit($code.': '.$message, self::MAX_WARNING_LENGTH, '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn parser vượt process deadline của một lượt chạy
     * =====================================================================
     * INPUT: Unix deadline hoặc null khi extractor được gọi độc lập.
     * OUTPUT: Không trả giá trị khi còn trong thời gian cho phép.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: RuntimeException để queue retry khi quá hạn.
     * =====================================================================
     */
    private function assertWithinDeadline(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
            throw new RuntimeException('Template processing vượt quá thời gian cho phép.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn run staging khi extraction gặp exception
     * =====================================================================
     * INPUT: Absolute run path do extractor vừa tạo.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Xóa đúng run directory tạm.
     * EXCEPTION/TRANSACTION: Lỗi dọn dẹp không che exception gốc.
     * =====================================================================
     */
    private function removeStaging(string $runPath): void
    {
        if (is_dir($runPath)) {
            File::deleteDirectory($runPath);
        }
    }
}
