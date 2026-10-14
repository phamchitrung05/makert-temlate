<?php

namespace Tests\Unit;

use App\Services\Media\ArchiveSecurityScanner;
use App\Services\Resources\TemplateManifestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử contract manifest và baseline độc lập của TPL-00
 * =====================================================================
 *
 * Test đọc snapshot đã đóng băng, không tạo lại expected từ nguồn đang test.
 * Bao phủ input sai kiểu, allowlist, bounds, path, quan hệ entry/slug/path,
 * hai ZIP, local navigation và screenshot thật/placeholder. Không DB/network.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_normalized_fixture_matches_expected_manifest(): kiểm manifest và scan ZIP
 * - test_manifest_contract_cases(): kiểm dataset hợp lệ/blocking
 * - manifestCases(): cung cấp input JSON và unsafe path đã đóng băng
 * - test_manifest_bounds_and_empty_package(): kiểm giới hạn và package rỗng
 * - test_missing_screenshot_is_only_warning(): kiểm warning ảnh thiếu
 * - test_raw_stitch_matches_discovery_baseline(): kiểm raw ZIP chưa có entry
 * - test_fixture_checksums_and_screenshots(): kiểm checksum và nội dung ảnh
 * - test_normalized_navigation_targets_exist(): kiểm relative href/src tồn tại
 * - readZip(): đọc ZIP trong memory, không extract hoặc thực thi package
 * - fixtureJson(): đọc expected JSON dưới test fixture
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: snapshot ZIP, expected JSON và corpus input trong tests/Fixtures
 * - OUTPUT: assertions đúng contract, không chứng nhận parser/preview đã triển khai
 * - SIDE EFFECT: Chỉ đọc local file; không ghi database, network hoặc queue
 * - EXCEPTION/TRANSACTION: Lỗi fixture/read được báo test failed; không mở transaction
 * =====================================================================
 */
class ResourceTemplateManifestValidatorTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm normalized snapshot với expected manifest cố định
     * =====================================================================
     * INPUT: ZIP normalized và expected/normalized-manifest.json đã đóng băng.
     * OUTPUT: Manifest valid, 18 screen, metadata tùy chọn/path đúng expected; ZIP scan đạt.
     * SIDE EFFECT: Chỉ đọc central directory/bytes, không extract/chạy HTML/JS.
     * EXCEPTION/TRANSACTION: JSON/read lỗi làm test fail; không DB/transaction.
     * =====================================================================
     */
    public function test_normalized_fixture_matches_expected_manifest(): void
    {
        $path = base_path('tests/Fixtures/ResourceTemplate/normalized/package.zip');
        $entries = $this->readZip($path);
        $this->assertArrayHasKey('flowtask/template.json', $entries);
        $this->assertArrayNotHasKey('flowtask/manifest.json', $entries);
        $relativePaths = array_map(fn ($name) => substr($name, strlen('flowtask/')), array_keys($entries));

        $result = app(TemplateManifestValidator::class)->validateJson($entries['flowtask/template.json'], $relativePaths);

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
        $this->assertSame([], $result['warnings']);
        $this->assertEquals($this->fixtureJson('expected/normalized-manifest.json'), $result['manifest']);
        $this->assertCount(count($this->fixtureJson('expected/normalized-manifest.json')['screens']), $result['manifest']['screens']);
        $stats = app(ArchiveSecurityScanner::class)->scan($path);
        $this->assertSame(count($entries), $stats['entries']);
        $this->assertGreaterThan(0, $stats['uncompressed_bytes']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm corpus schema/semantic và path mà không ghi database
     * =====================================================================
     * INPUT: JSON UTF-8 hoặc JSON lỗi cùng expected valid flag từ data provider.
     * OUTPUT: Validator chỉ chấp nhận input đã chốt trong contract.
     * SIDE EFFECT: Không có; không cần file list khi kiểm riêng schema.
     * EXCEPTION/TRANSACTION: Không exception từ input sai; không mở transaction.
     * =====================================================================
     */
    #[DataProvider('manifestCases')]
    public function test_manifest_contract_cases(string $json, bool $valid): void
    {
        $result = app(TemplateManifestValidator::class)->validateJson($json);

        $this->assertSame($valid, $result['valid'], implode(' | ', $result['errors']));
        if ($valid) {
            $this->assertSame([], $result['errors']);
        } else {
            $this->assertNotEmpty($result['errors']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cấp corpus immutable cho từng test manifest
     * =====================================================================
     * INPUT: manifest-cases.json nằm cạnh hai snapshot ZIP.
     * OUTPUT: Named dataset gồm valid, invalid, semantic invalid, path và JSON lỗi.
     * SIDE EFFECT: Chỉ đọc fixture, không tạo hoặc sửa expected output.
     * EXCEPTION/TRANSACTION: JSON fixture sai làm provider fail; không DB/transaction.
     * =====================================================================
     */
    public static function manifestCases(): array
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/ResourceTemplate/manifest-cases.json'), true, 16, JSON_THROW_ON_ERROR);
        $datasets = [];
        foreach (['valid' => true, 'invalid' => false, 'semantic_invalid' => false] as $group => $valid) {
            foreach ($cases[$group] as $name => $manifest) {
                if ($group === 'invalid' && $name === 'object-screens') {
                    continue;
                }
                $datasets[$group.':'.$name] = [json_encode($manifest, JSON_THROW_ON_ERROR), $valid];
            }
        }
        $datasets['invalid:object-screens'] = ['{"schema_version":1,"entry_screen":"home","screens":{"0":{"slug":"home","title":"Home","path":"index.html"}}}', false];
        foreach ($cases['unsafe_paths'] as $index => $path) {
            $manifest = $cases['valid']['minimal'];
            $manifest['screens'][0]['path'] = $path;
            $datasets['unsafe-path:'.$index] = [json_encode($manifest, JSON_THROW_ON_ERROR), false];
        }
        foreach (['malformed' => '{', 'list-root' => '[]', 'null-root' => 'null', 'string-root' => '"template"'] as $name => $json) {
            $datasets['json:'.$name] = [$json, false];
        }

        return $datasets;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm bounds và phân biệt schema-only với package rỗng
     * =====================================================================
     * INPUT: Minimal manifest, metadata unicode dài, 201 screen và JSON quá 256 KiB.
     * OUTPUT: Bound vi phạm bị chặn; [] file list không bypass kiểm HTML tồn tại.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không DB/transaction; input sai trả lỗi có kiểm soát.
     * =====================================================================
     */
    public function test_manifest_bounds_and_empty_package(): void
    {
        $validator = app(TemplateManifestValidator::class);
        $minimal = $this->fixtureJson('manifest-cases.json')['valid']['minimal'];
        $this->assertTrue($validator->validate($minimal)['valid']);
        $this->assertFalse($validator->validate($minimal, [])['valid']);

        foreach (['name' => 120, 'title' => 160, 'version' => 64, 'description' => 2000] as $field => $limit) {
            $manifest = $minimal;
            $manifest[$field] = str_repeat('ế', $limit);
            $this->assertTrue($validator->validate($manifest)['valid'], $field);
            $manifest[$field] .= 'ế';
            $this->assertFalse($validator->validate($manifest)['valid'], $field);
        }
        $manifest = $minimal;
        $manifest['screens'] = array_fill(0, 201, $minimal['screens'][0]);
        $this->assertFalse($validator->validate($manifest)['valid']);
        $this->assertFalse($validator->validateJson(str_repeat(' ', 262145))['valid']);
        $this->assertFalse($validator->validateJson("{\"name\":\"\xFF\"}")['valid']);
        $manifest = $minimal;
        $manifest['screens'][0]['path'] = str_repeat('a', 508).'.html';
        $this->assertFalse($validator->validate($manifest)['valid']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Không biến ảnh tùy chọn bị thiếu thành lỗi blocking
     * =====================================================================
     * INPUT: HTML tồn tại, screenshot omitted hoặc path ảnh không có trong package.
     * OUTPUT: Manifest valid và warning tương ứng.
     * SIDE EFFECT: Không đọc bytes ảnh hoặc ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không DB/transaction.
     * =====================================================================
     */
    public function test_missing_screenshot_is_only_warning(): void
    {
        $manifest = $this->fixtureJson('manifest-cases.json')['valid']['minimal'];
        $validator = app(TemplateManifestValidator::class);
        $result = $validator->validate($manifest, ['index.html']);
        $this->assertTrue($result['valid']);
        $this->assertCount(1, $result['warnings']);

        $manifest['screens'][0]['screenshot'] = 'screenshots/absent.png';
        $result = $validator->validate($manifest, ['index.html']);
        $this->assertTrue($result['valid']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('không tồn tại', $result['warnings'][0]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm raw ZIP và expected discovery chưa có entry ready
     * =====================================================================
     * INPUT: Raw Stitch ZIP có 9 screen và expected/raw-discovery.json.
     * OUTPUT: Path/source root/screenshot khớp; entry null và phải chọn trước ready.
     * SIDE EFFECT: Chỉ đọc central directory/bytes; không triển khai parser TPL-03.
     * EXCEPTION/TRANSACTION: ZIP/read lỗi làm test fail; không DB/transaction.
     * =====================================================================
     */
    public function test_raw_stitch_matches_discovery_baseline(): void
    {
        $path = base_path('tests/Fixtures/ResourceTemplate/raw-stitch/package.zip');
        $entries = $this->readZip($path);
        $expected = $this->fixtureJson('expected/raw-discovery.json');
        $sourceRoot = $expected['source_root'].'/';
        $this->assertFalse($expected['manifest_present']);
        $this->assertNull($expected['entry_screen']);
        $this->assertTrue($expected['requires_entry_selection']);
        $this->assertArrayNotHasKey($sourceRoot.'template.json', $entries);
        $this->assertCount(9, $expected['screens']);
        $codePaths = array_values(array_filter(array_keys($entries), fn ($name) => str_ends_with($name, '/code.html')));
        sort($codePaths, SORT_STRING);
        $this->assertSame(array_map(fn ($screen) => $sourceRoot.$screen['path'], $expected['screens']), $codePaths);
        foreach ($expected['screens'] as $screen) {
            $this->assertArrayHasKey($sourceRoot.$screen['screenshot'], $entries);
            $this->assertNotFalse(getimagesizefromstring($entries[$sourceRoot.$screen['screenshot']]));
        }
        $stats = app(ArchiveSecurityScanner::class)->scan($path);
        $this->assertSame(30, $stats['entries']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm bytes baseline và phân biệt ảnh thật với 5 placeholder
     * =====================================================================
     * INPUT: Inventory SHA-256, ảnh trong normalized ZIP và PNG synthetic 28 byte.
     * OUTPUT: Baseline chưa đổi; 18 ảnh thật đọc được, 5 ảnh giả không qua getimagesize.
     * SIDE EFFECT: Chỉ đọc files; không tạo/chỉnh sửa ảnh.
     * EXCEPTION/TRANSACTION: Không DB/transaction; hash sai làm test fail.
     * =====================================================================
     */
    public function test_fixture_checksums_and_screenshots(): void
    {
        $root = base_path('tests/Fixtures/ResourceTemplate');
        foreach ($this->fixtureJson('expected/inventory.json') as $relative => $expected) {
            $this->assertSame($expected['bytes'], filesize($root.'/'.$relative), $relative);
            $this->assertSame($expected['sha256'], hash_file('sha256', $root.'/'.$relative), $relative);
        }
        $entries = $this->readZip($root.'/normalized/package.zip');
        foreach ($this->fixtureJson('expected/normalized-manifest.json')['screens'] as $screen) {
            $this->assertNotFalse(getimagesizefromstring($entries['flowtask/'.$screen['screenshot']]));
        }
        for ($index = 1; $index <= 5; $index++) {
            $bytes = file_get_contents($root.'/invalid-screenshots/placeholder-'.$index.'.png');
            $this->assertSame(28, strlen($bytes));
            $this->assertFalse(@getimagesizefromstring($bytes));
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khóa navigation baseline của normalized package
     * =====================================================================
     * INPUT: 18 HTML trong ZIP snapshot và relative href/src không phải URL ngoài/fragment.
     * OUTPUT: Mọi local target trong markup đều có trong package; dependencies ngoài giữ nguyên.
     * SIDE EFFECT: Không mở browser, gọi URL hoặc thực thi HTML/JavaScript.
     * EXCEPTION/TRANSACTION: Không DB/transaction; không chứng nhận runtime browser.
     * =====================================================================
     */
    public function test_normalized_navigation_targets_exist(): void
    {
        $entries = $this->readZip(base_path('tests/Fixtures/ResourceTemplate/normalized/package.zip'));
        $localLinks = 0;
        foreach ($this->fixtureJson('expected/normalized-manifest.json')['screens'] as $screen) {
            $sourcePath = 'flowtask/'.$screen['path'];
            preg_match_all('/(?:href|src)\s*=\s*["\']([^"\']+)/i', $entries[$sourcePath], $matches);
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i', $url)) {
                    continue;
                }
                $target = dirname($sourcePath).'/'.explode('?', explode('#', $url)[0])[0];
                $this->assertArrayHasKey($target, $entries, $sourcePath.' => '.$url);
                $localLinks++;
            }
        }
        $this->assertGreaterThan(100, $localLinks);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc fixture ZIP trong memory mà không extract
     * =====================================================================
     * INPUT: Path ZIP snapshot nằm trong tests/Fixtures.
     * OUTPUT: Map relative entry path => bytes; không gồm directory.
     * SIDE EFFECT: Không ghi filesystem/database hoặc chạy package code.
     * EXCEPTION/TRANSACTION: RuntimeException nếu ZIP hỏng; luôn đóng ZIP; không DB.
     * =====================================================================
     */
    private function readZip(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Không đọc được fixture ZIP.');
        }
        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (! str_ends_with($name, '/')) {
                    $bytes = $zip->getFromIndex($index);
                    if ($bytes === false) {
                        throw new RuntimeException('Không đọc được entry fixture.');
                    }
                    $entries[$name] = $bytes;
                }
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc expected JSON đã đóng băng
     * =====================================================================
     * INPUT: Relative path cố định dưới tests/Fixtures/ResourceTemplate.
     * OUTPUT: Array JSON với types được giữ ở mức semantic test.
     * SIDE EFFECT: Chỉ đọc file, không rebuild expected.
     * EXCEPTION/TRANSACTION: JSON exception nếu baseline hỏng; không DB/transaction.
     * =====================================================================
     */
    private function fixtureJson(string $relative): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ResourceTemplate/'.$relative)), true, 16, JSON_THROW_ON_ERROR);
    }
}
